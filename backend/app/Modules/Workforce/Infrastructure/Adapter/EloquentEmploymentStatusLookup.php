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
}
