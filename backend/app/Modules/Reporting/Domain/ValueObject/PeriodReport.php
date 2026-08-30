<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;

/**
 * El informe por periodo completo: las filas y **los criterios con los que se
 * calcularon** (**RF-IN-01**, RF-IN-02, RF-IN-03).
 *
 * ## Los criterios viajan con el resultado, no en la documentacion
 *
 * `/informe-nuevo` lo pide por escrito: *«los criterios de inclusion van visibles
 * en el propio informe»*. Un informe de horas sin ellos es una tabla de numeros
 * que cada persona interpreta a su manera —¿cuenta el turno que sigue abierto?
 * ¿y el tramo que se anulo?— y esa interpretacion acaba discutiendose en una
 * reunion de nomina, no en el codigo.
 *
 * Aqui viajan como **claves** ({@see self::criteria}), no como texto: la
 * traduccion es de la capa de presentacion, que es la que sabe en que idioma
 * esta hablando. El dominio no tiene idioma.
 *
 * ## Es el mismo objeto que consumira la exportacion de la tarea 2.9
 *
 * CSV, XLSX y PDF (RF-IN-04) se generan **desde aqui**, no desde otra consulta.
 * Si la exportacion tuviera su propia SQL, el fichero que alguien adjunta a un
 * correo y la tabla que ve en pantalla podrian discrepar, y el que se cree seria
 * el equivocado. Por eso este tipo lleva ya todo lo que un fichero necesita: la
 * zona, el instante de generacion, los criterios y la cobertura de contrato.
 */
final readonly class PeriodReport
{
    /**
     * @param  list<PeriodReportRow>  $rows
     * @param  list<string>  $criteria  Claves de `lang/*\/reports.php`, en el orden en el que se
     *                                  leen. Nunca texto ya traducido.
     */
    public function __construct(
        public array $rows,
        public DateRange $range,
        public ReportGranularity $granularity,
        public ReportGrouping $grouping,
        /** Zona del centro (ADR-040). Los `work_date` ya estan expresados en ella. */
        public string $timeZone,
        public DateTimeImmutable $generatedAt,
        public array $criteria,
        public ContractCoverage $contractCoverage,
    ) {}

    public function rowCount(): int
    {
        return \count($this->rows);
    }

    /**
     * Los `employee_uuid` distintos que aparecen en el informe.
     *
     * Es lo que el asiento de `audit_log` necesita para responder a la pregunta
     * que RS-05 obliga a contestar: **de quien** se consultaron los datos. Con
     * agrupacion por departamento o por centro devuelve una lista vacia, y eso
     * es correcto: ahi no se ha divulgado el dato de nadie en particular.
     *
     * @return list<string>
     */
    public function employeeUuids(): array
    {
        $uuids = [];

        foreach ($this->rows as $row) {
            if ($row->subject->employeeUuid !== null) {
                $uuids[$row->subject->employeeUuid] = true;
            }
        }

        return array_keys($uuids);
    }

    public function workedMinutes(): int
    {
        return array_sum(array_map(
            static fn (PeriodReportRow $row): int => $row->workedMinutes,
            $this->rows,
        ));
    }
}
