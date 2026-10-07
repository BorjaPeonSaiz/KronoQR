<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\DeactivateManagementAccountCommand;
use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\ManagementAccountChange;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Identity\Application\Support\ManagementAccountRosterLock;
use App\Modules\Identity\Application\Support\ManagementAccountTelemetry;
use App\Modules\Identity\Domain\Event\ManagementAccountDeactivated;
use App\Modules\Identity\Domain\Policy\DeactivationVerdict;
use App\Modules\Identity\Domain\Policy\ManagementAccountDeactivationGuard;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;

/**
 * Da de baja una cuenta de gestion (**RF-ID-10**, **RS-05**, **RS-06**,
 * **RL-16**; `POST /api/v1/management-accounts/{uuid}/deactivate` e
 * `identity:deactivate-user`).
 *
 * ## Por que existe
 *
 * `users.is_active` se consultaba al autenticar desde la Fase 1, pero nada lo
 * ponia a `false`: retirarle el acceso a quien deja el hotel exigia editar la
 * fila a mano, fuera del producto y del trail (hallazgo H-03 de la revision
 * ASVS de 2026-09). La consola lo resolvio en la 3.8; desde la 2.2.0 tambien el
 * panel, porque un hotel sin acceso al servidor no daba de baja a nadie.
 *
 * ## La invariante, bajo candado
 *
 * Ni la propia cuenta ni la ultima `admin` activa
 * ({@see ManagementAccountDeactivationGuard}). Se decide con el candado del
 * padron de cuentas y la fila bloqueada, en el orden unico del producto —cadena
 * de auditoria, padron, fila—: dos `admin` que se dan de baja el uno al otro a la
 * vez no pueden dejar la instalacion sin ninguno.
 *
 * ## Un caso de uso, una transaccion
 *
 * La baja, la revocacion de los tokens y el asiento van juntos dentro de
 * `withChainLock` (ADR-010). Si la auditoria falla, la cuenta sigue activa. Los
 * accesos de soporte que esa cuenta concedio caen en la misma transaccion: los
 * retira `Product` al recibir el evento.
 *
 * ## Y con la baja se van las sesiones, por dos caminos
 *
 * Se revocan **todos** los tokens de la cuenta, y ademas el callback de Sanctum
 * consulta `is_active` en cada peticion: aunque un token sobreviviera no valdria.
 *
 * ## Lo que NO hace
 *
 * **No borra la cuenta** (regla dura 5), y por eso sigue contando para la guarda
 * de {@see CreateFirstAdministratorHandler}: dar de baja a todo el mundo no
 * reabre la creacion publica de un administrador.
 */
final readonly class DeactivateManagementAccountHandler
{
    public function __construct(
        private ManagementAccountLifecycle $accounts,
        private AccessTokenIssuer $tokens,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
        private ConnectionInterface $connection,
        private ManagementAccountTelemetry $telemetry,
    ) {}

    public function handle(DeactivateManagementAccountCommand $command): AccountDeactivationOutcome
    {
        return $this->telemetry->measure(
            ManagementAccountChange::Deactivated,
            $command->accountUuid,
            $command->actorUuid,
            fn (): AccountDeactivationOutcome => $this->deactivate($command),
            static fn (AccountDeactivationOutcome $outcome): string => $outcome->name,
        );
    }

    private function deactivate(DeactivateManagementAccountCommand $command): AccountDeactivationOutcome
    {
        $now = $this->clock->now();

        /** @var array{0: AccountDeactivationOutcome, 1: list<UserRole>} $result */
        $result = $this->serialized->withChainLock(function () use ($command, $now): array {
            ManagementAccountRosterLock::acquire($this->connection);

            $account = $this->accounts->lockAccount($command->accountUuid);

            if ($account === null) {
                return [AccountDeactivationOutcome::NotFound, []];
            }

            if (! $account->active) {
                return [AccountDeactivationOutcome::AlreadyInactive, []];
            }

            $verdict = ManagementAccountDeactivationGuard::decide(
                $command->actorUuid,
                $account->uuid,
                $account->isActiveAdmin(),
                // Solo se cuenta cuando importa: para una cuenta que no es `admin`
                // el numero no decide nada.
                $account->isActiveAdmin() ? $this->accounts->countActiveAdmins() : 0,
            );

            if ($verdict === DeactivationVerdict::OwnAccount) {
                return [AccountDeactivationOutcome::OwnAccount, []];
            }

            if ($verdict === DeactivationVerdict::LastActiveAdmin) {
                return [AccountDeactivationOutcome::LastActiveAdmin, []];
            }

            $this->accounts->deactivate($account->uuid);
            $this->tokens->revokeAllFor($account->uuid);
            $this->events->publish(new ManagementAccountDeactivated(
                $account->uuid,
                $command->reason,
                $command->actorUuid,
                $now,
            ));

            return [AccountDeactivationOutcome::Deactivated, $account->roles];
        });

        [$outcome, $roles] = $result;

        if ($outcome === AccountDeactivationOutcome::Deactivated) {
            $this->telemetry->count(ManagementAccountChange::Deactivated, $roles);
        }

        return $outcome;
    }
}
