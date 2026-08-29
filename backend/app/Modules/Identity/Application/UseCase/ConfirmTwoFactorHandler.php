<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\VerifyTwoFactorCommand;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\AuthenticationFailed;
use App\Modules\Identity\Application\Exception\TwoFactorAlreadyEnabled;
use App\Modules\Identity\Application\Exception\TwoFactorNotEnrolled;
use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\LoginAttempts;
use App\Modules\Identity\Application\Port\TwoFactorAuthenticator;
use App\Modules\Identity\Application\Port\TwoFactorSecrets;
use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Domain\Event\TwoFactorEnabled;
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;
use Illuminate\Database\ConnectionInterface;

/**
 * Activacion del segundo factor con el primer codigo del autenticador (**RS-06**,
 * `POST /api/v1/auth/2fa/confirm`).
 *
 * ## Un caso de uso, una transaccion
 *
 * Confirmar el secreto y publicar `auth.two_factor_enabled` ocurren **dentro de la
 * misma transaccion** (ADR-027): el listener de auditoria es sincrono, asi que si
 * el asiento falla, la activacion no se confirma. Una credencial de acceso activa
 * sin traza es peor que una no activada, porque la segunda se repite y la primera
 * no se descubre.
 *
 * La emision de la sesion se hace **fuera** de esa transaccion, despues de
 * confirmarla: un token emitido dentro de una transaccion que despues se deshace
 * seria una sesion viva de una activacion que no ocurrio.
 *
 * ## Por que emite sesion y no obliga a volver a entrar
 *
 * Quien acaba de activar su TOTP ya presento su contrasena hace segundos y acaba
 * de demostrar que tiene el telefono: pedirle que empiece de nuevo no anade
 * ninguna garantia y multiplica las llamadas a soporte del primer dia. La sesion
 * que sale de aqui es identica a la de `/auth/2fa/verify`.
 *
 * ## Confirmar comparte el contador de la verificacion
 *
 * Y tiene que compartirlo: si no, bastaria con alternar `/confirm` y `/verify`
 * para tener el doble de intentos. La orden es la misma
 * ({@see VerifyTwoFactorCommand}) y la clave del contador la compone la capa HTTP
 * igual para los dos.
 */
final readonly class ConfirmTwoFactorHandler
{
    public function __construct(
        private UserAccounts $accounts,
        private TwoFactorSecrets $secrets,
        private TwoFactorAuthenticator $authenticator,
        private AccessTokenIssuer $tokens,
        private LoginAttempts $attempts,
        private Clock $clock,
        private AuthenticationJournal $journal,
        private IdentityEventPublisher $events,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws AccountTemporarilyLocked cuando se agotaron los intentos de codigo
     * @throws AuthenticationFailed cuando el codigo no vale
     * @throws TwoFactorAlreadyEnabled cuando la cuenta ya tenia el segundo factor activo
     * @throws TwoFactorNotEnrolled cuando no hay ningun alta pendiente que confirmar
     */
    public function handle(VerifyTwoFactorCommand $command): LoginOutcome
    {
        if ($this->attempts->isLocked($command->throttleKey)) {
            $this->journal->failed(AuthChannel::MANAGEMENT, $command->userUuid, AuthFailureReason::LOCKED);

            throw new AccountTemporarilyLocked($this->attempts->secondsUntilUnlock($command->throttleKey));
        }

        $user = $this->accounts->findByUuid($command->userUuid);

        if ($user === null) {
            throw new AuthenticationFailed;
        }

        if ($this->secrets->activeSecretFor($command->userUuid) !== null) {
            throw new TwoFactorAlreadyEnabled;
        }

        $secret = $this->secrets->unconfirmedSecretFor($command->userUuid);

        if ($secret === null) {
            throw new TwoFactorNotEnrolled;
        }

        // Sin franja previa: un alta nueva no arrastra la del secreto anterior,
        // que se calculaba con otra clave y por tanto no dice nada de esta.
        $slice = $this->authenticator->verify($secret, $command->code, null);

        if ($slice === null) {
            $this->attempts->recordFailure($command->throttleKey);
            $this->journal->failed(
                AuthChannel::MANAGEMENT,
                $command->userUuid,
                AuthFailureReason::INVALID_CREDENTIALS,
            );
            $this->announceLockoutIfJustOpened($command->throttleKey, $command->userUuid);

            throw new AuthenticationFailed;
        }

        $now = $this->clock->now();

        $this->connection->transaction(function () use ($command, $slice, $now): void {
            $this->secrets->confirm($command->userUuid, $now);
            $this->secrets->rememberAcceptedSlice($command->userUuid, $slice);

            // Dentro de la transaccion a proposito: el listener de auditoria es
            // sincrono y su fallo tiene que deshacer la activacion (ADR-027).
            $this->events->publish(new TwoFactorEnabled($command->userUuid, $now));
        });

        $this->attempts->clear($command->throttleKey);
        $this->accounts->recordSuccessfulLogin($user->uuid, $now);

        $outcome = LoginOutcome::session(
            $user,
            $this->tokens->issueFor($user, $command->deviceName),
        );

        $this->tokens->revoke($command->challengeTokenId);

        $this->journal->succeeded(AuthChannel::MANAGEMENT, $user->uuid);

        return $outcome;
    }

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
