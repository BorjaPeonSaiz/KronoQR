<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * El candado del **padron de cuentas de gestion** (RF-PD-03, RF-ID-10).
 *
 * Protege las dos invariantes de conjunto que no caben en una fila: que la
 * primera cuenta de la instalacion sea una sola (`CreateFirstAdministratorHandler`)
 * y que nunca se quede sin `admin` activo (`DeactivateManagementAccountHandler`).
 * **Una sola clave para las dos**: es el mismo conjunto, y dos claves serian dos
 * ordenes de adquisicion.
 *
 * **Orden unico del producto: cadena de auditoria → este candado → fila de
 * `users`.** Por eso se toma siempre DENTRO de `SerializedLedgerWrite::withChainLock`.
 *
 * La clave es un entero fijo y unico en el producto, compuesto del numero de
 * fase y de tarea en que nacio (5.5 → `5_050_001`): el espacio de
 * `pg_advisory_lock` es global a la base de datos, y dos usos distintos con el
 * mismo numero se bloquearian entre si sin ninguna relacion.
 */
final class ManagementAccountRosterLock
{
    public const int KEY = 5_050_001;

    /**
     * Toma el candado hasta el final de la transaccion en curso. Fuera de una
     * transaccion se soltaria al terminar la sentencia y no protegeria nada.
     */
    public static function acquire(ConnectionInterface $connection): void
    {
        $connection->statement('SELECT pg_advisory_xact_lock(?)', [self::KEY]);
    }
}
