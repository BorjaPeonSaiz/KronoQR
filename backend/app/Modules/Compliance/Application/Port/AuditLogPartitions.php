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
     *
     * **La crea el motor, no el rol de quien llama** (ADR-042): el adaptador
     * pide la particion a una funcion de la base que la crea con los permisos
     * del propietario, para el año en curso o el siguiente. Quien llama no
     * necesita, ni debe tener, ninguna credencial capaz de alterar el registro.
     *
     * Si la base no ofrece esa funcion al rol de la aplicacion —la migracion
     * que la crea no se ha aplicado—, lanza la excepcion de aplicacion
     * `AuditPartitionCreationUnavailable`. Se nombra en prosa y no con
     * `@throws` porque Deptrac no deja a la capa de puertos depender del resto
     * de la capa de aplicacion.
     */
    public function create(int $year): void;
}
