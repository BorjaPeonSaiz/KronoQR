<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\AuditLogPartitions;
use Illuminate\Database\ConnectionInterface;

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
 * **Crear una particion es DDL**, y el rol de la aplicacion no tiene DDL desde
 * la tarea 1.14. La tarea programada de creacion corre, por tanto, sobre la
 * conexion de migracion; el comando lo dice en su firma y lo explica en su
 * mensaje de error si falta.
 */
final readonly class DatabaseAuditLogPartitions implements AuditLogPartitions
{
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
        foreach (AuditLogSchema::createPartitionStatements($year) as $statement) {
            $this->connection->statement($statement);
        }
    }
}
