<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Ha cambiado el responsable de un departamento (RF-ID-03, RF-ID-10, ADR-051
 * §5).
 *
 * **Es un cambio de permisos de dos personas**, no un dato del departamento:
 * quien deja de ser responsable pierde el alcance sobre esa gente y quien pasa
 * a serlo lo gana. `Compliance` deja un asiento `role_assignment.changed` por
 * cada cuenta afectada (regla dura 6, RS-05).
 *
 * Lleva los `uuid` de las cuentas, nunca su nombre (regla dura 21). Cualquiera
 * de los dos puede ser `null` —un departamento sin responsable que recibe uno,
 * o uno que se queda sin el—, pero nunca los dos iguales: si no cambia nada, no
 * se publica.
 */
final readonly class DepartmentManagerChanged implements DomainEvent
{
    public function __construct(
        public int $departmentId,
        public ?string $previousManagerUuid,
        public ?string $newManagerUuid,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'workforce.department_manager_changed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
