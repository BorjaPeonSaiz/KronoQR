<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

/**
 * El quiosco que acaba de quedar dado de alta o reactivado (**RF-PD-06**).
 *
 * Lo devuelve el puerto `DeviceRegistry::provision()`
 * y lo consumen dos sitios: el evento `DeviceProvisioned` que se audita y la
 * respuesta de `POST /api/v1/kiosk/pair/confirm`.
 *
 * **`reactivated` y `previousStatus` existen para el texto y para el trail.** El
 * panel dice «Se ha reactivado el quiosco Recepcion» en vez de «Creado», y quien
 * sustituye una tablet averiada tiene que ver que el sistema entendio lo que
 * estaba haciendo — no descubrirlo tres dias despues en un informe con dos
 * quioscos donde hay uno (ADR-028).
 */
final readonly class ProvisionedDevice
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $name,
        /** Siempre `active`: si no lo estuviera, la tablet no podria recoger su token. */
        public string $status,
        public bool $reactivated,
        /** Lo que era antes, o `null` si el puesto no existia. */
        public ?string $previousStatus,
    ) {}
}
