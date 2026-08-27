<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * Peticion de acceso al panel, ya validada por el `FormRequest`.
 *
 * `throttleKey` lo compone la capa HTTP —correo mas origen— y no el caso de
 * uso: quien sabe de que IP viene una peticion es el transporte, y meter la
 * `Request` en el caso de uso lo ataria a HTTP para siempre.
 */
final readonly class AuthenticateUserCommand
{
    public function __construct(
        public string $email,
        public string $password,
        public string $deviceName,
        public string $throttleKey,
    ) {}
}
