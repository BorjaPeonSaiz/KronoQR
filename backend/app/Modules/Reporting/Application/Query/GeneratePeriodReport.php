<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Query;

use App\Modules\Reporting\Application\Port\ComplianceProfileReference;
use App\Modules\Reporting\Application\Port\PeriodReportReader;
use App\Modules\Reporting\Application\Support\ComposedPeriodReport;
use App\Modules\Reporting\Application\Support\ReportDataset;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\ValueObject\ComplianceProfileRef;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\ReportCriterion;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;

/**
 * El informe de horas por periodo (**RF-IN-01**, RF-IN-02, RF-IN-03).
 *
 * ## Que decide esta clase y que no
 *
 * Decide **cinco** cosas, y ninguna es una regla de negocio:
 *
 *   1. Que el informe se entrega en el acto o no se entrega (RNF-P-05).
 *   2. Que los criterios de inclusion salen **con** el resultado y no en un
 *      manual.
 *   3. Que generarlo deja constancia en `audit_log` cuando lleva datos de
 *      terceros.
 *   4. Que el instante y la zona los pone el servidor, no el cliente.
 *   5. Que el calendario de festivos del centro lo **resuelve el servidor** y no
 *      lo elige quien pregunta (RF-GP-04, regla dura 14).
 *
 * ## Los festivos se resuelven aqui, y por eso el dominio no los busca
 *
 * `CompliancePolicyProvider` entrega el perfil del centro con su calendario ya
 * normalizado, y esta clase lo mete en la consulta con `withHolidays()`. Es la
 * aplicacion literal de la regla dura 14 —el dominio recibe el umbral resuelto,
 * nunca lo consulta— y lo que hace que `holiday_calendar` estrene consumidor:
 * desde RF-GP-04, un festivo del perfil deja de contar como absentismo.
 *
 * El **nombre** del perfil llega por otro puerto, `ComplianceProfileReference`,
 * por lo mismo que en la vista de cumplimiento: `CompliancePolicy` son umbrales
 * y no lleva nombre a proposito, para que ninguna regla pueda escribir «si el
 * perfil es ES-hosteleria, entonces…» (ADR-017). Aqui el nombre no decide nada:
 * solo aparece en la linea de criterios que explica de donde salen los festivos.
 *
 * El calculo de los totales no se decide aqui —lo define RN-06 y lo tiene
 * `daily_totals` (regla dura 7)—, el prorrateo de lo contratado lo define
 * `EmploymentContract` (RF-IN-03) y la autorizacion es de la policy y del
 * alcance que entra en la consulta.
 *
 * ## El presupuesto se comprueba antes, en dos pasos
 *
 * Primero el rango, que es gratis; despues las filas que produciria, que cuesta
 * un `COUNT` sobre `employees`. Los dos se saben **sin** ejecutar el informe. El
 * tercer limite —el `statement_timeout`— es el unico que se descubre
 * ejecutando, y lo aplica el adaptador en PostgreSQL. Los tres desembocan en la
 * misma respuesta: `422` que remite a la generacion en diferido de RF-IN-06
 * (tarea 3.9).
 *
 * ## La constancia
 *
 * RS-05 no admite matices, y aqui salen horas de trabajo de personas
 * identificadas: el dato personal mas sensible que este producto guarda de
 * nadie. Se registra **el alcance** —cuantas filas, que periodo, con que
 * granularidad, con que ambito— y, cuando el informe es por empleado, **la lista
 * de `employee_uuid`**: es un informe que se pide para llevarselo, y sin esa
 * lista el asiento no responde a la pregunta que RL-15 obliga a contestar. Nunca
 * un nombre y nunca una hora (regla dura 21).
 *
 * Con agrupacion por departamento o por centro no hay lista, y es correcto: ahi
 * no se ha divulgado el dato de nadie en particular. El asiento sigue
 * escribiendose, porque un agregado de departamento tambien es informacion sobre
 * su gente.
 *
 * **Se escribe antes de devolver**: si la escritura de auditoria falla, la
 * divulgacion no ocurre (regla dura 6, ADR-027). Y **no se agrupa**, al
 * contrario que la del panel de presencia: aquella se sondea cada quince
 * segundos y esta la pide una persona pulsando un boton unas cuantas veces al
 * mes.
 *
 * ## `now()` no aparece
 *
 * El instante entra por el puerto `Clock` (regla dura 2) y la zona sale del
 * centro de la instalacion (ADR-040). Sin lo primero, la prueba del informe
 * dependeria del dia en que se ejecute la suite.
 */
