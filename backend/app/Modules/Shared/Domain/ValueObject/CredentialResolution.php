<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Desenlace de resolver un payload QR: o hay un empleado detras, o hay un
 * motivo de rechazo. Nunca las dos cosas y nunca ninguna.
 *
 * Es lo que devuelve el puerto `Attendance\Application\Port\CredentialResolver`,
 * que implementa Identity (ADR-025). Vive en Shared por la misma razon que
 * {@see EmployeeSnapshot}: cruza la frontera entre dos modulos, y el adaptador
 * de Identity solo puede alcanzar `Attendance\Application\Port`, jamas el
 * `Domain` del nucleo.
 *
 * El constructor es privado y solo hay dos formas de llegar a una instancia, de
 * modo que el estado imposible —resuelta y rechazada a la vez, o ninguna de las
 * dos— no se puede construir.
 */
final readonly class CredentialResolution
{
    private function __construct(
        private ?string $employeeUuid,
        private ?CredentialRejectionReason $rejectionReason,
        private ?PinClaim $pinClaim = null,
        private ?CredentialHolder $holder = null,
        private ?DateTimeImmutable $issuedAt = null,
    ) {}

    /**
     * La credencial es valida, esta vigente y apunta a este empleado.
     */
    public static function resolved(string $employeeUuid, ?DateTimeImmutable $issuedAt = null): self
    {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('Una credencial resuelta necesita el UUID del empleado.');
        }

        return new self($employeeUuid, null, issuedAt: $issuedAt);
    }

    /**
     * No se resuelve. El motivo es para el registro interno, no para la
     * respuesta: RS-03 exige un rechazo generico y de tiempo constante.
     */
    public static function rejected(CredentialRejectionReason $reason): self
    {
        return new self(null, $reason);
    }

    /**
     * Rechazo de un fichaje por PIN cuyo codigo es de una persona que puede
     * fichar (RN-19, ADR-043). **Hacia fuera es el mismo rechazo** que
     * {@see rejected()} con `UNKNOWN`: sin `employeeUuid()` y con el mismo
     * motivo. El claim solo lo lee el caso de uso para escribir
     * `scan_events.claimed_employee_id`.
     */
    public static function rejectedWithPinClaim(PinClaim $claim): self
    {
        return new self(null, CredentialRejectionReason::UNKNOWN, $claim);
    }

    /**
     * Rechazo de una tarjeta **autentica** que ya no vale —credencial retirada o
     * titular de baja— (RN-20, ADR-047). **Hacia fuera es el mismo rechazo** que
     * {@see rejected()}: sin `employeeUuid()` y con el mismo motivo. El titular
     * solo lo lee el caso de uso para atribuir la fila de `scan_events` y
     * decidir si pide revision; nunca sube a la respuesta.
     */
    public static function rejectedWithHolder(CredentialRejectionReason $reason, CredentialHolder $holder): self
    {
        return new self(null, $reason, holder: $holder);
    }

    /**
     * UUID del empleado, o `null` si la credencial no se resolvio.
     *
     * Devuelve `?string` en lugar de lanzar para que quien llama tenga que
     * estrechar el tipo: con PHPStan 9, olvidarse del caso de rechazo no
     * compila.
     */
    public function employeeUuid(): ?string
    {
        return $this->employeeUuid;
    }

    /**
     * Motivo del rechazo, o `null` si la credencial se resolvio.
     */
    public function rejectionReason(): ?CredentialRejectionReason
    {
        return $this->rejectionReason;
    }

    public function isResolved(): bool
    {
        return $this->employeeUuid !== null;
    }

    /**
     * A quien correspondia el codigo de un PIN rechazado (RN-19), o `null`.
     * Nunca viaja a una respuesta, a un evento ni al log.
     */
    public function pinClaim(): ?PinClaim
    {
        return $this->pinClaim;
    }

    /**
     * El titular de una tarjeta autentica rechazada (RN-20), o `null`. Nunca
     * viaja a una respuesta.
     */
    public function holder(): ?CredentialHolder
    {
        return $this->holder;
    }

    /**
     * Emision de la credencial que resolvio (`credentials.issued_at`), o `null`
     * si quien resolvio no la conoce. La usa el aviso de fichaje descartado
     * (RN-22) como cota inferior de su revision; nunca viaja a una respuesta.
     */
    public function issuedAt(): ?DateTimeImmutable
    {
        return $this->issuedAt;
    }
}
