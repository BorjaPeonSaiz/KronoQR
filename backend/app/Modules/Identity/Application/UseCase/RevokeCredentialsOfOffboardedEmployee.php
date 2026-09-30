<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Support\CredentialTelemetry;
use App\Modules\Identity\Domain\Event\CredentialRevoked;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use App\Modules\Shared\Application\Port\PortalSessionIssuer;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * **Una baja deja sin credencial a quien se va** (RN-14, RF-GP-03, N1).
 *
 * Dar de baja a una persona ya le impedia fichar —el escaneo mira el estado
 * laboral—, pero su tarjeta seguia «activa» en el panel, en la rotacion de la
 * clave de firma y en el recuento de tarjetas por reimprimir, y su sesion del
 * portal seguia abierta hasta caducar. El contrato afirmaba desde la Fase 2
 * que la baja revoca la credencial; nadie escuchaba el evento.
 *
 * ## Que hace
 *
 * - Revoca **todas** las credenciales activas de la persona —la que lleva en
 *   la mano y, si la hay, la reemision pendiente de imprimir—, cada una con su
 *   `CredentialRevoked` y por tanto con su asiento `credential.revoked` en
 *   `audit_log` (regla dura 6). Nada se borra (regla dura 5): la fila gana
 *   `revoked_at` y `revoked_reason`.
 * - Cierra sus sesiones del portal. El acceso nuevo ya lo niega el propio
 *   inicio de sesion (empleado no en alta); esto cubre la sesion que ya estaba
 *   abierta.
 *
 * ## Idempotente
 *
 * Solo toca lo que sigue activo. Si el evento llegara dos veces, la segunda
 * pasada no encuentra nada que revocar y no escribe ningun asiento.
 *
 * ## En la transaccion de la baja
 *
 * Lo invoca el listener sincrono de `EmployeeOffboarded`, dentro de la
 * transaccion de `OffboardEmployeeHandler`: si la revocacion o su asiento
 * fallan, la baja no se confirma. `transaction()` anidada es un punto de
 * guardado y no abre otra transaccion.
 *
 * **Sin actor humano en el asiento de la revocacion**: la decision fue la baja,
 * y el asiento de la baja (`employee.offboarded`) es el que lleva a la persona
 * que la firmo. El motivo de la revocacion dice de donde viene.
 */
final readonly class RevokeCredentialsOfOffboardedEmployee
{
    /** El motivo que queda en `credentials.revoked_reason` y en el asiento. */
    public const string REASON = 'Baja del empleado (RN-14)';

    /**
     * Techo defensivo del bucle: una persona tiene como mucho la tarjeta en uso
     * y una reemision pendiente. Si `save()` dejara de persistir, el bucle no
     * puede girar para siempre dentro de la transaccion de una baja.
     */
    private const int MAXIMUM_ACTIVE_CREDENTIALS = 10;

    public function __construct(
        private CredentialRepository $credentials,
        private EmployeeRegistry $employees,
        private PortalSessionIssuer $portalSessions,
        private IdentityEventPublisher $events,
        private ConnectionInterface $connection,
        private CredentialTelemetry $telemetry,
    ) {}

    /**
     * @return int cuantas credenciales se han revocado
     */
    public function handle(string $employeeUuid, DateTimeImmutable $offboardedAt): int
    {
        $employeeId = $this->employees->internalIdFor($employeeUuid);

        if ($employeeId === null) {
            return 0;
        }

        // Span y log propios (doc 02 §8.1): `employee_uuid`, nunca el nombre
        // (regla dura 21).
        return $this->telemetry->measure(
            'identity.credentials_revoked_on_offboarding',
            ['employee_uuid' => $employeeUuid],
            fn (): int => $this->revokeAll($employeeId, $employeeUuid, $offboardedAt),
        );
    }

    private function revokeAll(int $employeeId, string $employeeUuid, DateTimeImmutable $offboardedAt): int
    {
        return $this->connection->transaction(function () use ($employeeId, $employeeUuid, $offboardedAt): int {
            $revoked = 0;

            while ($revoked < self::MAXIMUM_ACTIVE_CREDENTIALS) {
                $credential = $this->credentials->activeForEmployee($employeeId);

                if (! $credential instanceof Credential) {
                    break;
                }

                $this->revoke($credential, $employeeUuid, $offboardedAt);
                $revoked++;
            }

            $this->portalSessions->revokeAllFor($employeeUuid);

            return $revoked;
        });
    }

    private function revoke(Credential $credential, string $employeeUuid, DateTimeImmutable $offboardedAt): void
    {
        $revoked = $credential->revoke(self::REASON, $offboardedAt);

        $this->credentials->save($revoked);

        $this->events->publish(new CredentialRevoked(
            credentialId: $revoked->id ?? 0,
            credentialUuid: $revoked->uuid,
            employeeUuid: $employeeUuid,
            reason: self::REASON,
            actorUserId: null,
            occurredAt: $revoked->revokedAt ?? $offboardedAt,
        ));
    }
}
