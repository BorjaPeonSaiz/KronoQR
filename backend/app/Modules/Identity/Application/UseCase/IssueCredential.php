<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\IssueCredentialCommand;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Domain\Event\CredentialIssued;
use App\Modules\Identity\Domain\Event\CredentialRevoked;
use App\Modules\Identity\Domain\Exception\CredentialRevocationNeedsReason;
use App\Modules\Identity\Domain\Exception\EmployeeAlreadyHasCredential;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Emision y reemision de una credencial (RF-QR-01, RF-QR-03).
 *
 * **Un caso de uso, una transaccion.** Revocar la anterior, insertar la nueva y
 * sellar los dos asientos de `audit_log` son un solo hecho: si algo falla a
 * mitad, el empleado se quedaria sin tarjeta valida —revocada la vieja, no
 * escrita la nueva— o con dos. Los eventos se publican **dentro** de la
 * transaccion a proposito, al contrario que en `Workforce`: el listener de
 * auditoria escribe en `audit_log` y ADR-027 exige que, si ese asiento falla, la
 * accion auditada no se confirme. Una credencial emitida sin rastro es peor que
 * una credencial no emitida, porque la segunda se repite.
 *
 * **Aqui no se acuña ningun token** (ADR-034). Emitir crea el derecho a una
 * tarjeta y la deja **pendiente de imprimir**; el token, su firma y su hash
 * nacen en la impresion, dentro del mismo acto que dibuja el PDF, para que el
 * unico sitio por el que el payload sale de este sistema sea la tarjeta. La
 * consecuencia practica: esta transaccion no maneja ningun secreto, su respuesta
 * no lleva nada que permita fichar por nadie, y una credencial emitida hace tres
 * semanas se puede imprimir hoy —que es lo que `credentials:print-batch
 * --pending` necesita— sin guardar nada reversible.
 *
 * **Lo que este caso de uso NO hace.** No comprueba si el empleado esta de baja:
 * RN-14 es de la Fase 2 y quien decide sobre el estado laboral es `Workforce`.
 * Tampoco imprime ni entrega: eso es la tarea 1.10.
 */
final readonly class IssueCredential
{
    public function __construct(
        private CredentialRepository $credentials,
        private EmployeeRegistry $employees,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * Devuelve `null` si el empleado no existe: quien llama lo traduce a 404.
     *
     * @throws EmployeeAlreadyHasCredential cuando ya hay una activa y no se pidio reemitir
     * @throws CredentialRevocationNeedsReason cuando se reemite sin declarar el motivo
     */
    public function handle(IssueCredentialCommand $command): ?IssuedCredential
    {
        $employeeId = $this->employees->internalIdFor($command->employeeUuid);

        if ($employeeId === null) {
            return null;
        }

        if ($command->reissue && trim((string) $command->reason) === '') {
            throw CredentialRevocationNeedsReason::make();
        }

        $now = $this->clock->now();

        return $this->connection->transaction(function () use (
            $command,
            $employeeId,
            $now,
        ): IssuedCredential {
            $replaced = $this->revokePreviousIfAsked($command, $employeeId, $now);

            $credential = Credential::issue(
                uuid: Str::uuid7()->toString(),
                employeeId: $employeeId,
                issuedAt: $now,
            );

            $credentialId = $this->credentials->add($credential);

            $this->events->publish(new CredentialIssued(
                credentialId: $credentialId,
                credentialUuid: $credential->uuid,
                employeeUuid: $command->employeeUuid,
                reissue: $replaced,
                actorUserId: $command->actorUserId,
                occurredAt: $now,
            ));

            return new IssuedCredential(
                credentialId: $credentialId,
                credential: $credential,
                employeeUuid: $command->employeeUuid,
                reissue: $replaced,
            );
        });
    }

    /**
     * Revoca la credencial activa anterior cuando se ha pedido reemitir.
     *
     * Devuelve si de verdad habia una: `reissue` sobre un empleado sin tarjeta
     * no es un error —es la primera emision— pero tampoco es una reemision, y el
     * asiento de auditoria tiene que decir cual de las dos cosas fue.
     */
    private function revokePreviousIfAsked(
        IssueCredentialCommand $command,
        int $employeeId,
        DateTimeImmutable $now,
    ): bool {
        if (! $command->reissue) {
            // Sin bandera no se toca nada: si hay una activa, el UNIQUE parcial
            // rechazara la insercion y quien llama recibira un 409.
            return false;
        }

        $previous = $this->credentials->activeForEmployee($employeeId);

        if (! $previous instanceof Credential) {
            return false;
        }

        $revoked = $previous->revoke((string) $command->reason, $now);

        $this->credentials->save($revoked);

        $this->events->publish(new CredentialRevoked(
            credentialId: $revoked->id ?? 0,
            credentialUuid: $revoked->uuid,
            employeeUuid: $command->employeeUuid,
            reason: (string) $revoked->revokedReason,
            actorUserId: $command->actorUserId,
            occurredAt: $now,
        ));

        return true;
    }
}
