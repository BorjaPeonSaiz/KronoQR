<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\AuthenticateUserCommand;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\AuthenticationFailed;
use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\LoginAttempts;
use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Acceso al panel de gestion (RF-ID-01): comprueba credenciales, aplica el
 * bloqueo por intentos y emite el token con los ambitos del rol.
 *
 * **Sin segundo factor.** El 2FA obligatorio de RF-ID-01 completo es de la
 * tarea 2.1 (Anexo A del doc 01) y aqui no se anticipa. El punto donde encajara
 * esta marcado abajo: entre la verificacion de la contrasena y la emision del
 * token, devolviendo una sesion pendiente de verificar en lugar del token.
 *
 * **El orden de los pasos no es casual.** El bloqueo se consulta antes de mirar
 * la contrasena para que una cuenta bloqueada responda igual con la contrasena
 * correcta que con la incorrecta; si no, el bloqueo confirmaria los aciertos.
 */
final readonly class AuthenticateUserHandler
{
    public function __construct(
        private UserAccounts $accounts,
        private AccessTokenIssuer $tokens,
        private LoginAttempts $attempts,
        private Clock $clock,
    ) {}

    /**
     * @return array{user: AuthenticatedUser, token: IssuedAccessToken}
     *
     * @throws AccountTemporarilyLocked cuando la clave esta bloqueada por fallos previos
     * @throws AuthenticationFailed cuando las credenciales no valen, por la causa que sea
     */
    public function handle(AuthenticateUserCommand $command): array
    {
        if ($this->attempts->isLocked($command->throttleKey)) {
            throw new AccountTemporarilyLocked($this->attempts->secondsUntilUnlock($command->throttleKey));
        }

        $user = $this->accounts->verifyCredentials($command->email, $command->password);

        if ($user === null) {
            $this->attempts->recordFailure($command->throttleKey);

            throw new AuthenticationFailed;
        }

        // ---------------------------------------------------------------
        // Tarea 2.1 (RF-ID-01 completo): aqui va el segundo factor. Si la
        // cuenta tiene TOTP activo, este metodo devolvera una sesion pendiente
        // de verificar en lugar de un token utilizable, y `POST
        // /api/v1/auth/2fa/verify` sera quien llame a issueFor(). Ningun otro
        // punto de este caso de uso cambia.
        // ---------------------------------------------------------------

        $this->attempts->clear($command->throttleKey);
        $this->accounts->recordSuccessfulLogin($user->uuid, $this->clock->now());

        return [
            'user' => $user,
            'token' => $this->tokens->issueFor($user, $command->deviceName),
        ];
    }
}
