<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\ValueObject\ContractCoverage;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;

/**
 * De donde salen las cifras del informe por periodo (**RF-IN-01**).
 *
 * ## La fuente esta decidida y no es negociable
 *
 * Los agregados por empleado y dia salen de **`daily_totals`**, que es la
 * proyeccion reconstruible de RN-06 (regla dura 7, ADR-007). El adaptador **no
 * recalcula** desde `shift_entries` ni desde `scan_events`: si la cifra no
 * cuadra, el problema es la proyeccion y se arregla con `attendance:reconcile`
 * (RF-PR-02). Dos formas de calcular el mismo total es como se acaba teniendo
 * dos totales, y el que se cree seria el equivocado.
 *
 * Lo contratado sale de `employment_contracts` (RF-GP-02), cruzado dia a dia con
 * la vigencia. El detalle de tramos y la trazabilidad de correcciones no entran
 * en este informe: para eso esta `GET /employees/{uuid}/workdays`.
 *
 * ## El alcance entra en la consulta
 *
 * RF-ID-03, regla dura 18. Nunca se filtra un resultado ya agregado: si se
 * hiciera, el total por centro describiria horas de personas que quien pregunta
 * no puede ver.
 *
 * ## Dos metodos y no uno
 *
 * {@see self::estimateRows()} existe para poder decir «esto no se entrega en el
 * acto» **antes** de ejecutar el informe (RNF-P-05). Meterlo dentro de
 * `rows()` obligaria a empezar la consulta cara para descubrir que no se debia
 * empezar.
 */
interface PeriodReportReader
{
    /**
     * Cuantas filas produciria el informe: sujetos del alcance × cubos de
     * periodo. Es una estimacion barata, no un `COUNT` del resultado.
     */
    public function estimateRows(PeriodReportQuery $query): int;

    /**
     * Las filas del informe, ordenadas por sujeto y despues por periodo.
     *
     * @return list<PeriodReportRow>
     *
     * @throws ReportTooLargeForSynchronousDelivery si PostgreSQL cancela la consulta por
     *                                              agotar su `statement_timeout`
     */
    public function rows(PeriodReportQuery $query, string $siteName): array;

    /**
     * Cuantos dias-persona del informe quedaron sin contrato vigente (RF-IN-03).
     *
     * Va aparte de las filas porque es una cifra del informe entero y no de cada
     * fila: con agrupacion por departamento, «cuatro dias sin contrato» no se
     * puede repartir entre las filas sin inventar a quien pertenecen.
     */
    public function contractCoverage(PeriodReportQuery $query): ContractCoverage;
}
