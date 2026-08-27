<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

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
    ) {}

    /**
     * La credencial es valida, esta vigente y apunta a este empleado.
     */
    public static function resolved(string $employeeUuid): self
    {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('Una credencial resuelta necesita el UUID del empleado.');
        }

        return new self($employeeUuid, null);
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
}
