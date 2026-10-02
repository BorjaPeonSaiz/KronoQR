<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\EmploymentStatusLookup;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use Illuminate\Database\ConnectionInterface;

/**
 * `employees.status` por la clave interna (RN-14). Una consulta por la clave
 * primaria y nada mas: ni nombre ni codigo salen de aqui.
 */
final readonly class EloquentEmploymentStatusLookup implements EmploymentStatusLookup
{
    public function __construct(private ConnectionInterface $connection) {}

    public function statusOf(int $employeeId): ?EmploymentStatus
    {
        $status = $this->connection->table('employees')->where('id', $employeeId)->value('status');

        return \is_string($status) ? EmploymentStatus::tryFrom($status) : null;
    }

    public function statusesOf(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $statuses = [];

        foreach ($this->connection->table('employees')->whereIn('id', $employeeIds)->get(['id', 'status']) as $row) {
            $status = \is_string($row->status ?? null) ? EmploymentStatus::tryFrom($row->status) : null;

            if ($status instanceof EmploymentStatus) {
                $statuses[(int) $row->id] = $status;
            }
        }

        return $statuses;
    }
}
