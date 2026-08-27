<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * Las particiones anuales de `audit_log` (ADR-027).
 *
 * **Por que hay una tarea programada detras de esto.** Con la tabla particionada
 * por rango, un `INSERT` cuyo `occurred_at` no cae en ninguna particion **falla**
 * —PostgreSQL dice «no partition of relation "audit_log" found for row»—, y un
 * fallo al escribir auditoria tumba la accion auditada: el 1 de enero a las
 * 00:00 nadie podria fichar. No puede quedarse en silencio, pero tampoco puede
 * llegar a ocurrir.
 */
interface AuditLogPartitions
{
    /**
     * Los años que ya tienen particion, ascendente.
     *
     * @return list<int>
     */
    public function years(): array;

    /**
     * Crea la particion del año dado, con los mismos permisos que la tabla
     * madre: `INSERT` y `SELECT` para la aplicacion y **nunca** `UPDATE` ni
     * `DELETE` (regla dura 6). Los permisos no se heredan al adjuntar una
     * particion, asi que otorgarlos es parte indivisible de crearla.
     */
    public function create(int $year): void;
}
