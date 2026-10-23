<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use DateTimeImmutable;

/**
 * **De quien era una tarjeta, por la huella de su contenido** (RN-22, ADR-061,
 * A2 del 24-10-2026).
 *
 * Solo lo usa el descarte del servidor de un fichaje de tarjeta que no se pudo
 * atribuir por su firma —quiza lo roto era justo el resolutor—: el titular del
 * fichaje **aceptado** mas reciente con el mismo `payload_fingerprint` en
 * `scan_events`. La huella es un SHA-256 del contenido impreso, asi que solo
 * casa con la misma tarjeta; nunca guarda ni recibe el contenido.
 *
 * **No autentica a nadie**: lo que devuelve va a `discarded_scan_reports`, nunca
 * a `scan_events.employee_id` (ADR-061 §6).
 */
interface CardFingerprintOwners
{
    /**
     * `employee_uuid` del titular del aceptado mas reciente con esa huella y
     * `occurred_at` en [`$notBefore`, `$notAfter`], o `null`.
     */
    public function latestAcceptedOwner(string $payloadFingerprint, DateTimeImmutable $notBefore, DateTimeImmutable $notAfter): ?string;
}
