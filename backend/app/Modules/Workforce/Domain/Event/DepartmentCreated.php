<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

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
    ) {}

    public function eventName(): string
    {
        return 'workforce.department_created';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
