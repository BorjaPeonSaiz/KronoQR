<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationWindow;

/**
 * Lectura del registro horario frente a su auditoria para la conciliacion
 * (ADR-057 §4).
 *
 * **Solo lee.** La conciliacion no corrige nada, al contrario que la de
 * `daily_totals`: la proyeccion se puede reconstruir desde los hechos, pero aqui
 * lo que no cuadra son los hechos mismos, y decidir cual de los dos lados dice
 * la verdad es trabajo de una persona con el runbook delante.
 */
interface WorkRecordAuditSource
{
    /**
     * Cada tramo de la ventana emparejado con su ultimo asiento `shift_entry.*`,
     * y con el corte de la ultima purga auditada y los años de auditoria
     * purgados.
     *
     * **Todo en una sola instantanea.** El tramo y su asiento se escriben en la
     * misma transaccion; si la fila, el asiento o la purga se leyeran en
     * momentos distintos, un fichaje o una purga confirmados entre dos lecturas
     * aparecerian como una discrepancia que no existe.
     *
     * **Por lotes**: la pasada completa recorre todo el plazo de conservacion y
     * no puede cargarlo en memoria. El orden no esta garantizado.
     *
     * @return iterable<WorkRecordPair>
     */
    public function pairs(WorkRecordReconciliationWindow $window, int $chunkSize): iterable;
}
