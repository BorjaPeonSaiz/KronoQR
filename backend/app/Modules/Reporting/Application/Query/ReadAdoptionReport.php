<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Query;

use App\Modules\Reporting\Application\Port\AdoptionFactsReader;
use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\Policy\AdoptionIndicators;
use App\Modules\Reporting\Domain\ValueObject\AdoptionFacts;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReportQuery;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use DateTimeImmutable;
use DateTimeZone;

/**
 * El cuadro de impacto y adopcion (**RF-IN-08**, **RNF-D-01**): los indicadores del
 * §1.3 del documento 01 con su comparacion contra el periodo anterior.
 *
 * ## Que decide esta clase, y que no
 *
 * Decide **cuatro** cosas, y ninguna es aritmetica:
 *
 *   1. **Que periodo describe el cuadro** cuando no se pide uno: el mes natural
 *      anterior completo, resuelto en la zona del centro.
 *   2. **Que no se entrega** lo que se sale del presupuesto sincrono (`422`).
 *   3. **De donde salen las horas**: de `GeneratePeriodReport`, no de una SQL
 *      propia (regla dura 7).
 *   4. **Que este cuadro no deja asiento de divulgacion**, y por que.
 *
 * La aritmetica es de {@see AdoptionIndicators}, en el dominio y sin reloj; los
 * recuentos son del puerto; el instante viene de `Clock` (regla dura 2) y la zona
 * del centro de la instalacion (ADR-040, regla dura 3).
 *
 * ## Leer este cuadro NO es un acceso a datos personales
 *
 * Y por eso, al contrario que {@see ReadComplianceSummary} y que
 * {@see GeneratePeriodReport}, aqui **no se llama a `PersonalDataAccessLog`**. Lo
 * que sale son doce agregados de la instalacion entera: ni un `employee_uuid`, ni
 * un nombre, ni un departamento (regla dura 21, decision 5 de la ficha 3.13). Un
 * asiento `personal_data.accessed` por cada apertura de esta pantalla llenaria el
 * trail —que tiene cuatro años de retencion (RL-02)— de filas que no describen
 * ninguna divulgacion, y con ello haria mas dificil encontrar las que si.
 *
 * **Exportarlo si se audita** (`adoption_report.exported`): ahi sale un documento
 * del sistema, y de un documento se responde. Lo escribe el controlador de la
 * descarga publicando un evento de dominio, no esta clase.
 *
 * ## Las horas vienen del informe por periodo, y eso no es reutilizacion por pereza
 *
 * `GeneratePeriodReport::compose()` es quien sabe prorratear lo contratado por dia
 * natural de vigencia (RF-IN-03), descontar festivos y ausencias (RF-GP-04) y no
 * contar dos veces un tramo sustituido por una correccion (regla dura 5). Sumar
 * `daily_totals` aqui daria un segundo total de horas de la instalacion, y el dia
 * que los dos no coincidieran nadie sabria cual creer.
 *
 * Se llama a `compose()` y no a `handle()` **para no escribir asiento** (decision
 * 13 de la ficha 3.12): el cuadro solo usa los totales del informe, y un
 * `personal_data.accessed` por abrir una pantalla de agregados es justo lo que el
 * parrafo anterior descarta. El contexto de divulgacion que `compose()` devuelve se
 * ignora a proposito.
 *
 * ## El presupuesto de base de datos de UNA peticion, escrito
 *
 * Tres consultas con techo propio, y los techos **no se suman en un solo
 * `statement_timeout`** porque cada una abre su transaccion:
 *
 *   - los hechos, con `reporting.adoption.statement_timeout_seconds` (10 s de serie);
 *   - **dos** informes de horas —el periodo y el anterior—, cada uno con
 *     `reporting.period.statement_timeout_seconds` (10 s de serie).
 *
 * En el peor caso son **30 s de base de datos por peticion**, y de ahi que la ruta
 * lleve `throttle:management`: el techo por cuenta es lo que impide que una pestaña
 * en bucle de reintento ocupe treinta segundos de conexion por intento contra la
 * misma base de datos que atiende el fichaje (RNF-P-02, regla dura 19). Medido con
 * volumen real —500 personas y dos años— el conjunto tarda unos 2 s; los 30 son el
 * techo, no la expectativa.
 *
 * ## El alcance es «sin restriccion», y es coherente con la policy
 *
 * `AccessScope::unrestricted()` en las dos consultas de horas. No es un atajo: la
 * policy de este endpoint es `{admin, rrhh}` y **nadie mas** (Anexo B del doc 01),
 * asi que quien llega aqui alcanza a toda la plantilla por definicion. El dia que
 * un responsable pudiera ver el cuadro, esto tendria que cambiar antes que la
 * policy — y de ahi que el argumento este escrito aqui y no solo en la policy.
 */
