<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\DeliverCredentialCommand;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Support\CredentialTelemetry;
use App\Modules\Identity\Domain\Event\CredentialDelivered;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyDelivered;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyRevoked;
use App\Modules\Identity\Domain\Exception\CredentialNotPrintedYet;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use Illuminate\Database\ConnectionInterface;

/**
 * Registrar la entrega de una tarjeta, con fecha y responsable (RF-QR-06).
 *
 * **Por que se registra.** El doc 02 §5.5: *«es lo que distingue "la tarjeta se
 * perdio antes de darsela" de "el empleado la perdio", que son incidencias
 * distintas»*. Sin este dato, las dos situaciones se ven igual desde la base de
 * datos y la segunda tiene consecuencias para una persona que la primera no
 * tiene. Es tambien lo que distingue el riesgo residual de ADR-034 —el PDF que se
 * perdio antes de llegar a la impresora— de una tarjeta que si se entrego.
 *
 * **Una transaccion que incluye el asiento.** Igual que la emision y la
 * revocacion: si el apunte de `audit_log` falla, la entrega no se confirma
 * (ADR-027). Una entrega sin constancia de quien la hizo no sirve para lo unico
 * para lo que existe.
 *
 * **No es idempotente, y es deliberado.** Marcar dos veces la misma entrega
 * responde `409`. Sobrescribirla cambiaria el responsable y el momento que ya
 * constan en la auditoria, que es exactamente lo que la regla dura 5 prohibe.
 */
final readonly class DeliverCredential
{
    public function __construct(
        private CredentialRepository $credentials,
        private EmployeeRegistry $employees,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        private CredentialTelemetry $telemetry,
    ) {}

    /**
     * Devuelve `null` si la credencial no existe: quien llama lo traduce a `404`.
     *
     * @throws CredentialNotPrintedYet antes de imprimir no hay tarjeta que entregar
     * @throws CredentialAlreadyDelivered
     * @throws CredentialAlreadyRevoked
     */
    public function handle(DeliverCredentialCommand $command): ?CredentialView
    {
        $credential = $this->credentials->findByUuid($command->credentialUuid);

        if (! $credential instanceof Credential) {
            return null;
        }

        // El agregado decide: sin imprimir no hay tarjeta, dos veces no, revocada
        // tampoco. Las tres son reglas de negocio y viven en `Credential`.
        $delivered = $credential->deliveredBy($command->deliveredByUserId, $this->clock->now());

        $employeeUuid = $this->employees->uuidOf($delivered->employeeId) ?? '';

        return $this->telemetry->measure(
            'identity.credential_deliver',
            [
                'credential_uuid' => $delivered->uuid,
                'employee_uuid' => $employeeUuid,
                'delivered_by_user_id' => $command->deliveredByUserId,
            ],
            fn (): CredentialView => $this->connection->transaction(function () use (
                $command,
                $delivered,
                $employeeUuid,
            ): CredentialView {
                if (! $this->credentials->markDelivered($delivered)) {
                    // Cero filas afectadas: entre la lectura y este `UPDATE` otra
                    // persona de RRHH registro la misma entrega, o alguien revoco
                    // la credencial. El primero que llego se queda, porque su
                    // nombre ya esta en `audit_log`.
                    throw CredentialAlreadyDelivered::forCredential($delivered->uuid);
                }

                $this->events->publish(new CredentialDelivered(
                    credentialId: $delivered->id ?? 0,
                    credentialUuid: $delivered->uuid,
                    employeeUuid: $employeeUuid,
                    deliveredByUserId: $command->deliveredByUserId,
                    actorUserId: $command->actorUserId,
                    occurredAt: $delivered->deliveredAt ?? $this->clock->now(),
                ));

                return new CredentialView($delivered, $employeeUuid);
            }),
        );
    }
}
