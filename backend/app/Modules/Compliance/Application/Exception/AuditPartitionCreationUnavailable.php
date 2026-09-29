<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * La base de datos no ofrece la forma de crear la particion anual de
 * `audit_log` (ADR-027, ADR-042).
 *
 * Desde ADR-042 la aplicacion no crea la particion por si misma: se la pide a la
 * funcion `audit_log_create_partition`, que la crea con los permisos del
 * propietario y solo para el año en curso o el siguiente. Si esa funcion no
 * existe —la migracion que la crea no se ha aplicado— o el rol de la aplicacion
 * no puede ejecutarla, la tarea programada no tiene otro camino, y **no debe
 * tenerlo**: buscar una credencial con mas privilegios devolveria al runtime lo
 * que ADR-042 le quita.
 *
 * No bloquea el fichaje mientras exista la particion del año en curso. El año
 * siguiente se prepara desde noviembre, con dos meses de margen.
 */
final class AuditPartitionCreationUnavailable extends RuntimeException
{
    /** La migracion que crea la funcion; se nombra en el mensaje para quien lo lea en el log. */
    public const string MIGRATION = '2026_09_29_100000_audit_log_partition_function';

    private function __construct(public readonly int $year, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function functionMissing(int $year, ?Throwable $previous = null): self
    {
        return new self(
            $year,
            'No se puede crear la particion audit_log_'.$year.': la base de datos no ofrece la funcion '
            .'audit_log_create_partition al rol de la aplicacion. La crea la migracion '.self::MIGRATION
            .', que se aplica actualizando el producto (ADR-042).',
            $previous,
        );
    }
}
