<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Command;

use App\Modules\Kiosk\Domain\ValueObject\DeviceName;
use App\Modules\Kiosk\Domain\ValueObject\PairingCode;

/**
 * La orden de confirmar un codigo y vincular la tablet (**RF-PD-06**, paso 2).
 *
 * **El actor no se declara, se toma de la sesion.** Aceptarlo en el cuerpo
 * permitiria dar de alta un quiosco a nombre de otra persona, y el asiento de
 * `audit_log` perderia justo lo que lo hace util. Es `null` desde
 * `kiosk:pairing-code`, que no tiene sesion: el asiento lo traduce a actor
 * `system`, que es la respuesta honesta.
 *
 * **El centro tampoco viaja**: hay uno por instalacion y lo resuelve el servidor
 * (ADR-040).
 *
 * Los dos valores llegan ya como objetos de valor: la validacion de forma ocurre
 * en el borde y este comando no admite una cadena cualquiera.
 */
final readonly class ConfirmPairingCommand
{
    public function __construct(
        public PairingCode $code,
        public DeviceName $name,
        public ?int $actorUserId = null,
    ) {}
}
