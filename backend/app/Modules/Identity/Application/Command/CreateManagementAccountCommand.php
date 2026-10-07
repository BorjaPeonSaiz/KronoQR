<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Alta de una cuenta de gestion con contrasena temporal (RF-ID-10).
 *
 * `$actorUuid` y `$proof` van juntos: por la API los dos (quien actua y su
 * reautenticacion); por consola ninguno, porque alli no hay sesion detras y el
 * asiento sale a nombre del sistema.
 */
final readonly class CreateManagementAccountCommand
{
    public function __construct(
        public string $name,
        public string $email,
        public UserRole $role,
        public string $locale,
        public ?string $actorUuid = null,
        public ?ActorProof $proof = null,
    ) {}
}
