<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Application\Port\ScheduledOffboardingRecord;
use Illuminate\Database\ConnectionInterface;

/**
 * El ultimo asiento de programacion o anulacion de la baja de una persona, leido
 * de `audit_log` (RN-14, ADR-062 §4).
 *
 * La consulta entra por `audit_log_action_index` con las dos acciones, que son
 * raras, y filtra por el `employee_uuid` del payload: no hace falta un indice
 * propio. Las particiones anteriores a la 2.3.0 no tienen ninguna de las dos.
 */
final readonly class DatabaseScheduledOffboardingRecord implements ScheduledOffboardingRecord
{
    public function __construct(private ConnectionInterface $connection) {}

    public function lastScheduledTerminationOf(string $employeeUuid): ?string
    {
        $row = $this->connection->table('audit_log')
            ->whereIn('action', [
                AuditAction::EmployeeOffboardingScheduled->value,
                AuditAction::EmployeeOffboardingCancelled->value,
            ])
            ->whereRaw("payload->>'employee_uuid' = ?", [$employeeUuid])
            ->orderByDesc('id')
            ->first(['action', 'payload']);

        if (! \is_object($row) || ! property_exists($row, 'action') || $row->action !== AuditAction::EmployeeOffboardingScheduled->value) {
            return null;
        }

        $payload = property_exists($row, 'payload') && \is_string($row->payload) ? json_decode($row->payload, true) : null;
        $terminatedOn = \is_array($payload) ? ($payload['terminated_on'] ?? null) : null;

        return \is_string($terminatedOn) ? $terminatedOn : null;
    }
}
