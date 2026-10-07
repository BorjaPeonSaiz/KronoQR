<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * Restablecimiento de la contrasena de otra cuenta de gestion (RF-ID-10,
 * RS-06), con motivo obligatorio y, por la API, reautenticacion de quien actua.
 */
final readonly class ResetManagementPasswordCommand
{
    public function __construct(
        public string $accountUuid,
        public string $reason,
        public ?string $actorUuid = null,
        public ?ActorProof $proof = null,
    ) {}
}