final readonly class GeneratePeriodReport
{
    /**
     * Hasta cuantos afectados se enumeran en el asiento. Ver
     * {@see self::affectedSubjects()}.
     */
    private const int MAX_ENUMERATED_SUBJECTS = 50;

    /**
     * Claves de los criterios de inclusion, en el orden en el que se leen.
     *
     * Son **claves**, no texto: la traduccion es de la capa de presentacion, que
     * es la que sabe en que idioma esta hablando. Estan aqui y no en el
     * `Resource` porque forman parte de lo que el informe **es**: la tarea 2.9
     * los escribira en la cabecera del CSV y del PDF desde este mismo objeto, y
     * si vivieran en el serializador de JSON habria dos listas que mantener.
     *
     * @var list<string>
     */
    private const array BASE_CRITERIA = [
        'criteria.source',
        'criteria.work_date',
        'criteria.voided',
        'criteria.incidents',
        'criteria.empty_days',
        'criteria.contracted',
        'criteria.scope',
    ];

    public function __construct(
        private PeriodReportReader $reader,
        private InstallationSiteProvider $installation,
        private Clock $clock,
        private PersonalDataAccessLog $disclosures,
        /** El calendario de festivos del centro, ya resuelto (RF-GP-04, regla dura 14). */
        private CompliancePolicyProvider $policies,
        /** Solo para **nombrar** el perfil en los criterios. No decide nada. */
        private ComplianceProfileReference $profiles,
    ) {}

    /**
     * @param  int  $maxRangeDays  Techo del rango sincrono, de `config/reporting.php`.
     * @param  int  $maxRows  Techo de filas del resultado, de `config/reporting.php`.
     * @param  ReportDelivery  $delivery  Como sale el informe de la instalacion. Solo afecta al
     *                                    asiento de `audit_log`: el contenido es el mismo para los
     *                                    cuatro. Ver {@see ReportDelivery} y {@see recordDisclosure()}.
     * @param  ReportDataset  $dataset  Que conjunto de datos personales se divulga. Tampoco cambia el
     *                                  contenido: separa en el trail «miro el cuadro de horas» de «se
     *                                  llevo el fichero para el programa de nomina» (RF-IN-07, RS-05).
     *                                  Ver {@see ReportDataset}.
     * @param  array<string, scalar>  $disclosureContext  Lo que este informe tiene de particular y el
     *                                                    asiento canonico no sabe decir. Hoy solo lo usa
     *                                                    el resumen semanal (RF-PR-05), que es el unico
     *                                                    informe sin nadie delante: sin `manager_user_id`
     *                                                    ni `week_start`, su asiento no respondería **a
     *                                                    quien** se le fueron los datos, que es justo la
     *                                                    pregunta que RL-15 obliga a contestar. **No puede
     *                                                    pisar ninguna clave canonica** ni llevar datos
     *                                                    personales (regla dura 21). Ver
     *                                                    {@see recordDisclosure()}.
     *
     * @throws InstallationSiteMissing antes de la puesta en marcha, cuando no hay centro
     *                                 del que tomar la zona horaria (RF-PD-03)
     * @throws ReportTooLargeForSynchronousDelivery cuando se sale del presupuesto de RNF-P-05
     */
    public function handle(
        PeriodReportQuery $query,
        int $maxRangeDays,
        int $maxRows,
        ReportDelivery $delivery = ReportDelivery::Json,
        ReportDataset $dataset = ReportDataset::PeriodReport,
        array $disclosureContext = [],
    ): PeriodReport {
        $composed = $this->compose($query, $maxRangeDays, $maxRows, $delivery, $dataset, $disclosureContext);

        $this->disclosures->recordDisclosure(
            $composed->dataset->value,
            $composed->recordCount(),
            $composed->disclosure,
        );

        return $composed->report;
    }

    /**
     * El mismo informe, **con el asiento calculado y sin escribir** (decision 13
     * de la ficha 3.12).
     *
     * ## Un solo llamante, y por un motivo muy concreto
     *
     * `SendWeeklySummaries`. Aquel compone el informe, se lo manda por correo al
     * responsable y **solo entonces** deja constancia. Si el asiento se
     * escribiera aqui, quedaria dentro de la misma transaccion que despues
     * habla con el SMTP del cliente, y esa transaccion retiene el candado de la
     * cadena de auditoria —el mismo por el que pasa cada fichaje (ADR-010)—
     * hasta el commit. Ver {@see ComposedPeriodReport}, donde esta el argumento
     * entero.
     *
     * **No es un modo «sin auditoria»**: quien llama recibe el contexto ya
     * resuelto y esta obligado a registrarlo en cuanto la divulgacion se
     * consuma. El contenido del asiento es exactamente el mismo que escribe
     * {@see self::handle()}, y eso es lo que hace que los dos caminos no puedan
     * contar historias distintas.
     *
     * @param  array<string, scalar>  $disclosureContext
     *
     * @throws InstallationSiteMissing
     * @throws ReportTooLargeForSynchronousDelivery
     */
    public function compose(
        PeriodReportQuery $query,
        int $maxRangeDays,
        int $maxRows,
        ReportDelivery $delivery = ReportDelivery::Json,
        ReportDataset $dataset = ReportDataset::PeriodReport,
        array $disclosureContext = [],
    ): ComposedPeriodReport {
        $site = $this->installation->installationSite();

        if ($site === null) {
            // Sin centro no hay zona, y sin zona el informe obligaria al cliente
            // a adivinarla. Es un estado de la instalacion, no un error de quien
            // pregunta: `409` (ver bootstrap/app.php).
            throw new InstallationSiteMissing;
        }

        $this->assertFitsInASynchronousResponse($query, $maxRangeDays, $maxRows);

        // RF-GP-04. El calendario entra **antes** de consultar, no despues: lo
        // necesita el propio `SELECT` para no contar un festivo como absentismo.
        $query = $query->withHolidays($this->policies->forSite($site->id)->holidayCalendar);

        $rows = $this->reader->rows($query, $site->name);
        $coverage = $this->reader->contractCoverage($query);

        $report = new PeriodReport(
            rows: $rows,
            range: $query->range,
            granularity: $query->granularity,
            grouping: $query->grouping,
            timeZone: $site->timezone,
            generatedAt: $this->clock->now(),
            criteria: $this->criteriaFor($query, $site->id),
            contractCoverage: $coverage,
        );

        return new ComposedPeriodReport(
            $report,
            $dataset,
            $this->disclosureContextFor($query, $report, $delivery, $disclosureContext),
        );
    }

    /**
     * @throws ReportTooLargeForSynchronousDelivery
     */
    private function assertFitsInASynchronousResponse(PeriodReportQuery $query, int $maxRangeDays, int $maxRows): void
    {
        $days = $query->range->days();

        if ($days > $maxRangeDays) {
            throw ReportTooLargeForSynchronousDelivery::rangeTooWide($days, $maxRangeDays);
        }

        $estimated = $this->reader->estimateRows($query);

        if ($estimated > $maxRows) {
            throw ReportTooLargeForSynchronousDelivery::tooManyRows($estimated, $maxRows);
        }
    }

    /**
     * Los criterios comunes mas los que dependen de lo que se ha pedido.
     *
     * El de los turnos abiertos cambia de texto segun `include_open_shifts`, y
     * tiene que cambiarlo: un informe que dijera siempre lo mismo sobre ellos
     * mentiria en uno de los dos casos.
     *
     * **Las tres lineas de RF-GP-04 van juntas y en este orden** —que las
     * ausencias justifican, cuantos festivos hay y que el producto no conoce el
     * cuadrante— porque se leen como un solo parrafo: las dos primeras dicen que
     * se ha descontado y la tercera, que es lo que sigue sin descontarse. Sacar
     * la tercera de ahi convertiria `unjustified_absence_days` en un numero que
     * parece medir lo que no mide.
     *
     * @return list<ReportCriterion>
     */
    private function criteriaFor(PeriodReportQuery $query, int $siteId): array
    {
        $criteria = ReportCriterion::listOf(self::BASE_CRITERIA);

        $criteria[] = new ReportCriterion($query->includeOpenShifts
            ? 'criteria.open_shifts_included'
            : 'criteria.open_shifts_excluded');

        if ($query->granularity === ReportGranularity::Week) {
            $criteria[] = new ReportCriterion('criteria.iso_week');
        }

        $criteria[] = new ReportCriterion('criteria.absences');

        $profile = $this->profiles->forSite($siteId);

        if ($profile instanceof ComplianceProfileRef) {
            // Con el recuento del periodo y el nombre del perfil, que es lo que
            // permite ir a mirarlo: «cero festivos» sobre un calendario que nadie
            // cargo y «cero festivos» sobre marzo son la misma cifra con dos
            // causas distintas, y saber de que perfil se habla es la mitad de la
            // respuesta. Sin perfil resoluble no hay festivos que contar ni
            // nombre que citar, y la linea se omite en vez de decir «del perfil
            // (ninguno)».
            $criteria[] = new ReportCriterion('criteria.holidays', [
                'count' => (string) \count($query->holidaysInRange()),
                'profile' => $profile->name,
            ]);
        }

        $criteria[] = new ReportCriterion('criteria.no_roster');

        return $criteria;
    }

    /**
     * El contexto del asiento de `personal_data.accessed`, **calculado en un
     * solo sitio** para los dos caminos: el que lo escribe en el acto
     * ({@see self::handle()}) y el que lo escribe despues de entregar
     * ({@see self::compose()}).
     *
     * @param  array<string, scalar>  $extra
     * @return array<string, scalar>
     */
    private function disclosureContextFor(
        PeriodReportQuery $query,
        PeriodReport $report,
        ReportDelivery $delivery,
        array $extra,
    ): array {
        $uuids = $report->employeeUuids();

        // Lo particular va PRIMERO y lo canonico despues, para que quien pase un
        // contexto extra no pueda cambiar lo que el asiento dice del alcance
        // —`scope`, `employees`, el periodo— ni por descuido ni a proposito. El
        // asiento de una divulgacion no lo redacta quien divulga.
        return [
            ...$extra,
            // EN QUE se lo llevaron (RF-IN-04). Un asiento por divulgacion y no
            // dos: la descarga y la consulta son el mismo acceso a los mismos
            // datos, y separarlas obligaria a quien lee el trail a emparejar dos
            // entradas para contestar una sola pregunta. Lo que cambia es la
            // consecuencia —un XLSX se reenvia por correo, una tabla en pantalla
            // no— y eso es justo lo que este campo distingue.
            'format' => $delivery->value,
            'from' => $query->range->isoFrom(),
            'to' => $query->range->isoTo(),
            'granularity' => $query->granularity->value,
            'group_by' => $query->grouping->value,
            ...($query->departmentId === null ? [] : ['department_id' => $query->departmentId]),
            // El alcance con el que se sirvio (RF-ID-03): distingue «RRHH saco el
            // hotel entero» de «un responsable saco su cocina», que ante una
            // brecha (RL-15) no es lo mismo.
            'scope' => $query->scope->isUnrestricted() ? 'all' : 'departments',
            // Cuantas personas salieron en el informe, siempre. Es lo que
            // convierte «alguien saco un informe» en «alguien se llevo las horas
            // de la plantilla entera».
            'employees' => \count($uuids),
            ...$this->affectedSubjects($uuids),
        ];
    }

    /**
     * La lista de afectados, **solo cuando el conjunto es pequeño**.
     *
     * El puerto {@see PersonalDataAccessLog} fija el criterio y aqui se aplica
     * literalmente: enumerar esta descartado para los conjuntos grandes —el
     * trail acabaria siendo una segunda copia de la plantilla, con cuatro años
     * de retencion— y es obligatorio cuando el conjunto es pequeño y los datos
     * **salen** de la instalacion, que es exactamente lo que hace un informe que
     * alguien pide para llevarselo.
     *
     * El corte no es arbitrario: por debajo de {@see self::MAX_ENUMERATED_SUBJECTS}
     * el informe es de una persona o de un equipo concreto, y saber de quien
     * eran las horas es la pregunta que RL-15 obliga a contestar. Por encima, el
     * recuento y el alcance ya la contestan mejor que una lista de quinientos
     * identificadores.
     *
     * Identificadores, nunca nombres (regla dura 21).
     *
     * @param  list<string>  $uuids
     * @return array<string, string>
     */
    private function affectedSubjects(array $uuids): array
    {
        if ($uuids === [] || \count($uuids) > self::MAX_ENUMERATED_SUBJECTS) {
            return [];
        }

        return ['employee_uuids' => implode(',', $uuids)];
    }
}
