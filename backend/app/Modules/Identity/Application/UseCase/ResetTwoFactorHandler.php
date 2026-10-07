<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\ActorProof;
use App\Modules\Identity\Application\Command\ResetManagementTwoFactorCommand;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\ActorReauthenticationFailed;
use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\ManagementAccountChange;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Identity\Application\Port\TwoFactorSecrets;
use App\Modules\Identity\Application\Support\ActingAccountCheck;
use App\Modules\Identity\Application\Support\ActorReauthentication;
use App\Modules\Identity\Application\Support\ManagementAccountTelemetry;
use App\Modules\Identity\Domain\Event\TwoFactorReset;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Retira el segundo factor de una cuenta de gestion (**RF-ID-10**, **RS-06**;
 * `POST /api/v1/management-accounts/{uuid}/two-factor/reset` e
 * `identity:2fa-reset`).
 *
 * ## Por que existe, y por que tambien por el panel
 *
 * Sin esto, perder el telefono deja a alguien fuera de su cuenta para siempre.
 * Hasta la 2.2.0 solo se podia por consola, con el argumento de que por API seria
 * la forma mas comoda de que un `admin` comprometido se preparara el acceso a la
 * cuenta de otro; el coste real fue que un hotel sin acceso al servidor no podia
 * hacerlo. El propietario decidio el panel (doc 01, Anexo B) y el riesgo se
 * mitiga en lugar de evitarse: **motivo obligatorio, reautenticacion del `admin`
 * que actua, nunca sobre la propia cuenta, nunca junto con la contrasena** (dos
 * acciones, dos asientos), y una alerta de seguridad en cada uso.
 *
 * ## Lo que no retira nada no deja asiento
 *
 * Una cuenta sin segundo factor confirmado responde `NotEnrolled` (`409`) sin
 * escribir nada: un asiento de restablecimiento sin nada restablecido ensuciaria
 * la pregunta que el asiento existe para responder.
 *
 * ## Un caso de uso, una transaccion, y el orden de los candados
 *
 * Retirar el secreto, cerrar **todas** las sesiones y publicar
 * `auth.two_factor_reset` van juntos dentro de `withChainLock` (ADR-010), con la
 * fila bloqueada **despues** del candado de la cadena. `ConfirmTwoFactorHandler`
 * toma el mismo orden: si su titular confirma su TOTP mientras un `admin` se lo
 * retira, uno espera al otro en lugar de abrazarse.
 *
 * Es el punto que conviene endurecer si `seguridad-cumplimiento` lo pide (por
 * ejemplo, impedirlo sobre otra cuenta `admin`): la cuenta objetivo esta en
 * `$account` con sus roles, bajo candado.
 */
final readonly class ResetTwoFactorHandler
{
    public function __construct(
        private ManagementAccountLifecycle $accounts,
        private TwoFactorSecrets $secrets,
        private AccessTokenIssuer $tokens,
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
    public function handle(ResetManagementTwoFactorCommand $command): TwoFactorResetOutcome
    {
        return $this->telemetry->measure(
            ManagementAccountChange::TwoFactorReset,
            $command->accountUuid,
            $command->actorUuid,
            fn (): TwoFactorResetOutcome => $this->reset($command),
            static fn (TwoFactorResetOutcome $outcome): string => $outcome->name,
        );
    }

    private function reset(ResetManagementTwoFactorCommand $command): TwoFactorResetOutcome
    {
        if ($command->actorUuid !== null) {
            $this->reauthentication->confirm($command->actorUuid, $command->proof ?? new ActorProof(null, null));
        }

        $now = $this->clock->now();

        /** @var array{0: TwoFactorResetOutcome, 1: list<UserRole>} $result */
        $result = $this->serialized->withChainLock(function () use ($command, $now): array {
            ActingAccountCheck::assertStillActive($this->accounts, $command->actorUuid);

            $account = $this->accounts->lockAccount($command->accountUuid);

            if ($account === null || ! $account->active) {
                return [TwoFactorResetOutcome::NotFound, []];
            }

            if ($command->actorUuid !== null && $command->actorUuid === $account->uuid) {
                return [TwoFactorResetOutcome::OwnAccount, []];
            }

            if (! $account->twoFactorConfirmed) {
                return [TwoFactorResetOutcome::NotEnrolled, []];
            }

            $this->secrets->forget($account->uuid);

            // Retirar el segundo factor sin echar a quien ya esta dentro no
            // retira nada: en el caso que importa —la sospecha— quien esta dentro
            // lleva una sesion viva de hasta doce horas.
            $this->tokens->revokeAllFor($account->uuid);

            $this->events->publish(new TwoFactorReset($account->uuid, $command->reason, $command->actorUuid, $now));

            return [TwoFactorResetOutcome::Reset, $account->roles];
        });

        [$outcome, $roles] = $result;

        if ($outcome === TwoFactorResetOutcome::Reset) {
            $this->telemetry->count(ManagementAccountChange::TwoFactorReset, $roles);
        }

        return $outcome;
    }
}
