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
 * Desde RF-GP-04 la clave puede llevar ademas sus sustituciones —«N festivos del
 * perfil X en el periodo»—, y por eso cada criterio es un
 * {@see ReportCriterion} y no una cadena. Lo que no cambia es que aqui no hay
 * ni una frase traducida.
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
     * @param  list<ReportCriterion>  $criteria  Claves de `lang/*\/reports.php` con sus
     *                                           sustituciones, en el orden en el que se leen.
     *                                           Nunca texto ya traducido.
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

    /**
     * Las horas contratadas del informe entero, en minutos.
     *
     * La hermana de {@see self::workedMinutes()}, y la pide el cuadro de impacto
     * (RF-IN-08), que enseña «trabajadas frente a contratadas» como totales de la
     * instalacion. Sumar aqui y no en el consumidor es lo que evita que cada
     * pantalla decida por su cuenta si el cubo de quien no tiene departamento entra
     * en el total — entra, y por eso existe.
     *
     * **Ojo con lo que NO mide.** Los dias sin contrato vigente no suman nada y
     * salen aparte en `contractCoverage`: un informe con cobertura incompleta da un
     * total contratado mas bajo de lo real, y el cuadro lo dice en sus criterios
     * en lugar de rellenarlo con una estimacion.
     */
    public function contractedMinutes(): int
    {
        return array_sum(array_map(
            static fn (PeriodReportRow $row): int => $row->contractedMinutes,
            $this->rows,
        ));
    }
}
