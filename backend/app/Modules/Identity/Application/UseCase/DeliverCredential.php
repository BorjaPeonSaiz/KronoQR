<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\DeliverCredentialCommand;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Support\CredentialTelemetry;
use App\Modules\Identity\Domain\Event\CredentialDelivered;
use App\Modules\Identity\Domain\Event\CredentialRevoked;
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
 *
 * **Y es el acto que cierra el relevo de una rotacion de clave** (RF-QR-07,
 * tarea 2.12): al entregar la tarjeta nueva se revoca la que esa persona tenia
 * firmada con la clave saliente. Ver {@see revokeSupersededCards()}.
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

                $this->revokeSupersededCards($delivered, $employeeUuid, $command->actorUserId);

                return new CredentialView($delivered, $employeeUuid);
            }),
        );
    }

    /**
     * **El relevo de la rotacion se cierra aqui** (RF-QR-07, doc 02 §5.3).
     *
     * Durante un solape una persona llega a tener dos tarjetas escaneables: la
     * que lleva encima, firmada con la clave saliente, y la reemision que
     * `credentials:rotate-key` creo y que acaba de salir de la impresora. Las
     * dos tienen que funcionar mientras la segunda esta en la mesa de RRHH —esa
     * es toda la razon de ser del solape—, pero en el momento en que se entrega
     * la nueva, la vieja sobra: su titular ya no la necesita, y mientras siga
     * activa la clave anterior no se puede retirar.
     *
     * Se revoca **solo lo firmado con otra clave**. Dos tarjetas escaneables con
     * la misma clave no pueden existir —lo impide
     * `one_active_credential_per_key_and_employee`—, asi que esta condicion no
     * puede alcanzar a una tarjeta que no sea la relevada. La pendiente de
     * imprimir tampoco se toca: no esta firmada con ninguna clave y no es de
     * este relevo.
     *
     * **Deja su propio asiento**, uno por tarjeta retirada (regla dura 6): la
     * revocacion es un hecho distinto de la entrega y la respuesta a «por que
     * dejo de funcionar mi tarjeta» tiene que estar escrita, no deducirse de que
     * ese dia hubo una rotacion.
     */
    private function revokeSupersededCards(
        Credential $delivered,
        string $employeeUuid,
        ?int $actorUserId,
    ): void {
        $superseded = $this->credentials->otherActivePrintedForEmployee(
            $delivered->employeeId,
            $delivered->uuid,
        );

        foreach ($superseded as $card) {
            if ($delivered->keyId !== null && $card->signedWithKey($delivered->keyId)) {
                continue;
            }

            $reason = 'Sustituida por la rotacion de la clave de firma '.($card->keyId ?? '');
            $revoked = $card->revoke($reason, $delivered->deliveredAt ?? $this->clock->now());

            $this->credentials->save($revoked);

            $this->events->publish(new CredentialRevoked(
                credentialId: $revoked->id ?? 0,
                credentialUuid: $revoked->uuid,
                employeeUuid: $employeeUuid,
                reason: $reason,
                actorUserId: $actorUserId,
                occurredAt: $revoked->revokedAt ?? $this->clock->now(),
            ));
        }
    }
}
