<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\RecordedScanFailures;
use App\Modules\Attendance\Application\Port\ScanFailureAttempt;
use App\Modules\Attendance\Application\Port\ScanFailureLedger;
use App\Modules\Attendance\Domain\ValueObject\ScanFailureHistory;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * `scan_processing_failures` sobre PostgreSQL (RN-22, ADR-061 §1).
 *
 * ## Su propia transaccion, despues del `ROLLBACK`
 *
 * El caso de uso que fallo ya deshizo la suya cuando llega aqui. El apunte y la
 * lectura de la historia van juntos en una transaccion corta: el `SELECT` ve el
 * `INSERT` de esta misma transaccion, asi que la historia siempre incluye el
 * intento actual (`ScanFailureHistory::$attempts >= 1`).
 *
 * ## El primero manda
 *
 * La historia se cuenta sobre **el quiosco y la huella del primer intento**
 * apuntado de ese `scan_id` (`ORDER BY failed_at, id`). Un intento que no
 * coincide se apunta —es historia del fallo— pero devuelve `null`: con el token
 * de otro quiosco nadie empuja hacia el descarte el fichaje de otro.
 * `IS NOT DISTINCT FROM` para la huella, que puede ser nula.
 *
 * Sin candados: dos fallos simultaneos del mismo `scan_id` se apuntan los dos y
 * como mucho suman un intento de mas, que es inocuo (ADR-061 §1).
 *
 * ## El cupo
 *
 * Los descartes del servidor de un quiosco se leen de `discarded_scan_reports`
 * por el indice parcial `discarded_scan_reports_server_device_recorded_at_index`.
 */
final readonly class DatabaseScanFailureLedger implements ScanFailureLedger
{
    public function __construct(private ConnectionInterface $connection) {}

    public function record(ScanFailureAttempt $attempt): ?RecordedScanFailures
    {
        return $this->connection->transaction(function () use ($attempt): ?RecordedScanFailures {
            $this->connection->insert(
                <<<'SQL'
                    INSERT INTO scan_processing_failures (
                        scan_id, device_id, origin, payload_fingerprint, failed_at, exception_class
                    ) VALUES (?, ?, ?, ?, ?, ?)
                SQL,
                [
                    $attempt->scanId,
                    $attempt->deviceId,
                    $attempt->origin->value,
                    $attempt->payloadFingerprint,
                    $this->toTimestamp($attempt->failedAt),
                    $attempt->exceptionClass,
                ],
            );

            /** @var list<object{device_id: int|string, payload_fingerprint: string|null}> $first */
            $first = $this->connection->select(
                <<<'SQL'
                    SELECT device_id, payload_fingerprint
                      FROM scan_processing_failures
                     WHERE scan_id = CAST(? AS uuid)
                     ORDER BY failed_at, id
                     LIMIT 1
                SQL,
                [$attempt->scanId],
            );

            $origin = $first[0] ?? null;

            if ($origin === null
                || (int) $origin->device_id !== $attempt->deviceId
                || $origin->payload_fingerprint !== $attempt->payloadFingerprint) {
                return null;
            }

            /** @var list<object{attempts: int|string, first_failed_at: string, exception_classes: string|null}> $rows */
            $rows = $this->connection->select(
                <<<'SQL'
                    SELECT count(*) AS attempts,
                           min(failed_at) AS first_failed_at,
                           string_agg(DISTINCT exception_class, ',' ORDER BY exception_class) AS exception_classes
                      FROM scan_processing_failures
                     WHERE scan_id = CAST(? AS uuid)
                       AND device_id = ?
                       AND payload_fingerprint IS NOT DISTINCT FROM ?
                SQL,
                [$attempt->scanId, $attempt->deviceId, $attempt->payloadFingerprint],
            );

            $row = $rows[0];

            return new RecordedScanFailures(
                history: new ScanFailureHistory((int) $row->attempts, $this->toUtc($row->first_failed_at)),
                exceptionClasses: $row->exception_classes === null || $row->exception_classes === ''
                    ? []
                    : explode(',', $row->exception_classes),
            );
        });
    }

    public function serverDiscardsSince(int $deviceId, DateTimeImmutable $since): int
    {
        /** @var object{discards: int|string}|null $row */
        $row = $this->connection->selectOne(
            <<<'SQL'
                SELECT count(*) AS discards
                  FROM discarded_scan_reports
                 WHERE source = 'server'
                   AND device_id = ?
                   AND recorded_at >= ?
            SQL,
            [$deviceId, $this->toTimestamp($since)],
        );

        return $row === null ? 0 : (int) $row->discards;
    }

    public function purgeFailedBefore(DateTimeImmutable $cutoff): int
    {
        return $this->connection->delete(
            'DELETE FROM scan_processing_failures WHERE failed_at < ?',
            [$this->toTimestamp($cutoff)],
        );
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
