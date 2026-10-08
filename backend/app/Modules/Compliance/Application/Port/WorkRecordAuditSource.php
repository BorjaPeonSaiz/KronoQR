<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\WorkRecordAuditContext;
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
     * **Primero, y siempre, un {@see WorkRecordAuditContext}** —los asientos de
     * purga del registro y los años de `audit_log` sellados—; despues, cada
     * tramo de la ventana emparejado con sus asientos `shift_entry.*`.
     *
     * **Todo en una sola instantanea.** El tramo y su asiento se escriben en la
     * misma transaccion; si la fila, el asiento o la purga se leyeran en
     * momentos distintos, un fichaje o una purga confirmados entre dos lecturas
     * aparecerian como una discrepancia que no existe.
     *
     * **Por lotes**: la pasada completa recorre todo el plazo de conservacion y
     * no puede cargarlo en memoria. El orden de los pares no esta garantizado.
     *
     * @return iterable<WorkRecordAuditContext|WorkRecordPair>
     */
    public function read(WorkRecordReconciliationWindow $window, int $chunkSize): iterable;
}
