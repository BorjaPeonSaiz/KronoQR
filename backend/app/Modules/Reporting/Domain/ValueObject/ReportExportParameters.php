<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * Lo que se pidio al informe en diferido, **tal cual se pidio** (**RF-IN-06**,
 * decision 1 de la ficha 3.9).
 *
 * ## Por que existe, si ya hay `PeriodReportQuery`
 *
 * Porque {@see PeriodReportQuery} lleva dentro el **alcance** del solicitante y
 * el calendario de festivos, y ninguno de los dos es un parametro de la
 * peticion: los pone el servidor. La columna `parameters` guarda lo que la
 * persona eligio —periodo, granularidad, agrupacion, filtros— y la columna
 * `scope` guarda, aparte, la instantanea del alcance que tenia **en el momento
 * de pedirlo**.
 *
 * Esa separacion es la que permite la garantia de la decision 1: *el trabajo
 * aplica el alcance guardado tal cual y nunca lo recalcula*. Si se recalculara
 * al ejecutar, un responsable al que le quitan un departamento entre la
 * peticion y la generacion recibiria un fichero distinto del que pidio —o, al
 * reves, alguien a quien le añaden uno recibiria datos que no podia ver cuando
 * pulso el boton—. El fichero describe el alcance del instante en que se
 * autorizo, que es el unico que quedo auditado.
 *
 * ## Es un objeto de valor puro
 *
 * Sin reloj, sin configuracion y sin `Illuminate` (reglas duras 1 y 2). Se
 * serializa a `jsonb` en el repositorio y se reconstruye desde ahi; las claves
 * del array son **las del contrato**, para que la fila de la base de datos y el
 * cuerpo del `POST` se lean igual.
 */
final readonly class ReportExportParameters
{
    public function __construct(
        /** Fecha civil ISO `AAAA-MM-DD` del primer dia del periodo. */
        public string $from,
        /** Fecha civil ISO `AAAA-MM-DD` del ultimo dia, inclusive. */
        public string $to,
        public ReportGranularity $granularity,
        public ReportGrouping $grouping,
        public bool $includeOpenShifts,
        public ?int $departmentId,
        /** Identificador **publico** de una persona, para el informe de una sola. */
        public ?string $employeeUuid,
    ) {}

    /**
     * La consulta que ejecuta el trabajo, con el alcance guardado.
     *
     * **El alcance entra por argumento y no se guarda aqui** por lo mismo que en
     * `PeriodReportQuery`: es la acotacion que quien pide no elige. Lo aporta el
     * repositorio desde la columna `scope`, que es la instantanea del momento de
     * la peticion.
     */
    public function toQuery(AccessScope $scope): PeriodReportQuery
    {
        return new PeriodReportQuery(
            scope: $scope,
            range: DateRange::between($this->from, $this->to),
            granularity: $this->granularity,
            grouping: $this->grouping,
            departmentId: $this->departmentId,
            employeeUuid: $this->employeeUuid,
            includeOpenShifts: $this->includeOpenShifts,
        );
    }

    /**
     * Los mismos parametros **sin los dos que señalan a una persona o a un
     * equipo**, para la fila ya purgada (RL-11, decision de minimizacion).
     *
     * ## Que se va y que se queda
     *
     * Se van `employee_uuid` y `department_id`: son los unicos campos de esta
     * consulta que dicen **de quien** era el informe. Se quedan el periodo, la
     * granularidad y la agrupacion, que describen **que** se genero y no señalan a
     * nadie — y sin ellos la lista de exportaciones purgadas dejaria de poder
     * explicarse a si misma.
     *
     * ## Por que se minimiza al purgar y no antes
     *
     * Mientras el fichero existe, esos dos campos son la razon por la que el
     * fichero tiene el contenido que tiene: quitarlos seria no poder explicar la
     * descarga que todavia puede ocurrir. Cuando el fichero se borra dejan de
     * tener uso operativo, y lo unico que quedaria seria un dato personal
     * conservado sin plazo.
     *
     * **El hecho completo no se pierde**: `report_export.requested` guarda los
     * parametros enteros en `audit_log`, que es solo-apendice y se conserva
     * cuatro años (RL-02). Es la misma division que en el resto del producto: el
     * registro operativo se minimiza, el trail no.
     */
    public function minimised(): self
    {
        return new self(
            from: $this->from,
            to: $this->to,
            granularity: $this->granularity,
            grouping: $this->grouping,
            includeOpenShifts: $this->includeOpenShifts,
            departmentId: null,
            employeeUuid: null,
        );
    }

    /**
     * La forma que se guarda en `parameters` y la que sale en la API.
     *
     * Una sola conversion para los dos usos: si el repositorio y el `Resource`
     * tuvieran cada uno la suya, el dia que cambiara una clave el panel
     * enseñaria un filtro y el fichero llevaria otro.
     *
     * @return array<string, bool|int|string|null>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'granularity' => $this->granularity->value,
            'group_by' => $this->grouping->value,
            'include_open_shifts' => $this->includeOpenShifts,
            'department_id' => $this->departmentId,
            'employee_uuid' => $this->employeeUuid,
        ];
    }

    /**
     * Reconstruye los parametros desde el `jsonb`.
     *
     * **Tolerante con lo que falta y estricto con lo que hay**: una fila escrita
     * por una version anterior puede no traer `include_open_shifts`, y ahi la
     * respuesta correcta es el valor por omision del informe —no incluirlos— y
     * no romper el listado entero. Un valor de granularidad o agrupacion
     * desconocido si rompe: significaria generar un fichero distinto del que se
     * pidio, y eso no se hace en silencio.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            from: self::text($stored, 'from'),
            to: self::text($stored, 'to'),
            granularity: ReportGranularity::from(self::text($stored, 'granularity')),
            grouping: ReportGrouping::from(self::text($stored, 'group_by')),
            includeOpenShifts: ($stored['include_open_shifts'] ?? false) === true,
            departmentId: isset($stored['department_id']) && is_numeric($stored['department_id'])
                ? (int) $stored['department_id']
                : null,
            employeeUuid: self::optionalText($stored, 'employee_uuid'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    private static function text(array $stored, string $key): string
    {
        $value = $stored[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    private static function optionalText(array $stored, string $key): ?string
    {
        $value = self::text($stored, $key);

        return $value === '' ? null : $value;
    }
}
