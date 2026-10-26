<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Support;

/**
 * **La huella del contenido de una tarjeta**: el SHA-256 del `qr_payload`, nunca
 * el payload (RS-03, regla dura 10).
 *
 * Es **una sola funcion** porque la usan tres sitios que tienen que coincidir
 * byte a byte: `scan_events.payload_fingerprint` (lo escribe el registro del
 * fichaje), la historia de fallos de `scan_processing_failures` (ADR-061 §1) y la
 * atribucion por huella del descarte del servidor (ADR-061 A2), que busca en la
 * primera con lo que calcula la segunda. Dos copias acabarian discrepando y la
 * atribucion dejaria de encontrar a nadie sin que ninguna prueba lo notara.
 */
final class PayloadFingerprint
{
    private function __construct() {}

    public static function ofQrPayload(string $qrPayload): string
    {
        return hash('sha256', $qrPayload);
    }
}