final readonly class ReadAdoptionReport
{
    public function __construct(
        private AdoptionFactsReader $facts,
        private AdoptionIndicators $indicators,
        /**
         * De donde salen las horas trabajadas y contratadas de los dos periodos.
         * Ver el docblock de la clase: se invoca con `compose()`, sin asiento.
         */
        private GeneratePeriodReport $periodReports,
        private InstallationSiteProvider $installation,
        /** La linea base declarada de `BASELINE_MANUAL_HOURS_PER_MONTH` (§1.3). */
        private OperationalSettingsProvider $settings,
        private Clock $clock,
    ) {}

    /**
     * @param  int  $maxRangeDays  Techo del rango sincrono, de `config/reporting.php`.
     * @param  int  $maxRows  Techo de filas del informe de horas, de `config/reporting.php`.
     *
     * @throws InstallationSiteMissing antes de la puesta en marcha, cuando no hay centro
     *                                 del que tomar la zona horaria (RF-PD-03)
     * @throws ReportTooLargeForSynchronousDelivery cuando el rango pedido supera el techo
     *                                              sincrono de la instalacion (`422`)
     */
    public function handle(AdoptionReportCriteria $criteria, int $maxRangeDays, int $maxRows): AdoptionReport
    {
        $site = $this->installation->installationSite();

        if (! $site instanceof InstallationSite) {
            // Sin centro no hay zona, y sin zona ni «hoy» ni «el mes pasado»
            // significan nada. Es un estado de la instalacion, no un error de quien
            // pregunta: `409` (ver bootstrap/app.php).
            throw new InstallationSiteMissing;
        }

        $now = $this->clock->now();
        $today = $now->setTimezone(new DateTimeZone($site->timezone))->format('Y-m-d');
        $query = $this->resolve($criteria, $now, $site->timezone, $today, $maxRangeDays);

        $facts = $this->withHours(
            $this->facts
                ->factsFor($query, $site->timezone)
                ->withDeclaredBaselineHours($this->settings->forSite($site->id)->baselineManualHoursPerMonth),
            $query,
            $maxRangeDays,
            $maxRows,
        );

        return new AdoptionReport(
            indicators: $this->indicators->evaluate($facts),
            originBreakdown: $this->indicators->originBreakdown($facts),
            range: $query->range,
            previousRange: $query->previousRange,
            timeZone: $site->timezone,
            generatedAt: $now,
            criteria: $this->indicators->criteria(),
        );
    }

    /**
     * El periodo pedido, con la omision ya resuelta **en la zona del centro** y el
     * techo comprobado.
     *
     * ## Tres omisiones y no una
     *
     * - **Sin `from` ni `to`**: el mes natural anterior completo. Es el periodo
     *   cerrado del que se habla en una reunion; el mes en curso daria un cuadro
     *   que cambia cada dia y cuyos porcentajes dependen de la hora a la que se
     *   mire — el mismo criterio por el que `reporting:adoption-metrics` mide
     *   **ayer** y no hoy.
     * - **`from` sin `to`**: hasta hoy.
     * - **`to` sin `from`**: desde el dia 1 de su mes.
     *
     * ## El techo se comprueba con {@see DateRange::spanInDays()} y antes de construir el rango
     *
     * Construyendo el rango primero, un `?from=2020-01-01` moriria en el
     * `InvalidDateRange` de `DateRange::MAXIMUM_DAYS` —un `validation-failed` que no
     * remite a nada— en lugar del `report-too-large` que dice que hay que acortar.
     * Y se comprueba aqui y no en el `FormRequest` porque el rango puede venir a
     * medias y el borde no sabe donde esta el hotel.
     *
     * @throws ReportTooLargeForSynchronousDelivery
     */
    private function resolve(
        AdoptionReportCriteria $criteria,
        DateTimeImmutable $now,
        string $timeZone,
        string $today,
        int $maxRangeDays,
    ): AdoptionReportQuery {
        if ($criteria->from === null && $criteria->to === null) {
            return AdoptionReportQuery::previousMonth($now, $timeZone);
        }

        $to = $criteria->to ?? $today;
        $from = $criteria->from ?? AdoptionReportQuery::startOfMonthOf($to);
        $days = DateRange::spanInDays($from, $to);

        if ($days > $maxRangeDays) {
            throw ReportTooLargeForSynchronousDelivery::adoptionRangeTooWide($days, $maxRangeDays);
        }

        return AdoptionReportQuery::of(DateRange::between($from, $to));
    }

    /**
     * Los hechos con las horas trabajadas y contratadas de los dos periodos.
     *
     * ## Dos informes y no uno
     *
     * Porque el cuadro compara, y una comparacion necesita las horas de los dos
     * lados. Cuesta poco: con `grouping: department` y `granularity: range` cada
     * informe son tantas filas como departamentos tenga el hotel —una decena—, no
     * una por persona y dia.
     *
     * ## Los techos que se le pasan son los del CUADRO, no los del informe
     *
     * Y es deliberado. El presupuesto sincrono del informe por periodo (92 dias)
     * existe porque aquel cruza la plantilla entera con el calendario y produce una
     * fila por persona y dia; aqui se le pide **una fila por departamento** para
     * todo el rango, asi que 366 dias no lo hacen mas caro que 92 en numero de
     * filas. Pasarle su propio techo haria que el cuadro de «el año pasado
     * completo» respondiera `422` por un motivo que no le aplica.
     */
    private function withHours(
        AdoptionFacts $facts,
        AdoptionReportQuery $query,
        int $maxRangeDays,
        int $maxRows,
    ): AdoptionFacts {
        $current = $this->hoursFor($query->range, $maxRangeDays, $maxRows);
        $previous = $this->hoursFor($query->previousRange, $maxRangeDays, $maxRows);

        return $facts->withWorkedTime($current[0], $current[1], $previous[0], $previous[1]);
    }

    /**
     * Horas trabajadas y contratadas del rango, en minutos.
     *
     * @return array{0: int, 1: int}
     */
    private function hoursFor(DateRange $range, int $maxRangeDays, int $maxRows): array
    {
        $composed = $this->periodReports->compose(
            new PeriodReportQuery(
                // Ver el docblock de la clase: la policy de este cuadro es
                // `{admin, rrhh}`, asi que quien llega aqui alcanza a toda la
                // plantilla por definicion.
                scope: AccessScope::unrestricted(),
                range: $range,
                // Una sola fila por sujeto con el rango entero: el cuadro quiere el
                // total del periodo, no su desglose por dias.
                granularity: ReportGranularity::Range,
                // Por departamento y no por centro para que el informe pase por el
                // mismo camino que la pantalla de informes —el agregado por centro
                // tiene otra consulta— y porque sumar departamentos incluye el cubo
                // de quien no tiene ninguno, que existe justo para que la suma
                // cuadre con el total.
                grouping: ReportGrouping::Department,
                departmentId: null,
                employeeUuid: null,
                // Sin turnos abiertos: un tramo sin cerrar vale cero en la
                // proyeccion, y meterlo daria una cifra a medias justo en la
                // comparacion contra lo contratado.
                includeOpenShifts: false,
            ),
            maxRangeDays: $maxRangeDays,
            maxRows: $maxRows,
        );

        return [$composed->report->workedMinutes(), $composed->report->contractedMinutes()];
    }
}
