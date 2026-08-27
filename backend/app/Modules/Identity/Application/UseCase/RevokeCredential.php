<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\RevokeCredentialCommand;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Domain\Event\CredentialRevoked;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyRevoked;
use App\Modules\Identity\Domain\Exception\CredentialRevocationNeedsReason;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use Illuminate\Database\ConnectionInterface;

/**
 * Revocacion de una credencial (RF-QR-03).
 *
 * **Nada se borra** (regla dura 5): la fila sigue donde estaba y gana su
 * `revoked_at` y su `revoked_reason`. La credencial revocada es parte del
 * registro —explica por que una persona dejo de poder fichar un martes— y su
 * historial de escaneos se conserva igual.
 *
 * **Una transaccion que incluye el asiento.** Igual que en la emision: si el
 * apunte de `audit_log` falla, la revocacion no se confirma. Lo contrario seria
 * una tarjeta inutilizada sin constancia de quien lo decidio.
 *
 * A partir del `commit`, el siguiente escaneo de esa tarjeta se rechaza con
 * `rejected_revoked` —y, desde fuera, de forma indistinguible de cualquier otro
 * rechazo (RS-03)—.
 */
final readonly class RevokeCredential
{
    public function __construct(
        private CredentialRepository $credentials,
        private EmployeeRegistry $employees,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * Devuelve `null` si la credencial no existe: quien llama lo traduce a 404.
     *
     * @throws CredentialAlreadyRevoked
     * @throws CredentialRevocationNeedsReason
     */
    public function handle(RevokeCredentialCommand $command): ?CredentialView
    {
        $credential = $this->credentials->findByUuid($command->credentialUuid);

        if (! $credential instanceof Credential) {
            return null;
        }

        $revoked = $credential->revoke($command->reason, $this->clock->now());

        // Si la ficha del empleado hubiera desaparecido —no puede: la clave
        // ajena lo impide y nada se borra— la revocacion se hace igual. Una
        // tarjeta que sigue valiendo porque falta un dato accesorio seria peor
        // que un asiento con un hueco.
        $employeeUuid = $this->employees->uuidOf($revoked->employeeId) ?? '';

        return $this->connection->transaction(function () use ($command, $revoked, $employeeUuid): CredentialView {
            $this->credentials->save($revoked);

            $this->events->publish(new CredentialRevoked(
                credentialId: $revoked->id ?? 0,
                credentialUuid: $revoked->uuid,
                employeeUuid: $employeeUuid,
                reason: (string) $revoked->revokedReason,
                actorUserId: $command->actorUserId,
                occurredAt: $revoked->revokedAt ?? $this->clock->now(),
            ));

            return new CredentialView($revoked, $employeeUuid);
        });
    }
}
