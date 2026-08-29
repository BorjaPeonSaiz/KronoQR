<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\VerifyTwoFactorCommand;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\AuthenticationFailed;
use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\LoginAttempts;
use App\Modules\Identity\Application\Port\TwoFactorAuthenticator;
use App\Modules\Identity\Application\Port\TwoFactorSecrets;
use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;

/**
 * Segundo factor de un acceso pendiente (**RS-06**, RF-ID-01, `POST
 * /api/v1/auth/2fa/verify`).
 *
 * Es la **segunda mitad** de {@see AuthenticateUserHandler}, y por eso termina lo
 * que aquel dejo a medias: emite la sesion de verdad, anota `last_login_at` y
 * escribe `auth.login_succeeded`. Hasta aqui, para el rastro de ADR-039, nadie
 * habia entrado.
 *
 * ## Los cuatro rechazos son uno solo
 *
 * Codigo equivocado, codigo caducado, codigo ya usado y cuenta sin TOTP activo
 * lanzan la misma {@see AuthenticationFailed} y producen el mismo `401`. Quien
 * llega hasta aqui ya ha acertado una contrasena, asi que el riesgo de
 * enumeracion es menor que en el acceso, pero distinguirlos serviria para saber
 * si merece la pena seguir probando —y eso es exactamente lo que un bloqueo por
 * intentos existe para negar.
 *
 * **Y cuestan lo mismo, no solo lo parecen.** La rama «sin TOTP activo» verifica
 * contra {@see self::DECOY_SECRET} en lugar de saltarse la comparacion: paga la
 * misma consulta de la franja y los mismos HMAC de la ventana de tolerancia. Sin
 * el señuelo, un `401` rapido distinguia una cuenta que todavia no ha dado de alta
 * su segundo factor —la mas facil de atacar, porque cualquiera con la contrasena
 * puede darselo— de una que ya lo tiene. `TwoFactorRejectionSymmetryTest` lo fija
 * contando operaciones, no con un cronometro.
 *
 * ## El contador es propio y no el de la contrasena
 *
 * Un espacio de un millon de codigos con ventana de treinta segundos se barre en
 * horas si nadie cuenta los fallos. El contador va por cuenta y origen, igual que
 * el de la contrasena, pero **separado**: gastar el cupo probando codigos no puede
 * dejar a nadie sin poder reintentar su contrasena, ni al reves.
 *
 * ## El reto se consume
 *
 * Al emitir la sesion se revoca el token pendiente. Media autenticacion no puede
 * quedar viva despues de completarse: si quedara, un token de reto robado seguiria
 * sirviendo para pedir otra sesion con el siguiente codigo que la persona teclee.
 */
final readonly class VerifyTwoFactorHandler
{
    /**
     * Secreto señuelo contra el que se verifica cuando la cuenta no tiene ninguno
     * activo.
     *
     * **No es una credencial y no vale en ninguna instalacion**: no esta asignado
     * a ninguna cuenta y ningun autenticador lo tiene. Su unica funcion es que la
     * comparacion se ejecute igual —las mismas franjas, los mismos HMAC— cuando no
     * hay secreto real que comparar. Es lo mismo que `DECOY_HASH` es a la
     * comparacion de bcrypt del PIN.
     *
     * Base32 valido y de la longitud que emite el producto, porque un secreto que
     * la libreria rechazara por su forma volveria por el atajo de la excepcion y
     * no costaria lo mismo.
     */
    private const string DECOY_SECRET = 'K5CVGRKUKZLE6TCPKZDVSVKGKZLE6TCP';

    public function __construct(
        private UserAccounts $accounts,
        private TwoFactorSecrets $secrets,
        private TwoFactorAuthenticator $authenticator,
        private AccessTokenIssuer $tokens,
        private LoginAttempts $attempts,
        private Clock $clock,
        private AuthenticationJournal $journal,
    ) {}

    /**
     * @throws AccountTemporarilyLocked cuando se agotaron los intentos de codigo
     * @throws AuthenticationFailed cuando el codigo no vale, por la causa que sea
     */
    public function handle(VerifyTwoFactorCommand $command): LoginOutcome
    {
        if ($this->attempts->isLocked($command->throttleKey)) {
            $this->journal->failed(AuthChannel::MANAGEMENT, $command->userUuid, AuthFailureReason::LOCKED);

            throw new AccountTemporarilyLocked($this->attempts->secondsUntilUnlock($command->throttleKey));
        }

        $user = $this->accounts->findByUuid($command->userUuid);
        $secret = $this->secrets->activeSecretFor($command->userUuid);

        // EL SEÑUELO, y es lo que hace que las cuatro causas de rechazo cuesten lo
        // mismo. Sin el, la rama «esta cuenta no tiene TOTP activo» se ahorraba una
        // consulta —la de la franja— y todos los HMAC de la ventana de tolerancia,
        // asi que un `401` rapido significaba «aqui no hay segundo factor todavia»
        // y uno lento «lo hay y has fallado». Eso es exactamente lo que el rechazo
        // unico existe para no decir (RS-03, regla dura 17).
        //
        // Mismo criterio y misma forma que {@see HashedEmployeePinVerifier} con su
        // hash señuelo: se paga el trabajo, se descarta el resultado.
        $slice = $this->authenticator->verify(
            $secret ?? self::DECOY_SECRET,
            $command->code,
            $this->secrets->lastAcceptedSliceFor($command->userUuid),
        );

        if ($user === null || $secret === null || $slice === null) {
            $this->attempts->recordFailure($command->throttleKey);
            $this->journal->failed(
                AuthChannel::MANAGEMENT,
                // Aqui el sujeto SI se conoce sin ir a buscarlo: el token pendiente
                // lo trae. No hay oraculo de tiempo que proteger, porque para
                // llegar hasta aqui hubo que acertar la contrasena de esa cuenta.
                $command->userUuid,
                AuthFailureReason::INVALID_CREDENTIALS,
            );
            $this->announceLockoutIfJustOpened($command->throttleKey, $command->userUuid);

            throw new AuthenticationFailed;
        }

        // Antes de emitir: si dos peticiones traen el mismo codigo a la vez, la
        // segunda tiene que encontrarlo ya gastado.
        $this->secrets->rememberAcceptedSlice($command->userUuid, $slice);

        $this->attempts->clear($command->throttleKey);
        $this->accounts->recordSuccessfulLogin($user->uuid, $this->clock->now());

        $outcome = LoginOutcome::session(
            $user,
            $this->tokens->issueFor($user, $command->deviceName),
        );

        // El reto muere despues de que exista la sesion, no antes: si la emision
        // fallara, quien esta autenticandose se quedaria sin las dos mitades.
        $this->tokens->revoke($command->challengeTokenId);

        $this->journal->succeeded(AuthChannel::MANAGEMENT, $user->uuid);

        return $outcome;
    }

    /**
     * El asiento del bloqueo, **en el flanco**: solo el fallo que lo abre.
     *
     * Mismo criterio y misma trampa que en `AuthenticateUserHandler`: se pregunta
     * `isLocked()` y no `secondsUntilUnlock() > 0`, porque lo segundo es la
     * ventana del contador y devuelve un numero mayor que cero desde el primer
     * fallo.
     */
    private function announceLockoutIfJustOpened(string $throttleKey, string $userUuid): void
    {
        if (! $this->attempts->isLocked($throttleKey)) {
            return;
        }

        $this->journal->lockoutStarted(
            AuthChannel::MANAGEMENT,
            $userUuid,
            $this->attempts->secondsUntilUnlock($throttleKey),
        );
    }
}
