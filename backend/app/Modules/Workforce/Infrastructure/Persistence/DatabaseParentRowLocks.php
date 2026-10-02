<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Workforce\Application\Port\ParentRowLocks;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see ParentRowLocks} sobre PostgreSQL: `SELECT id … ORDER BY id FOR KEY SHARE`.
 *
 * `ORDER BY id` antes del candado es lo que hace que las filas se tomen en el
 * mismo orden en todas las transacciones (ADR-046 §5): dos importaciones con
 * departamentos solapados no pueden cruzarse.
 *
 * Solo se leen los identificadores. La sentencia existe por el candado, no por
 * los datos.
 */
final readonly class DatabaseParentRowLocks implements ParentRowLocks
{
    public function __construct(private ConnectionInterface $connection) {}

    public function shareInstallationSite(): void
    {
        $this->connection->select('SELECT id FROM sites ORDER BY id FOR KEY SHARE');
    }

    public function shareDepartments(array $departmentIds): void
    {
        $ids = array_values(array_unique($departmentIds));

        if ($ids === []) {
            return;
        }

        sort($ids);

        $this->connection->select(
            'SELECT id FROM departments WHERE id IN ('.implode(', ', array_fill(0, \count($ids), '?')).') ORDER BY id FOR KEY SHARE',
            $ids,
        );
    }
}
