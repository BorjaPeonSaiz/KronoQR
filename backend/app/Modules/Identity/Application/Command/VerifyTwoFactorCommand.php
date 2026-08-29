<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * Presentacion de un codigo TOTP contra una sesion pendiente (RS-06).
 *
 * Sirve a los dos endpoints que reciben un codigo —`/auth/2fa/verify` y
 * `/auth/2fa/confirm`—, que se diferencian en el caso de uso que la atiende y no
 * en los datos que necesitan.
 *
 * `throttleKey` la compone la capa HTTP, igual que en el acceso con contrasena:
 * quien sabe de que origen viene una peticion es el transporte.
 *
 * `challengeTokenId` es el token pendiente con el que se llamo, para revocarlo al
 * emitir la sesion de verdad: media autenticacion no puede quedar viva despues de
 * completarse.
 *
 * `deviceName` viene del nombre con el que se abrio la sesion pendiente, que a su
 * vez es el `device_name` del acceso original. Asi la sesion final se lista y se
 * revoca con el nombre que puso el cliente y no con uno inventado a mitad de
 * camino.
 */
final readonly class VerifyTwoFactorCommand
{
    public function __construct(
        public string $userUuid,
        public string $code,
        public string $deviceName,
        public int|string $challengeTokenId,
        public string $throttleKey,
    ) {}
}
