<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Lo que un caso de uso del ciclo de vida necesita saber de una cuenta de
 * gestion **mientras tiene su fila bloqueada** (RF-ID-10).
 *
 * Un DTO y no una entidad: no protege ninguna invariante, solo transporta los
 * cuatro hechos con los que se decide —si existe, si esta activa, que roles
 * tiene y si su segundo factor esta confirmado—. **Sin nombre, sin correo y sin
 * ningun secreto**: nada de eso decide nada aqui.
 */
final readonly class AccountSnapshot
{
    /**
     * @param  list<UserRole>  $roles
     */
    public function __construct(
        public string $uuid,
        public bool $active,
        public array $roles,
        public bool $twoFactorConfirmed,
    ) {}

    public function isActiveAdmin(): bool
    {
        return $this->active && \in_array(UserRole::ADMIN, $this->roles, true);
    }
}
