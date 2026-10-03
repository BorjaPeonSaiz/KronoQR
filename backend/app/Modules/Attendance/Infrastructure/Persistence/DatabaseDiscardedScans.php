<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\DiscardedScans;
use App\Modules\Attendance\Domain\Policy\DiscardedScanReviewPolicy;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScan;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttributionMethod;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Los avisos de fichaje descartado con dueño (RN-22, ADR-047), leidos hacia
 * atras de `discarded_scan_reports`.
 *
 * **No decide nada**: filtra por lo que el aviso ya escribio —dueño y
 * `already_recorded`— y por lo que el registro dice hoy —que el `scan_id` no
 * este en `scan_events`—. Si el aviso cae en la ventana que abre incidencia lo
 * decide el dominio ({@see DiscardedScanReviewPolicy}).
 *
 * Entra por `discarded_scan_reports_attributed_recorded_at_index`, parcial sobre
 * las filas con dueño y sin registrar. Se une con `employees` para el UUID y la
 * fecha del alta, y con `devices` para el UUID del quiosco. Ni un nombre ni un
 * codigo de empleado salen de aqui (regla dura 21).
 */
final readonly class DatabaseDiscardedScans implements DiscardedScans
{
    public function __construct(private ConnectionInterface $connection) {}

    public function attributedBetween(DateTimeImmutable $fromRecordedAt, DateTimeImmutable $toRecordedAt): array
    {
        /** @var list<object{scan_id: string, owner_uuid: string, owner_hired_on: string, device_uuid: string, origin: string, occurred_at: string, recorded_at: string, http_status: int|string, problem_type: string|null, attribution: string, credential_issued_at: string|null}> $rows */
        $rows = $this->connection->select(
            <<<'SQL'
                SELECT d.scan_id::text AS scan_id,
                       e.uuid::text AS owner_uuid,
                       e.hired_at::text AS owner_hired_on,
                       dv.uuid::text AS device_uuid,
                       d.origin, d.occurred_at, d.recorded_at, d.http_status, d.problem_type,
                       d.attribution, d.credential_issued_at
                  FROM discarded_scan_reports d
                  JOIN employees e ON e.id = d.owner_employee_id
                  JOIN devices dv ON dv.id = d.device_id
                 WHERE d.owner_employee_id IS NOT NULL
                   AND NOT d.already_recorded
                   AND d.recorded_at BETWEEN ? AND ?
                   AND NOT EXISTS (SELECT 1 FROM scan_events s WHERE s.scan_id = d.scan_id)
                 ORDER BY d.occurred_at, d.scan_id
            SQL,
            [$this->toTimestamp($fromRecordedAt), $this->toTimestamp($toRecordedAt)],
        );

        $scans = [];

        foreach ($rows as $row) {
            $scans[] = new DiscardedScan(
                scanId: $row->scan_id,
                ownerUuid: $row->owner_uuid,
                deviceUuid: $row->device_uuid,
                origin: ScanOrigin::from($row->origin),
                occurredAt: $this->toUtc($row->occurred_at),
                recordedAt: $this->toUtc($row->recorded_at),
                httpStatus: (int) $row->http_status,
                problemType: $row->problem_type,
                attribution: DiscardedScanAttributionMethod::from($row->attribution),
                credentialIssuedAt: $row->credential_issued_at === null ? null : $this->toUtc($row->credential_issued_at),
                ownerHiredOn: substr($row->owner_hired_on, 0, 10),
            );
        }

        return $scans;
    }

    /** La misma conversion que {@see EloquentScanLog}: hacia arriba solo sale UTC (regla dura 3). */
    private function toUtc(string|DateTimeInterface $value): DateTimeImmutable
    {
        $instant = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value);

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    private function toTimestamp(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }
}
