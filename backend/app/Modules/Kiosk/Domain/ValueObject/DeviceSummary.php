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
    private const string ACTIVE = 'active';

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
        /** `null` = desconocido: la cola de la tablet salio de IndexedDB (ADR-047). */
        public ?int $pendingQueueSize,
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
        /** Donde guarda la tablet su cola segun su ultimo latido (ADR-047). */
        public QueueStorage $queueStorage = QueueStorage::Durable,
        /** Descartes sin avisar segun su ultimo latido (RN-22). */
        public int $unreportedDiscards = 0,
    ) {}

    /** `active` frente a `revoked` (doc 01 §5.5). */
    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * Segundos desde el ultimo latido, nunca negativos; `null` si no ha latido
     * nunca.
     *
     * El suelo en cero no es cosmetico: `last_seen_at` lo escribe el servidor con
     * su propio reloj, pero un `pg_restore` de una copia hecha en otra maquina, o
     * un salto de NTP hacia atras, pueden dejar un instante en el futuro, y «hace
     * -12 s» haria dudar de todo el informe.
     */
    public function secondsSinceLastSeen(DateTimeImmutable $now): ?int
    {
        return $this->lastSeenAt === null ? null : max(0, $now->getTimestamp() - $this->lastSeenAt->getTimestamp());
    }

    /**
     * Activo, con algun latido y sin pasar el plazo de silencio: el «latido
     * reciente» con el que se mira la version de su aplicacion (sonda
     * `kiosk.app_version`). Mismo criterio que el veredicto `silent` de
     * `KioskHealthRow`, por {@see KioskHealthThresholds::isSilentAfter()}.
     */
    public function isBeatingRecently(DateTimeImmutable $now, KioskHealthThresholds $thresholds): bool
    {
        $elapsed = $this->secondsSinceLastSeen($now);

        return $this->isActive() && $elapsed !== null && ! $thresholds->isSilentAfter($elapsed);
    }
}
