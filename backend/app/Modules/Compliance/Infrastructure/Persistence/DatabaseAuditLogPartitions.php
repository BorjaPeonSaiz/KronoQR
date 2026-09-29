<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Exception\AuditPartitionCreationUnavailable;
use App\Modules\Compliance\Application\Port\AuditLogPartitions;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Las particiones anuales de `audit_log`, leidas del catalogo de PostgreSQL
 * (ADR-027).
 *
 * **Se leen del catalogo y no de una tabla propia.** Un registro paralelo de
 * «que particiones deberia haber» puede desincronizarse de las que de verdad
 * existen, y el sintoma seria el peor posible: el comando programado diciendo
 * que todo esta bien mientras el `INSERT` del 1 de enero falla. `pg_inherits` no
 * puede mentir.
 *
 * **Crear una particion es DDL, y la aplicacion no lo hace** (ADR-042). El rol
 * de la aplicacion no tiene DDL desde la tarea 1.14 y no debe tener ninguna
 * credencial que pueda alterar el registro, asi que `create()` pide la particion
 * a la funcion `audit_log_create_partition`: `SECURITY DEFINER`, propiedad del
 * rol de migracion y ejecutable solo por el rol de la aplicacion, que crea la
 * particion del año en curso o del siguiente con los mismos permisos que las
 * demas ({@see AuditLogSchema::createFunctionStatements()}). Este adaptador
 * corre, por tanto, sobre la conexion de la aplicacion, igual que todo el
 * runtime.
 */
final readonly class DatabaseAuditLogPartitions implements AuditLogPartitions
{
    /** La funcion no existe: la migracion que la crea no se ha aplicado. */
    private const string UNDEFINED_FUNCTION = '42883';

    /** El rol que llama no tiene `EXECUTE` sobre la funcion. */
    private const string INSUFFICIENT_PRIVILEGE = '42501';

    public function __construct(private ConnectionInterface $connection) {}

    public function years(): array
    {
        /** @var list<object{relname: string}> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT child.relname
            FROM pg_inherits
            JOIN pg_class parent ON parent.oid = pg_inherits.inhparent
            JOIN pg_class child  ON child.oid  = pg_inherits.inhrelid
            JOIN pg_namespace ns ON ns.oid     = parent.relnamespace
            WHERE parent.relname = ? AND ns.nspname = 'public'
            ORDER BY child.relname
        SQL, [AuditLogSchema::TABLE]);

        $years = [];

        foreach ($rows as $row) {
            if (preg_match('/^'.preg_quote(AuditLogSchema::TABLE, '/').'_(\d{4})$/', $row->relname, $match) === 1) {
                $years[] = (int) $match[1];
            }
        }

        sort($years);

        return $years;
    }

    public function create(int $year): void
    {
        try {
            $this->connection->selectOne(
                'SELECT public.'.AuditLogSchema::CREATE_FUNCTION.'(?) AS created',
                [$year],
            );
        } catch (QueryException $exception) {
            if (\in_array($exception->getCode(), [self::UNDEFINED_FUNCTION, self::INSUFFICIENT_PRIVILEGE], true)) {
                throw AuditPartitionCreationUnavailable::functionMissing($year, $exception);
            }

            // Cualquier otro rechazo de la funcion —año fuera de ventana o
            // sellado (22023), tabla homonima que no es particion (42P07),
            // espera de bloqueo agotada (55P03)— sube tal cual: es un fallo que
            // hay que investigar, no una migracion pendiente.
            throw $exception;
        }
    }
}
