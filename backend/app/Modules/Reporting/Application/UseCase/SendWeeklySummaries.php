<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\OpenIncidentCount;
use App\Modules\Reporting\Application\Port\WeeklySummaryDeliveries;
use App\Modules\Reporting\Application\Port\WeeklySummaryMailer;
use App\Modules\Reporting\Application\Port\WeeklySummaryMetrics;
use App\Modules\Reporting\Application\Port\WeeklySummaryRecipient;
use App\Modules\Reporting\Application\Port\WeeklySummaryRecipients;
use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Application\Support\ComposedPeriodReport;
use App\Modules\Reporting\Application\Support\ReportDataset;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Application\Support\WeeklySummaryReason;
use App\Modules\Reporting\Domain\Exception\InvalidIsoWeek;
use App\Modules\Reporting\Domain\Exception\WeeklySummaryNotDelivered;
use App\Modules\Reporting\Domain\ValueObject\IsoWeek;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Reporting\Domain\ValueObject\WeeklySummary;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Application\Port\WeeklySummaryPreference;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * El resumen semanal por correo al responsable de cada departamento
 * (**RF-PR-05**, tarea 3.12).
 *
 * ## Que manda y que no
 *
 * Manda **la semana ISO pasada** —lunes a domingo, en el calendario civil del
 * centro—, un correo por responsable, **con el alcance de su cuenta aplicado en
 * la consulta**. Nada se calcula aqui: el contenido es el informe por periodo de
 * RF-IN-01 con `grouping = employee` y `granularity = range`, que ya sale de
 * `daily_totals` (regla dura 7) y ya cuenta ausencias y festivos (RF-GP-04).
 *
 * Lo que esta clase decide son cuatro cosas, y ninguna es una regla de negocio:
 * a quien se le manda, de que semana, que no se mande dos veces y que el envio
 * deje constancia.
 *
 * ## El orden de las comprobaciones, de la mas barata a la mas cara
 *
 * | Comprobacion | Si no se cumple |
 * |---|---|
 * | El ajuste `WEEKLY_SUMMARY_EMAIL` | `disabled`, y ni se consulta la licencia |
 * | `MAIL_MAILER` distinto de `log` y `array` | `mailer_silent` |
 * | `Feature::WeeklyEmailSummary` en el plan | `not_in_plan` |
 * | Algun responsable activo con direccion | `no_recipients` |
 *
 * **Ninguna de las cuatro es un error.** Una instalacion sin salida a internet,
 * sin la funcionalidad contratada o con el correo apagado es una instalacion
 * normal (doc 02 §11.6.2, doc 05 §5.7 «correo opcional»): la pasada sale con
 * exito, no envia nada y deja escrito por que. Convertirlo en un fallo pondria
 * en rojo el planificador de un cliente que no ha hecho nada mal.
 *
 * Y **la licencia se comprueba al enviar, nunca al registrar** (regla dura 15,
 * ADR-019): sin `weekly_email_summary` se deja de mandar un correo de gestion, y
 * el registro horario, la bandeja y la exportacion para la Inspeccion siguen
 * exactamente igual.
 *
 * ## NINGUNA E/S DE RED BAJO EL CANDADO DE LA CADENA DE AUDITORIA
 *
 * Es la regla que ordena este caso de uso, y nacio de un fallo real de diseño
 * (decision 13 de la ficha). Cada asiento de `audit_log` toma
 * `pg_advisory_xact_lock` sobre la cadena de hash (ADR-010) y **lo suelta con el
 * commit**. Con el envio dentro de la transaccion del asiento —como estaba—, una
 * pasada del resumen semanal retenia el candado por el que pasa **cada fichaje**
 * durante una conversacion SMTP completa, por destinatario, un lunes a las 06:00
 * UTC: las 08:00 locales en verano, la entrada del turno de mañana. Con un
 * relevo caido, hasta la espera del socket.
 *
 * El orden, por destinatario, es entonces:
 *
 * 1. **Componer** el informe, sin transaccion y **sin escribir el asiento**:
 *    `GeneratePeriodReport::compose()` lo devuelve calculado.
 * 2. **Reclamar** la semana, en una transaccion de una sentencia. El
 *    `UNIQUE (manager_user_id, week_start)` cierra la carrera de dos pasadas
 *    simultaneas; quien la pierde no envia y se cuenta como omitido.
 * 3. **Enviar**, fuera de toda transaccion.
 * 4. **Dejar constancia** en otra transaccion de una sentencia —milisegundos
 *    bajo el candado, como el resto del producto— o **retirar la reclamacion**
 *    si no salio.
 *
 * Por destinatario y no una pasada entera: un SMTP que rechaza un correo no
 * puede deshacer los nueve que ya salieron.
 *
 * ## La constancia (RS-05, regla dura 6)
 *
 * Lleva el conjunto `weekly_summary` y el contexto que identifica **a quien** se
 * le mandaron los datos: `manager_user_id` y `week_start`, ademas de la lista de
 * `employee_uuid` cuando el alcance tiene 50 personas o menos. Un asiento y no
 * dos: componer el informe para mandarlo por correo y mandarlo son el mismo
 * acto, y separarlos obligaria a quien lee el trail a emparejar dos entradas
 * para contestar una pregunta.
 *
 * **Si el correo no sale, no hay asiento**, y es deliberado: el asiento describe
 * una divulgacion consumada, y anotar un intento fallido inflaria el alcance de
 * cualquier brecha con datos que no salieron de la instalacion (RL-15). Del
 * intento queda el log tecnico.
 *
 * Nunca un nombre (regla dura 21). El **correo** si los lleva, y el asiento es
 * exactamente la constancia de que salieron.
 *
 * ## `now()` no aparece
 *
 * El instante entra por el puerto `Clock` (regla dura 2) y la zona sale del
 * centro (ADR-040). Sin lo primero, la prueba de «la semana pasada» dependeria
 * del dia en que se ejecute la suite; sin lo segundo, una pasada de madrugada en
 * UTC resumiria unas veces una semana y otras la anterior.
 */
