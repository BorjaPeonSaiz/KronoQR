<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\ActorProof;
use App\Modules\Identity\Application\Command\ResetManagementPasswordCommand;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\ActorReauthenticationFailed;
use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\ManagementAccountChange;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Identity\Application\Port\PasswordHasher;
use App\Modules\Identity\Application\Port\TemporaryPasswordGenerator;
use App\Modules\Identity\Application\Support\ActingAccountCheck;
use App\Modules\Identity\Application\Support\ActorReauthentication;
use App\Modules\Identity\Application\Support\ManagementAccountTelemetry;
use App\Modules\Identity\Application\Support\TemporaryPasswordSettings;
use App\Modules\Identity\Domain\Event\ManagementPasswordReset;
use App\Modules\Identity\Domain\ValueObject\TemporaryPassword;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Sustituye la contrasena de otra cuenta de gestion por una **temporal**
 * (**RF-ID-10**, **RS-06**, OWASP A07;
 * `POST /api/v1/management-accounts/{uuid}/password/reset` e
 * `identity:reset-password`).
 *
 * ## Por que existe
 *
 * Sin esto, ante una contrasena olvidada o comprometida la unica salida era
 * crear **otra cuenta**, que parte en dos la respuesta a «¿quien corrigio esta
 * jornada?». **No hay recuperacion por correo** (regla dura 12, ADR-015): la
 * contrasena la genera el servidor y se entrega en mano, y caduca: una
 * contrasena que conoce otra persona y no caduca es una credencial compartida.
 *
 * ## Lo que se comprueba y donde
 *
 * Fuera de todo candado: la reautenticacion del `admin` que actua, generar y
 * hashear. Dentro, con la fila bloqueada: que la cuenta exista y este activa
 * (si no, `NotFound`: a una baja no se le devuelve el acceso cambiandole la
 * contrasena) y que no sea la propia (para eso esta el cambio propio, que pide
 * la actual).
 *
 * ## Un caso de uso, una transaccion
 *
 * La contrasena nueva, la revocacion de **todas** las sesiones y el asiento van
 * juntos dentro de `withChainLock` (ADR-010). De los dos motivos para
 * restablecer —olvido y sospecha—, en el que importa quien esta dentro lleva una
 * sesion viva. **El segundo factor no se toca**: retirarlo es otro hecho con su
 * propio asiento.
 */
final readonly class ResetManagementPasswordHandler
{
    public function __construct(
        private ManagementAccountLifecycle $accounts,
        private AccessTokenIssuer $tokens,
        private TemporaryPasswordGenerator $generator,
        private PasswordHasher $hasher,
        private TemporaryPasswordSettings $settings,
        private ActorReauthentication $reauthentication,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
        private ManagementAccountTelemetry $telemetry,
    ) {}

    /**
     * @throws ActorReauthenticationFailed si quien actua no confirma su identidad
     * @throws AccountTemporarilyLocked con el bloqueo de intentos de codigo de quien actua abierto
     */
    public function handle(ResetManagementPasswordCommand $command): ManagementPasswordResetOutcome
    {
        return $this->telemetry->measure(
            ManagementAccountChange::PasswordReset,
            $command->accountUuid,
            $command->actorUuid,
            fn (): ManagementPasswordResetOutcome => $this->reset($command),
            static fn (ManagementPasswordResetOutcome $outcome): string => $outcome->status->name,
        );
    }

    private function reset(ResetManagementPasswordCommand $command): ManagementPasswordResetOutcome
    {
        if ($command->actorUuid !== null) {
            $this->reauthentication->confirm($command->actorUuid, $command->proof ?? new ActorProof(null, null));
        }

        $plain = $this->generator->generate($this->settings->minLength);
        $password = new TemporaryPassword($plain, $this->hasher->hash($plain));
        $issuedAt = $this->clock->now();
        $expiresAt = $this->settings->lifetime->expiresAt($issuedAt);

        /** @var array{0: ManagementPasswordResetStatus, 1: list<UserRole>} $result */
        $result = $this->serialized->withChainLock(
            function () use ($command, $password, $issuedAt, $expiresAt): array {
                ActingAccountCheck::assertStillActive($this->accounts, $command->actorUuid);

                $account = $this->accounts->lockAccount($command->accountUuid);

                if ($account === null || ! $account->active) {
                    return [ManagementPasswordResetStatus::NotFound, []];
                }

                if ($command->actorUuid !== null && $command->actorUuid === $account->uuid) {
                    return [ManagementPasswordResetStatus::OwnAccount, []];
                }

                $this->accounts->replacePasswordHash($account->uuid, $password->hash, $expiresAt);
                $this->tokens->revokeAllFor($account->uuid);
                $this->events->publish(new ManagementPasswordReset(
                    $account->uuid,
                    $command->reason,
                    $command->actorUuid,
                    $issuedAt,
                ));

                return [ManagementPasswordResetStatus::Reset, $account->roles];
            },
        );

        [$status, $roles] = $result;

        if ($status !== ManagementPasswordResetStatus::Reset) {
            return ManagementPasswordResetOutcome::of($status);
        }

        $this->telemetry->count(ManagementAccountChange::PasswordReset, $roles);

        return ManagementPasswordResetOutcome::reset($password, $issuedAt, $expiresAt);
    }
}
