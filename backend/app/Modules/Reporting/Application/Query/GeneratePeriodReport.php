<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Query;

use App\Modules\Reporting\Application\Port\PeriodReportReader;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;

/**
 * El informe de horas por periodo (**RF-IN-01**, RF-IN-02, RF-IN-03).
 *
 * ## Que decide esta clase y que no
 *
 * Decide **cuatro** cosas, y ninguna es una regla de negocio:
 *
 *   1. Que el informe se entrega en el acto o no se entrega (RNF-P-05).
 *   2. Que los criterios de inclusion salen **con** el resultado y no en un
 *      manual.
 *   3. Que generarlo deja constancia en `audit_log` cuando lleva datos de
 *      terceros.
 *   4. Que el instante y la zona los pone el servidor, no el cliente.
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
    /** Vocabulario estable del `audit_log`, en ingles y sin datos dentro. */
    private const string DATASET = 'period_report';

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
    ) {}

    /**
     * @param  int  $maxRangeDays  Techo del rango sincrono, de `config/reporting.php`.
     * @param  int  $maxRows  Techo de filas del resultado, de `config/reporting.php`.
     * @param  ReportDelivery  $delivery  Como sale el informe de la instalacion. Solo afecta al
     *                                    asiento de `audit_log`: el contenido es el mismo para los
     *                                    cuatro. Ver {@see ReportDelivery} y {@see recordDisclosure()}.
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
    ): PeriodReport {
        $site = $this->installation->installationSite();

        if ($site === null) {
            // Sin centro no hay zona, y sin zona el informe obligaria al cliente
            // a adivinarla. Es un estado de la instalacion, no un error de quien
            // pregunta: `409` (ver bootstrap/app.php).
            throw new InstallationSiteMissing;
        }

        $this->assertFitsInASynchronousResponse($query, $maxRangeDays, $maxRows);

        $rows = $this->reader->rows($query, $site->name);
        $coverage = $this->reader->contractCoverage($query);

        $report = new PeriodReport(
            rows: $rows,
            range: $query->range,
            granularity: $query->granularity,
            grouping: $query->grouping,
            timeZone: $site->timezone,
            generatedAt: $this->clock->now(),
            criteria: $this->criteriaFor($query),
            contractCoverage: $coverage,
        );

        $this->recordDisclosure($query, $report, $delivery);

        return $report;
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
     * @return list<string>
     */
    private function criteriaFor(PeriodReportQuery $query): array
    {
        $criteria = self::BASE_CRITERIA;

        $criteria[] = $query->includeOpenShifts
            ? 'criteria.open_shifts_included'
            : 'criteria.open_shifts_excluded';

        if ($query->granularity === ReportGranularity::Week) {
            $criteria[] = 'criteria.iso_week';
        }

        return $criteria;
    }

    private function recordDisclosure(PeriodReportQuery $query, PeriodReport $report, ReportDelivery $delivery): void
    {
        $uuids = $report->employeeUuids();

        $this->disclosures->recordDisclosure(self::DATASET, $report->rowCount(), [
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
        ]);
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
