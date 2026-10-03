<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\DiscardedScanReportLog;
use App\Modules\Attendance\Application\Port\DiscardedScanReportRecord;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * `discarded_scan_reports` sobre PostgreSQL (RN-22, ADR-047).
 *
 * **Una sola sentencia por aviso, con y sin dueño** (F5 del dictamen del bloque
 * 18, el patron de ADR-043 punto 4): `owner_employee_id` se resuelve dentro del
 * `INSERT` con una subconsulta sobre `employees.uuid`, y `already_recorded` con
 * otra sobre `scan_events.scan_id`. Sin dueño, el parametro es `NULL` y la
 * subconsulta no encuentra fila: la sentencia, el plan y el numero de viajes son
 * los mismos se atribuya o no.
 *
 * **La idempotencia es el UNIQUE** (regla dura 8): `ON CONFLICT DO NOTHING`
 * espera a la transaccion que inserta la misma clave y devuelve cero. Nunca dos
 * filas.
 */
final readonly class DatabaseDiscardedScanReportLog implements DiscardedScanReportLog
{
    public function __construct(private ConnectionInterface $connection) {}

    public function record(DiscardedScanReportRecord $report): bool
    {
        $attribution = $report->attribution;
        $issuedAt = $attribution->credentialIssuedAt;

        $written = $this->connection->affectingStatement(
            <<<'SQL'
                INSERT INTO discarded_scan_reports (
                    scan_id, device_id, origin, occurred_at, discarded_at, recorded_at,
                    http_status, problem_type, owner_employee_id, attribution, credential_issued_at,
                    already_recorded
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, (SELECT id FROM employees WHERE uuid = CAST(? AS uuid)), ?, ?,
                    EXISTS (SELECT 1 FROM scan_events WHERE scan_id = CAST(? AS uuid))
                )
                ON CONFLICT (scan_id) DO NOTHING
            SQL,
            [
                $report->scanId,
                $report->deviceId,
                $report->origin->value,
                $this->toTimestamp($report->occurredAt),
                $this->toTimestamp($report->discardedAt),
                $this->toTimestamp($report->recordedAt),
                $report->httpStatus,
                $report->problemType,
                $attribution->ownerUuid,
                $attribution->method->value,
                $issuedAt instanceof DateTimeImmutable ? $this->toTimestamp($issuedAt) : null,
                $report->scanId,
            ],
        );

        return $written > 0;
    }

    private function toTimestamp(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }
}
