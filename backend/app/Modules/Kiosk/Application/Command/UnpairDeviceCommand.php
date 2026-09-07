<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Command;

/**
 * La orden de retirar un quiosco de servicio (**RF-PD-06**).
 *
 * **Por su identificador publico**, nunca por la clave interna: es lo que viaja
 * en la ruta y lo unico que el panel conoce.
 *
 * El motivo no viaja como parametro porque solo hay uno: `unpaired`. Un motivo
 * libre habria acabado siendo texto tecleado que nadie normaliza, y la revocacion
 * por tablet robada ya tiene su via —`RevokeDeviceToken` desde consola— con su
 * propio motivo.
 */
final readonly class UnpairDeviceCommand
{
    public function __construct(
        public string $deviceUuid,
        public ?int $actorUserId = null,
    ) {}
}
