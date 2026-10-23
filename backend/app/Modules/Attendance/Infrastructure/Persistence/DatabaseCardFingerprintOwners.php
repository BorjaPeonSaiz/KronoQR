<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\CardFingerprintOwners;
use App\Modules\Attendance\Application\Port\ScanResult;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * La huella de una tarjeta en `scan_events` (RN-22, ADR-061, A2).
 *
 * **Acotada en el tiempo a proposito**: `payload_fingerprint` no tiene indice
 * —anadirlo seria pagar una escritura mas en cada fichaje (RNF-P-01) para un
 * camino que corre como mucho unas pocas veces al dia por quiosco—, asi que la
 * consulta entra por `scan_events_occurred_at_utc_index` con una ventana que
 * fija el caso de uso. Solo fichajes de tarjeta y solo aceptados: un rechazo no
 * dice que la tarjeta fuera de nadie.
 */
final readonly class DatabaseCardFingerprintOwners implements CardFingerprintOwners
{
    public function __construct(private ConnectionInterface $connection) {}

    public function latestAcceptedOwner(string $payloadFingerprint, DateTimeImmutable $notBefore, DateTimeImmutable $notAfter): ?string
    {
        $placeholders = implode(', ', array_fill(0, \count(ScanResult::acceptedValues()), '?'));

        /** @var object{owner_uuid: string}|null $row */
        $row = $this->connection->selectOne(
            <<<SQL
                SELECT e.uuid::text AS owner_uuid
                  FROM scan_events s
                  JOIN employees e ON e.id = s.employee_id
                 WHERE (s.occurred_at AT TIME ZONE 'UTC') BETWEEN (CAST(? AS timestamptz) AT TIME ZONE 'UTC')
                                                           AND (CAST(? AS timestamptz) AT TIME ZONE 'UTC')
                   AND s.origin = 'qr_kiosk'
                   AND s.payload_fingerprint = ?
                   AND s.result IN ({$placeholders})
                 ORDER BY s.occurred_at DESC, s.id DESC
                 LIMIT 1
            SQL,
            [
                $notBefore->format('Y-m-d H:i:s.uP'),
                $notAfter->format('Y-m-d H:i:s.uP'),
                $payloadFingerprint,
                ...ScanResult::acceptedValues(),
            ],
        );

        return $row?->owner_uuid;
    }
}
