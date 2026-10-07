<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\ChangeOwnPasswordCommand;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\ManagementSessionVanished;
use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\LoginAttempts;
use App\Modules\Identity\Application\Port\ManagementAccountChange;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Identity\Application\Port\PasswordHasher;
use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Application\Support\ManagementAccountTelemetry;
use App\Modules\Identity\Domain\Event\ManagementPasswordChanged;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\PasswordStatus;
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Cambio de la contrasena **propia** (**RF-ID-10**, RF-ID-01;
 * `POST /api/v1/auth/password`).
 *
 * ## Es la salida de la contrasena temporal
 *
 * Una sesion abierta con una temporal lleva el unico ambito `password:change`
 * (ADR-051). Al cambiarla, **en la misma transaccion**, ese token recibe los
 * ambitos del rol: no hace falta volver a entrar.
 *
 * ## La actual es obligatoria aunque la sesion este abierta
 *
 * Una sesion robada no debe bastar para quedarse con la cuenta. Los fallos
 * cuentan en un **contador propio** (`password-change|<uuid>`): el del acceso se
 * lleva por correo y origen, y mezclarlos dejaria a quien tiene la sesion
 * agotar el cupo del titular legitimo. Al abrirse el bloqueo queda
 * `auth.lockout_started` y **se revoca el token con el que se probo**: quien
 * prueba contrasenas con una sesion robada la pierde. Los fallos sueltos solo
 * dejan log tecnico, sin asiento (ADR-039). Una temporal **caducada** no vale
 * como actual: esa sesion ya no sirve (`SessionGone`).
 *
 * ## Lo caro fuera; la escritura, condicionada
 *
 * Comparar la actual y hashear la nueva cuesta decenas de milisegundos y se
 * hace **fuera** del candado de la cadena. Dentro, con la fila bloqueada, se
 * comprueba que el hash leido sigue siendo el vigente —si un `admin` la
 * restablecio entretanto, `ChangedMeanwhile` y nada cambia— y que el token de
 * esta sesion sigue existiendo —si no, se deshace todo y `SessionGone`—.
 *
 * ## Un caso de uso, una transaccion
 *
 * Contrasena nueva, cierre de las **demas** sesiones, ambitos del rol para la
 * actual y asiento `user.password_changed`, juntos (ADR-010). El contador se
 * limpia **despues**, como en el PIN.
 */
final readonly class ChangeOwnPasswordHandler
{
    public function __construct(
        private UserAccounts $users,
        private ManagementAccountLifecycle $accounts,
        private PasswordHasher $hasher,
        private LoginAttempts $attempts,
        private AccessTokenIssuer $tokens,
        private AuthenticationJournal $journal,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
        private ManagementAccountTelemetry $telemetry,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AccountTemporarilyLocked con el bloqueo de intentos de esta cuenta abierto
     */
    public function handle(ChangeOwnPasswordCommand $command): ChangeOwnPasswordOutcome
    {
        return $this->telemetry->measure(
            ManagementAccountChange::PasswordChanged,
            $command->accountUuid,
            $command->accountUuid,
            fn (): ChangeOwnPasswordOutcome => $this->change($command),
            static fn (ChangeOwnPasswordOutcome $outcome): string => $outcome->name,
        );
    }

    private function change(ChangeOwnPasswordCommand $command): ChangeOwnPasswordOutcome
    {
        $key = 'password-change|'.$command->accountUuid;

        if ($this->attempts->isLocked($key)) {
            throw new AccountTemporarilyLocked($this->attempts->secondsUntilUnlock($key));
        }

        $user = $this->users->findByUuid($command->accountUuid);
        $storedHash = $this->accounts->currentPasswordHash($command->accountUuid);

        if (! $user instanceof AuthenticatedUser || $storedHash === null
            || $user->passwordStatus === PasswordStatus::TemporaryExpired) {
            return ChangeOwnPasswordOutcome::SessionGone;
        }

        if (! $this->hasher->matches($command->currentPassword, $storedHash)) {
            return $this->wrongCurrentPassword($command, $key);
        }

        if ($command->newPassword === $command->currentPassword) {
            return ChangeOwnPasswordOutcome::SameAsCurrent;
        }

        $newHash = $this->hasher->hash($command->newPassword);
        $now = $this->clock->now();

        try {
            $outcome = $this->serialized->withChainLock(
                fn (): ChangeOwnPasswordOutcome => $this->write($command, $user, $storedHash, $newHash, $now),
            );
        } catch (ManagementSessionVanished) {
            return ChangeOwnPasswordOutcome::SessionGone;
        }

        if ($outcome === ChangeOwnPasswordOutcome::Changed) {
            $this->attempts->clear($key);
            $this->telemetry->count(ManagementAccountChange::PasswordChanged, $user->roles);
        }

        return $outcome;
    }

    private function write(
        ChangeOwnPasswordCommand $command,
        AuthenticatedUser $user,
        string $storedHash,
        string $newHash,
        DateTimeImmutable $now,
    ): ChangeOwnPasswordOutcome {
        $account = $this->accounts->lockAccount($user->uuid);

        if ($account === null || ! $account->active) {
            return ChangeOwnPasswordOutcome::SessionGone;
        }

        // Escritura condicionada: el hash con el que se comparo la actual tiene
        // que seguir siendo el vigente. Un restablecimiento cruzado no se pisa.
        if ($this->accounts->currentPasswordHash($user->uuid) !== $storedHash) {
            return ChangeOwnPasswordOutcome::ChangedMeanwhile;
        }

        $this->accounts->replacePasswordHash($user->uuid, $newHash, null);
        $this->tokens->revokeAllExcept($user->uuid, $command->currentTokenId);

        if (! $this->tokens->promoteToFullSession($user, $command->currentTokenId)) {
            // El token de esta sesion ya no existe: se deshace la transaccion
            // entera, contrasena incluida, en vez de dejar una contrasena nueva
            // fijada desde una sesion que ya estaba cerrada.
            throw new ManagementSessionVanished;
        }

        $this->events->publish(new ManagementPasswordChanged($user->uuid, $now));

        return ChangeOwnPasswordOutcome::Changed;
    }

    private function wrongCurrentPassword(ChangeOwnPasswordCommand $command, string $key): ChangeOwnPasswordOutcome
    {
        $this->attempts->recordFailure($key);

        // Log tecnico y no asiento (ADR-039): un fallo suelto no es un hecho con
        // relevancia legal. Solo el `uuid`, nunca el nombre ni el correo.
        $this->logger->warning('identity.password_change.failed', ['user_uuid' => $command->accountUuid]);

        if (! $this->attempts->isLocked($key)) {
            return ChangeOwnPasswordOutcome::WrongCurrentPassword;
        }

        $seconds = $this->attempts->secondsUntilUnlock($key);

        $this->journal->lockoutStarted(AuthChannel::MANAGEMENT, $command->accountUuid, $seconds);

        // Quien prueba contrasenas con una sesion robada la pierde.
        $this->tokens->revoke($command->currentTokenId);

        throw new AccountTemporarilyLocked($seconds);
    }
}
