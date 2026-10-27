<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\CardFingerprintOwners;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * La huella de una tarjeta en `scan_events` (RN-22, ADR-061 A2).
 *
 * **Entra por `scan_events_accepted_card_fingerprint_index`**, parcial sobre los
 * fichajes de tarjeta aceptados y ordenado por `occurred_at`: la busqueda toca
 * solo las filas de esa huella y para en la primera. Sin el indice recorria los
 * fichajes de toda la ventana filtrando fila a fila, y su duracion era un canal
 * lateral sobre cuanto se habia usado la tarjeta (RS-03). Lo vigila
 * `CardFingerprintIndexUsageTest`.
 *
 * **El predicado del indice va escrito literal**, el mismo de su migracion: con
 * los resultados como parametros enlazados, el planificador no puede demostrar
 * que la consulta cae dentro del indice parcial. Son constantes del esquema, no
 * entrada de nadie.
 */
final readonly class DatabaseCardFingerprintOwners implements CardFingerprintOwners
{
    public function __construct(private ConnectionInterface $connection) {}

    public function latestAcceptedOwner(string $payloadFingerprint, DateTimeImmutable $notBefore, DateTimeImmutable $notAfter): ?string
    {
        /** @var object{owner_uuid: string}|null $row */
        $row = $this->connection->selectOne(
            <<<'SQL'
                SELECT e.uuid::text AS owner_uuid
                  FROM scan_events s
                  JOIN employees e ON e.id = s.employee_id
                 WHERE s.payload_fingerprint = ?
                   AND s.origin = 'qr_kiosk'
                   AND s.result IN ('clock_in', 'clock_out', 'break_start', 'break_end')
                   AND s.occurred_at BETWEEN CAST(? AS timestamptz) AND CAST(? AS timestamptz)
                 ORDER BY s.occurred_at DESC
                 LIMIT 1
            SQL,
            [
                $payloadFingerprint,
                $notBefore->format('Y-m-d H:i:s.uP'),
                $notAfter->format('Y-m-d H:i:s.uP'),
            ],
        );

        return $row?->owner_uuid;
    }
}
