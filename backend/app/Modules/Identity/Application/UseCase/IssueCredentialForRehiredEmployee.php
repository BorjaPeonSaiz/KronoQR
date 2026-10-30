<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Support\CredentialTelemetry;
use App\Modules\Identity\Domain\Event\CredentialIssued;
use App\Modules\Identity\Domain\Exception\EmployeeAlreadyHasCredential;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use DateTimeImmutable;
use RuntimeException;

/**
 * **La persona readmitida recibe una tarjeta nueva** (RN-27, ADR-062 §6).
 *
 * La readmision la decide `Workforce`; aqui solo se emite la credencial, con el
 * `uuid` que trae el evento para que la respuesta de la readmision pueda
 * nombrarla. Queda **pendiente de imprimir** (ADR-034): el token nace al
 * imprimirla, solo o con el lote de impresion. Las tarjetas revocadas en la
 * baja siguen revocadas: una tarjeta no se reactiva nunca.
 *
 * **Dentro de la transaccion de la readmision**, con la cadena ya tomada (orden
 * de ADR-046: cadena → `employees` → `credentials`). Si la emision o su asiento
 * `credential.issued` fallan, la readmision no se confirma: una persona en alta
 * sin tarjeta que imprimir es el olvido que esto existe para evitar.
 *
 * Una persona de baja no tiene credenciales activas: la baja las revoco todas.
 * Si apareciera una, no se emite otra encima (`409`): es una incoherencia que
 * `credentials:revoke-offboarded` repara, y no algo que tapar.
 */
final readonly class IssueCredentialForRehiredEmployee
{
    public function __construct(
        private CredentialRepository $credentials,
        private EmployeeRegistry $employees,
        private IdentityEventPublisher $events,
        private CredentialTelemetry $telemetry,
    ) {}

    /**
     * @throws EmployeeAlreadyHasCredential
     */
    public function handle(string $employeeUuid, string $credentialUuid, ?int $actorUserId, DateTimeImmutable $issuedAt): void
    {
        UseCaseInstrumentation::run('identity.issue_credential_for_rehired_employee', fn () => $this->telemetry->measure(
            'identity.credential_issued_on_rehire',
            ['employee_uuid' => $employeeUuid],
            fn (): int => $this->issue($employeeUuid, $credentialUuid, $actorUserId, $issuedAt),
        ));
    }

    private function issue(string $employeeUuid, string $credentialUuid, ?int $actorUserId, DateTimeImmutable $issuedAt): int
    {
        $employeeId = $this->employees->internalIdFor($employeeUuid)
            ?? throw new RuntimeException('La persona readmitida '.$employeeUuid.' no existe.');

        if ($this->credentials->activeForEmployee($employeeId) instanceof Credential) {
            throw EmployeeAlreadyHasCredential::make();
        }

        $credential = Credential::issue(uuid: $credentialUuid, employeeId: $employeeId, issuedAt: $issuedAt);
        $credentialId = $this->credentials->add($credential);

        $this->events->publish(new CredentialIssued(
            credentialId: $credentialId,
            credentialUuid: $credentialUuid,
            employeeUuid: $employeeUuid,
            reissue: false,
            actorUserId: $actorUserId,
            occurredAt: $issuedAt,
        ));

        return $credentialId;
    }
}
