<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Una persona de baja ha vuelto a la plantilla en su misma ficha, con un periodo
 * de alta nuevo (RN-27, ADR-062 §6).
 *
 * - `Identity` le emite una **credencial nueva pendiente de imprimir** con el
 *   `credentialUuid` que trae el evento (ADR-034): `Workforce` no importa
 *   `Identity`, y asi la respuesta de la readmision puede nombrar la tarjeta.
 * - `Compliance` escribe el asiento `employee.rehired`.
 *
 * Solo identificadores y fechas (regla dura 21). `actorUserId` es la cuenta de
 * gestion que readmite, para el asiento de la credencial; `null` sin cuenta.
 */
final readonly class EmployeeRehired implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public string $employmentPeriodUuid,
        /** Fecha de alta del periodo nuevo, `Y-m-d`. */
        public string $hiredOn,
        public ?int $departmentId,
        public ?int $previousDepartmentId,
        public bool $pinReset,
        public bool $viaImport,
        /**
         * Lo cuenta en el plan el evento de su lote ({@see EmployeesImported} o
         * {@see EmployeesRehired}), una vez, y no esta readmision suelta.
         */
        public bool $countedByBatch,
        public string $credentialUuid,
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function departmentChanged(): bool
    {
        return $this->departmentId !== $this->previousDepartmentId;
    }

    public function eventName(): string
    {
        return 'workforce.employee_rehired';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
