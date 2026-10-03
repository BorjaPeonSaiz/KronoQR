<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\WithdrawnCredentialScan;
use DateTimeImmutable;

/**
 * Los escaneos de tarjeta **autentica** usada antes de su retirada (RN-20,
 * ADR-047), leidos hacia atras de `scan_events`.
 *
 * Lee lo que el fichaje ya escribio —un rechazo de credencial atribuido a su
 * titular y marcado para revision—, igual que {@see OutOfOrderScans} con RN-18
 * y {@see RejectedPinScans} con RN-19: sin evento, listener ni proceso nuevos
 * dentro de la transaccion del fichaje.
 *
 * **La ventana es de `recorded_at`**: el escaneo que mas necesita revision es el
 * que mas tardo en llegar. La jornada del hallazgo sale del `occurred_at` en la
 * zona del centro (RN-05).
 */
interface WithdrawnCredentialScans
{
    /**
     * Escaneos cuyo `recorded_at` ∈ [from, to], ordenados por `occurred_at` ASC
     * y `scan_id` ASC.
     *
     * @return list<WithdrawnCredentialScan>
     */
    public function withdrawnBetween(DateTimeImmutable $fromRecordedAt, DateTimeImmutable $toRecordedAt): array;
}
