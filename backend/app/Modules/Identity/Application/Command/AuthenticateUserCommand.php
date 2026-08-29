<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

use SensitiveParameter;

/**
 * Peticion de acceso al panel, ya validada por el `FormRequest`.
 *
 * `throttleKey` lo compone la capa HTTP —correo mas origen— y no el caso de
 * uso: quien sabe de que IP viene una peticion es el transporte, y meter la
 * `Request` en el caso de uso lo ataria a HTTP para siempre.
 *
 * **La contrasena viaja marcada como parametro sensible**, igual que el PIN de
 * {@see AuthenticatePortalEmployeeCommand} y el codigo de
 * {@see VerifyTwoFactorCommand}. Sin la marca, una excepcion lanzada en cualquier
 * punto del camino de acceso —un fallo del hash, un error del driver— deja la
 * contrasena en claro dentro de la traza que se escribe en el log tecnico, y ese
 * log viaja al fabricante en el paquete de diagnostico (ADR-020, regla dura 21).
 */
final readonly class AuthenticateUserCommand
{
    public function __construct(
        public string $email,
        #[SensitiveParameter] public string $password,
        public string $deviceName,
        public string $throttleKey,
    ) {}
}
