<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Se ha dado de alta un departamento (RF-GP-02, AUD-2).
 *
 * Un departamento decide que responsable ve y corrige la jornada de quien
 * (RF-ID-03): crearlo abre un ambito de autoridad nuevo, y eso deja asiento en
 * `audit_log` (`department.created`, regla dura 6).
 *
 * **Solo identificadores, sin el nombre** (misma convencion que
 * {@see EmployeeProfileUpdated}): el evento dice que ocurrio, no copia valores.
 */
final readonly class DepartmentCreated implements DomainEvent
{
    public function __construct(
        public int $departmentId,
        public int $siteId,
        private DateTimeImmutable $occurredAt,
    ) {
        // Es el `subject_id` del asiento: sin fila no hay sujeto, y un cero
        // dejaria un asiento sobre el sujeto 0 en lugar de fallar (R1-AR-03).
        if ($departmentId <= 0) {
            throw new InvalidArgumentException('El hecho sobre el departamento necesita el identificador de su fila, y llego '.$departmentId.'.');
        }
    }

    public function eventName(): string
    {
        return 'workforce.department_created';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
