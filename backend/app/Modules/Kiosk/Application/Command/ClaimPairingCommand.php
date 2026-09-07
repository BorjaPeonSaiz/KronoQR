<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Command;

use SensitiveParameter;

/**
 * La orden de recoger el resultado de un emparejamiento (**RF-PD-06**, paso 3).
 *
 * Las dos credenciales de la solicitud, tal como las devolvio
 * `POST /api/v1/kiosk/pair`.
 *
 * `#[SensitiveParameter]` sobre el secreto por lo mismo que en el resolutor de
 * credenciales: sin el, una traza de excepcion lo escribiria en el log y en
 * `error_events`, que es la tabla que viaja al fabricante dentro del paquete de
 * diagnostico (ADR-020).
 */
final readonly class ClaimPairingCommand
{
    public function __construct(
        public string $pairingId,
        #[SensitiveParameter]
        public string $secret,
    ) {}
}
