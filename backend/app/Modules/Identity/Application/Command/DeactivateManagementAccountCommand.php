<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * Baja de una cuenta de gestion (RF-ID-10, RS-05, RL-16), por su `uuid`.
 *
 * La consola resuelve antes el correo que escribe el operador; la API recibe el
 * `uuid` en la ruta. `$actorUuid` es `null` en consola.
 */
final readonly class DeactivateManagementAccountCommand
{
    public function __construct(
        public string $accountUuid,
        public string $reason,
        public ?string $actorUuid = null,
    ) {}
}
