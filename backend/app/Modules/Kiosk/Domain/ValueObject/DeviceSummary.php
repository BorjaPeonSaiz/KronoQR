<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Un quiosco tal y como lo ve el panel (`GET /api/v1/devices`, RF-PD-06,
 * RF-PA-07).
 *
 * **Nunca lleva el token ni su hash**, ni lo llevara: quien lo viera tendria la
 * mitad del trabajo hecho para suplantar a un dispositivo. Tampoco el `site_id`,
 * que con un centro por instalacion (ADR-040) es siempre el mismo.
 *
 * **Ni un dato personal** (regla dura 21): un dispositivo es un aparato en una
 * pared, no tiene titular, y su nombre es el del sitio.
 *
 * `id` es la clave interna y **no sale en la respuesta**: la necesita `unpair`
 * para hablar con `Identity` sin volver a consultar.
 */
final readonly class DeviceSummary
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $name,
        /** `active` o `revoked` (doc 01 §5.5). */
        public string $status,
        /** `null` mientras la tablet no haya latido nunca. */
        public ?string $appVersion,
        public ?DateTimeImmutable $lastSeenAt,
        /** Lo declara el dispositivo y nadie lo comprueba: es operacion, no autoridad. */
        public int $pendingQueueSize,
        /** `null` en los dispositivos dados de alta antes del emparejamiento por codigo. */
        public ?DateTimeImmutable $pairedAt,
        /** `occurred_at` del fichaje mas antiguo de su cola; `null` con la cola vacia o sin latido. */
        public ?DateTimeImmutable $oldestPendingAt = null,
        /**
         * Nivel de bateria en tanto por ciento, `null` si el navegador no lo informa.
         *
         * **`null` no es cero y no avisa**: la Battery Status API solo la ofrece
         * Chrome en Android, y una tablet que no informa no es una tablet averiada.
         */
        public ?int $batteryLevel = null,
        /** Si estaba enchufada en su ultimo latido; `null` si no lo informa. */
        public ?bool $batteryCharging = null,
    ) {}
}