final readonly class SendWeeklySummaries
{
    /**
     * Los transportes que **no** son un envio de verdad.
     *
     * `log` escribe el correo en `laravel.log` y `array` lo guarda en memoria
     * para las pruebas. Con cualquiera de los dos, escribir la fila de la semana
     * diria «ya se envio» sobre un correo que no salio, y la semana siguiente
     * nadie lo reintentaria.
     *
     * @var list<string>
     */
    private const array SILENT_MAILERS = ['log', 'array'];

    public function __construct(
        private WeeklySummaryRecipients $recipients,
        private WeeklySummaryDeliveries $deliveries,
        private WeeklySummaryMailer $mailer,
        private GeneratePeriodReport $reports,
        private OpenIncidentCount $incidents,
        /**
         * El asiento de la divulgacion (RS-05).
         *
         * Lo escribe **este** caso de uso y no `GeneratePeriodReport`, al
         * contrario que los otros informes, y esa es la diferencia que la
         * decision 13 introduce: aqui el asiento tiene que esperar a que el
         * correo salga, y la transaccion que lo escribe no puede ser la misma
         * que habla con el SMTP.
         */
        private PersonalDataAccessLog $disclosures,
        private WeeklySummaryMetrics $metrics,
        private FeatureGate $features,
        private WeeklySummaryPreference $preference,
        private InstallationSiteProvider $installation,
        private Clock $clock,
        private ConnectionInterface $connection,
        /**
         * `MAIL_MAILER` ya resuelto (regla dura 14): un caso de uso no lee
         * configuracion. Mismo criterio que `MailReportExportNotifier` —nombrado
         * en prosa y no con una referencia, porque Pint la resolveria a un `use`
         * de `Infrastructure` desde `Application` y esa es una arista que
         * Deptrac rechaza—, que recibe la misma cadena por la misma razon.
         */
        private string $mailerTransport,
    ) {}

    /**
     * @param  string|null  $week  `AAAA-Www` para reenviar una semana concreta a mano.
     *                             Nulo es «la semana pasada en la zona del centro».
     *
     * @throws InvalidIsoWeek si `--week` no es una semana real
     * @throws InstallationSiteMissing antes de la puesta en marcha (RF-PD-03)
     */
    public function handle(?string $week = null): WeeklySummaryPass
    {
        $now = $this->clock->now();

        // LA SEMANA PEDIDA SE VALIDA LO PRIMERO, antes que la configuracion.
        // `--week=2026-W99` lo escribio una persona y lo util es decirselo, sea
        // cual sea el estado de la instalacion: con la validacion mas abajo, un
        // hotel con el resumen apagado respondia «desactivado» a una semana que
        // ademas no existe, y quien lo escribio corregia lo que no era.
        $requested = $week === null ? null : IsoWeek::fromLabel($week);

        $blocker = $this->blocker();

        if ($blocker instanceof WeeklySummaryReason) {
            return $this->nothingToDo($blocker, $now);
        }

        $recipients = $this->recipients->active();

        if ($recipients === []) {
            return $this->nothingToDo(WeeklySummaryReason::NoRecipients, $now);
        }

        $site = $this->installation->installationSite();

        if (! $site instanceof InstallationSite) {
            // Con responsables dados de alta y sin centro, la instalacion esta a
            // medio poner en marcha: no hay zona con la que saber que semana fue
            // la pasada. Es un estado de la instalacion y no un descuido de esta
            // pasada, asi que sube y el planificador lo registra.
            throw new InstallationSiteMissing;
        }

        // Sin `--week`, la semana ISO anterior **en el calendario del centro**:
        // la pasada corre en UTC y a esa hora el dia civil del hotel puede ser
        // otro (ADR-040).
        $summarised = $requested ?? IsoWeek::containing(
            $now->setTimezone(new DateTimeZone($site->timezone)),
        )->previous();

        $pass = $this->deliverAll($recipients, $summarised, $now);

        $this->metrics->passCompleted($pass->sent, $now);

        return $pass;
    }

    /**
     * Recorre a los destinatarios y devuelve los recuentos de la pasada.
     *
     * **Un destinatario que falla no arrastra a los demas**, y eso vale para
     * CUALQUIER fallo suyo: el correo que no sale, un informe que agota el
     * `statement_timeout`, una fila corrupta. Cada uno deja su cuenta y su clase
     * de excepcion —nunca el mensaje, que puede llevar dentro el valor de una
     * fila— y la pasada sigue con el siguiente. Lo que no puede pasar es que el
     * noveno responsable se quede sin resumen porque el tercero tenia un
     * problema.
     *
     * Lo que si tumba la pasada entera es un fallo **de la pasada**: la
     * configuracion que no se puede leer, la lista de destinatarios que no
     * responde. Eso no es de nadie en particular y no se disfraza de recuento.
     *
     * @param  list<WeeklySummaryRecipient>  $recipients
     */
    private function deliverAll(array $recipients, IsoWeek $week, DateTimeImmutable $now): WeeklySummaryPass
    {
        $sent = 0;
        $skipped = 0;

        /** @var list<array{manager_user_id: int, exception: string}> $failed */
        $failed = [];

        foreach ($recipients as $recipient) {
            // Un responsable al que todavia no se le ha asignado ningun
            // departamento no alcanza a nadie (RF-ID-03): no hay nada que
            // resumir y, sobre todo, no hay nada que divulgar. Se cuenta y se
            // pasa, sin componer informe y sin escribir asiento.
            //
            // Y quien ya tiene el de esta semana tampoco vuelve a recibirlo: lo
            // decide de verdad el `UNIQUE` de la tabla, pero preguntarlo antes
            // ahorra componer un informe que no se va a usar.
            if ($recipient->scope->reachesNobody() || $this->deliveries->wasSent($recipient->userId, $week->isoStart())) {
                $skipped++;

                continue;
            }

            try {
                $this->deliver($recipient, $week, $now)
                    ? $sent++
                    // Otra pasada se llevo la semana entre la comprobacion de
                    // arriba y la reclamacion. El correo salio igual —lo mando
                    // ella— asi que esto es una omision, no un fallo.
                    : $skipped++;
            } catch (Throwable $failure) {
                // CUALQUIER fallo de UN destinatario se queda en el: el correo
                // que no sale, un informe que se pasa del `statement_timeout`,
                // una fila corrupta. Lo que no puede pasar es que el noveno
                // responsable se quede sin resumen porque el tercero tenia un
                // problema. Ya no queda ni fila ni asiento de este: `deliver()`
                // retira su reclamacion antes de propagar.
                $failed[] = [
                    'manager_user_id' => $recipient->userId,
                    // Solo la CLASE de la excepcion: su mensaje puede llevar
                    // dentro el valor de una fila (regla dura 21) y este
                    // recuento acaba en el log del planificador, que viaja al
                    // fabricante en el paquete de diagnostico.
                    'exception' => $failure::class,
                ];
            }
        }

        return new WeeklySummaryPass(
            reason: WeeklySummaryReason::Sent,
            week: $week->label(),
            recipients: \count($recipients),
            sent: $sent,
            skipped: $skipped,
            failures: $failed,
        );
    }

    /**
     * Lo que impide enviar por como esta configurada la instalacion, o `null`.
     *
     * Las tres comprobaciones en un sitio y **en orden de coste**: el ajuste es
     * una lectura de configuracion; el transporte, una cadena ya resuelta; y la
     * licencia, una verificacion criptografica. Con el resumen apagado —que es
     * como se entrega— no se hace ninguna de las dos siguientes, ni se consulta
     * la lista de destinatarios.
     *
     * **Ninguna de las tres es un error.** Ver el docblock de la clase y
     * {@see WeeklySummaryReason}.
     */
    private function blocker(): ?WeeklySummaryReason
    {
        if (! $this->preference->weeklySummaryEnabled()) {
            return WeeklySummaryReason::Disabled;
        }

        if (\in_array($this->mailerTransport, self::SILENT_MAILERS, true)) {
            return WeeklySummaryReason::MailerSilent;
        }

        // La licencia se comprueba AL ENVIAR y nunca al registrar (regla dura
        // 15, ADR-019): lo que se degrada es un correo de gestion.
        return $this->features->statusOf(Feature::WeeklyEmailSummary)->enabled
            ? null
            : WeeklySummaryReason::NotInPlan;
    }

    /**
     * Compone, reclama la semana, manda y **solo despues** deja constancia.
     *
     * ## El orden, que es lo que esta decision arregla
     *
     * 1. **Componer**, fuera de cualquier transaccion. Es la parte cara —una
     *    consulta sobre el registro horario de una semana— y no escribe nada.
     *    El asiento sale calculado y **sin escribir** ({@see ComposedPeriodReport}).
     * 2. **Reclamar** la semana en una transaccion de una sentencia. El `UNIQUE`
     *    cierra la carrera de dos pasadas simultaneas; quien la pierde recibe
     *    `false` y no envia.
     * 3. **Enviar**, fuera de toda transaccion. Aqui es donde se habla con un
     *    servidor que puede tardar un minuto en contestar.
     * 4. **Dejar constancia**, en otra transaccion de una sentencia, o **retirar
     *    la reclamacion** si el correo no salio.
     *
     * Antes los cuatro pasos vivian dentro de una sola transaccion, y esa
     * transaccion era la del asiento: tomaba el candado de la cadena de
     * auditoria —el mismo por el que pasa **cada fichaje** (ADR-010)— y no lo
     * soltaba hasta el commit, es decir, hasta que terminara la conversacion
     * SMTP. Un lunes a las 06:00 UTC, que en verano son las 08:00 locales,
     * con el turno de mañana entrando. Con un relevo caido, hasta la espera del
     * socket por destinatario.
     *
     * ## Un fallo de correo no deja asiento, y es deliberado
     *
     * El asiento de `personal_data.accessed` describe una divulgacion
     * **consumada**. Escribirlo ante un intento fallido inflaria el alcance de
     * cualquier brecha con datos que no salieron de la instalacion (RL-15). Del
     * intento queda el log tecnico del adaptador del correo.
     *
     * @return bool `true` si se envio; `false` si otra pasada se llevo la semana.
     *
     * @throws WeeklySummaryNotDelivered si el correo no salio
     */
    private function deliver(WeeklySummaryRecipient $recipient, IsoWeek $week, DateTimeImmutable $now): bool
    {
        $composed = $this->reports->compose(
            new PeriodReportQuery(
                scope: $recipient->scope,
                range: $week->toRange(),
                // Una linea por persona con el total de la semana entera:
                // `range` y no `day`, porque el correo resume y el detalle
                // dia a dia esta en el panel, al que remite el enlace.
                granularity: ReportGranularity::Range,
                grouping: ReportGrouping::Employee,
                departmentId: null,
                employeeUuid: null,
                // Un turno sin cerrar vale cero en la proyeccion, asi que
                // incluirlo daria una cifra a medias justo en la comparacion
                // contra lo contratado. El dia cuenta igual como dia con
                // actividad.
                includeOpenShifts: false,
            ),
            // Los dos techos sincronos no aplican: esto no responde a nadie
            // por HTTP. Mismo criterio que la generacion en diferido, con cuyo
            // lector —`statement_timeout` de 600 s— se compone este caso de uso.
            maxRangeDays: PHP_INT_MAX,
            maxRows: PHP_INT_MAX,
            delivery: ReportDelivery::Mail,
            dataset: ReportDataset::WeeklySummary,
            // A QUIEN se le mandaron y DE QUE semana. Sin estas dos claves el
            // asiento no distinguiria un resumen del correo de Cocina de otro
            // del de Recepcion, que es la pregunta que RL-15 obliga a
            // contestar. Identificadores, nunca nombres (regla dura 21).
            disclosureContext: [
                'manager_user_id' => $recipient->userId,
                'week_start' => $week->isoStart(),
            ],
        );

        $summary = new WeeklySummary($week, $composed->report, $this->incidents->inScope($recipient->scope));

        // La reclamacion, ANTES de enviar: es lo unico que impide que dos
        // pasadas simultaneas manden el mismo correo dos veces.
        $claimed = $this->connection->transaction(fn (): bool => $this->deliveries->claim(
            $recipient->userId,
            $week->isoStart(),
            $now,
            $summary->employeeCount(),
            $composed->report->rowCount(),
        ));

        if (! $claimed) {
            return false;
        }

        try {
            if (! $this->mailer->send($recipient, $summary)) {
                throw WeeklySummaryNotDelivered::toManager($recipient->userId, $week->label());
            }

            // La divulgacion ya ocurrio: ahora si, y en milisegundos bajo el
            // candado de la cadena, como el resto del producto.
            $this->connection->transaction(function () use ($composed): void {
                $this->disclosures->recordDisclosure(
                    $composed->dataset->value,
                    $composed->recordCount(),
                    $composed->disclosure,
                );
            });
        } catch (Throwable $failure) {
            // Se retira la reclamacion para que la semana siga pendiente. Si lo
            // que fallo fue el ASIENTO y no el correo, retirarla es igualmente
            // lo correcto: la pasada siguiente vuelve a enviar y esa vez si deja
            // constancia, que es lo que la regla dura 6 exige. Un correo de mas
            // es molesto; una divulgacion sin asiento, no.
            $this->connection->transaction(function () use ($recipient, $week): void {
                $this->deliveries->release($recipient->userId, $week->isoStart());
            });

            throw $failure;
        }

        return true;
    }

    /**
     * Una pasada que termina bien sin enviar nada.
     *
     * Publica la metrica igualmente, y eso es lo que la hace util: sin la serie,
     * «no habia nada que enviar» y «el planificador dejo de correr» se leerian
     * exactamente igual.
     */
    private function nothingToDo(WeeklySummaryReason $reason, DateTimeImmutable $now): WeeklySummaryPass
    {
        $this->metrics->passCompleted(0, $now);

        return new WeeklySummaryPass($reason, '', 0, 0, 0);
    }
}
