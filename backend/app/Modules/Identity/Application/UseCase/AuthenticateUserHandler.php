<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\AuthenticateUserCommand;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\AuthenticationFailed;
use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\LoginAttempts;
use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Domain\Policy\TwoFactorRequirement;
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;

/**
 * Acceso al panel de gestion (RF-ID-01): comprueba credenciales, aplica el
 * bloqueo por intentos y emite el token con los ambitos del rol.
 *
 * **Con segundo factor** (RS-06, tarea 2.1). Entre la comprobacion de la
 * contrasena y la emision del token hay ahora una bifurcacion: si la cuenta debe
 * llevar TOTP —por su rol o porque ya lo activo—, este caso de uso devuelve una
 * **sesion pendiente** en lugar de una sesion, y quien emite la de verdad es
 * {@see VerifyTwoFactorHandler}. Todo lo demas —el orden de los pasos, el bloqueo,
 * el rastro— sigue igual.
 *
 * **Lo que se mueve con la bifurcacion, y por que.** `auth.login_succeeded`,
 * `last_login_at` y el borrado del contador de intentos **no** ocurren aqui cuando
 * hay reto: quien ha acertado la contrasena todavia no ha entrado, y un asiento de
 * acceso en ese punto diria en `audit_log` que alguien entro cuando lo que hizo
 * fue quedarse a medias. Los tres los hace el verificador. El contador de fallos
 * de contrasena, en cambio, **si se limpia** al acertarla: lo que se cuenta ahi
 * son contrasenas, y el segundo factor tiene su propio contador.
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
        private TwoFactorRequirement $secondFactor,
    ) {}

    /**
     * @throws AccountTemporarilyLocked cuando la clave esta bloqueada por fallos previos
     * @throws AuthenticationFailed cuando las credenciales no valen, por la causa que sea
     */
    public function handle(AuthenticateUserCommand $command): LoginOutcome
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

        // La contrasena era buena: el contador de contrasenas se limpia aqui,
        // haya reto o no. Lo contrario dejaria a quien acierta a la quinta con el
        // cupo gastado para el resto de la ventana.
        $this->attempts->clear($command->throttleKey);

        if ($this->secondFactor->challenges($user->roles, $user->secondFactorActive)) {
            // Media autenticacion. Ni asiento de acceso, ni `last_login_at`: no
            // ha entrado nadie todavia (RS-06, ADR-039).
            return LoginOutcome::challenge(
                $user,
                $this->tokens->issuePendingFor($user, $command->deviceName),
                $this->secondFactor->enrolmentRequired($user->roles, $user->secondFactorActive),
            );
        }

        $this->accounts->recordSuccessfulLogin($user->uuid, $this->clock->now());

        $outcome = LoginOutcome::session(
            $user,
            $this->tokens->issueFor($user, $command->deviceName),
        );

        // Despues de emitir, no antes: si la emision falla, no ha entrado nadie
        // y el asiento diria lo contrario.
        $this->journal->succeeded(AuthChannel::MANAGEMENT, $user->uuid);

        return $outcome;
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
