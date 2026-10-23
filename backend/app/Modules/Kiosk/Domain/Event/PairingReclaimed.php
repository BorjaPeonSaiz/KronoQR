<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * **Una tablet repitio la recogida de su emparejamiento y recibio otro token**
 * (V6-KI-02, 2.3.0): la respuesta de la primera se perdio y, dentro de la
 * ventana corta y desde el mismo origen, el servidor reemitio el token del MISMO
 * dispositivo y retiro el anterior.
 *
 * Deja asiento (`device.pairing_reclaimed`, actor `system`) ademas del
 * `device.paired` de la emision: quien lea el trail tiene que ver que un quiosco
 * cambio de token sin pasar por el latido ni por un `admin`, y por que.
 *
 * **Sin datos personales** (regla dura 21): un emparejamiento no conoce a nadie.
 * Ni el origen ni su huella: es un detalle de la comprobacion, no un hecho.
 */
final readonly class PairingReclaimed implements DomainEvent
{
    public function __construct(
        public int $deviceId,
        public string $deviceUuid,
        /** `pairing_id` de la solicitud, para unirlo a los otros pasos en el trail. */
        public string $pairingRequestUuid,
        /** Segundos desde la primera recogida. */
        public int $secondsSinceFirstClaim,
        /** Que recogida repetida es esta: 1 la primera. */
        public int $reclaim,
        private DateTimeImmutable $occurredAt,
    ) {}

    /** Nombre estable, no derivado de la clase (doc 02 §1.6). */
    public function eventName(): string
    {
        return 'kiosk.pairing_reclaimed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
