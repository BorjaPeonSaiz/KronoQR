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
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;

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
 *
 * ## Los tres desenlaces dejan rastro, y ninguno lo dice hacia fuera
 *
 * OWASP A09. Hasta que existio {@see AuthenticationJournal}, un ataque de
 * credenciales contra el panel no dejaba nada consultable: el `401` es generico
 * y no habia ni asiento ni contador. Que hecho va a `audit_log` y cual se queda
 * en el log y el contador lo decide
 * `docs/adr/ADR-039-que-hechos-de-autenticacion-dejan-asiento.md`, no este
 * fichero: aqui solo se describe lo que paso.
 *
 * **El fallo no identifica a nadie, y no es una carencia.** `verifyCredentials`
 * devuelve un solo `null` para «ese correo no existe», «esa contrasena no es» y
 * «esa cuenta esta desactivada», asi que aqui no hay a quien nombrar sin ir a
 * buscarlo — y una consulta que solo ocurriera cuando la cuenta existe seria un
 * oraculo medible con un cronometro (RS-03). Por lo mismo, el bloqueo se apunta
 * sin sujeto: su contador es por «correo mas origen», no por cuenta.
 */
final readonly class AuthenticateUserHandler
{
    public function __construct(
        private UserAccounts $accounts,
        private AccessTokenIssuer $tokens,
        private LoginAttempts $attempts,
        private Clock $clock,
        private AuthenticationJournal $journal,
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
            $this->journal->failed(AuthChannel::MANAGEMENT, null, AuthFailureReason::LOCKED);

            throw new AccountTemporarilyLocked($this->attempts->secondsUntilUnlock($command->throttleKey));
        }

        $user = $this->accounts->verifyCredentials($command->email, $command->password);

        if ($user === null) {
            $this->attempts->recordFailure($command->throttleKey);
            $this->journal->failed(AuthChannel::MANAGEMENT, null, AuthFailureReason::INVALID_CREDENTIALS);
            $this->announceLockoutIfJustOpened($command->throttleKey);

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

        $session = [
            'user' => $user,
            'token' => $this->tokens->issueFor($user, $command->deviceName),
        ];

        // Despues de emitir, no antes: si la emision falla, no ha entrado nadie
        // y el asiento diria lo contrario.
        $this->journal->succeeded(AuthChannel::MANAGEMENT, $user->uuid);

        return $session;
    }

    /**
     * El asiento del bloqueo, **en el flanco**: solo el fallo que lo abre.
     *
     * Se sabe que es el flanco porque arriba, al empezar, la clave no estaba
     * bloqueada: si lo hubiera estado, esta linea no se alcanza. Sin esa
     * comprobacion previa habria un asiento por cada intento posterior, y la
     * cadena de hash acabaria llena de la insistencia de quien ataca.
     *
     * **La pregunta es `isLocked()`, y no `secondsUntilUnlock() > 0`.** Aqui no
     * son la misma pregunta, aunque en `Shared\Application\Port\PinAttempts` si lo
     * sean —nombrado en prosa porque una referencia resoluble seria una
     * dependencia entre modulos que el §1.6 no concede—:
     * {@see LoginAttempts::secondsUntilUnlock()} devuelve lo que queda de la
     * **ventana del contador** —el `availableIn()` del limitador—, que es distinto
     * de cero desde el primer fallo. Decidir el flanco con ese numero dejaria un
     * asiento de bloqueo por cada contrasena equivocada.
     */
    private function announceLockoutIfJustOpened(string $throttleKey): void
    {
        if (! $this->attempts->isLocked($throttleKey)) {
            return;
        }

        $this->journal->lockoutStarted(
            AuthChannel::MANAGEMENT,
            // Sin sujeto a proposito: ver el docblock de la clase.
            null,
            $this->attempts->secondsUntilUnlock($throttleKey),
        );
    }
}
