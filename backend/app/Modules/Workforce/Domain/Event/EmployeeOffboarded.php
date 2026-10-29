<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * La baja de una persona se ha hecho **efectiva** (RF-GP-03, RN-14): en el acto,
 * al registrarla, o aplicada por la tarea del sistema sobre una baja programada.
 *
 * **Es el evento con mas consecuencias de este modulo:**
 *
 *   - `Identity` revoca su credencial y cierra su portal: un empleado de baja no
 *     ficha.
 *   - `Compliance` sella el asiento `employee.offboarded` en `audit_log`. Con la
 *     tarea, el actor es `system`.
 *   - `Reporting` deja de contarla en la plantilla activa.
 *
 * Escrito asi, ninguno de los tres obliga a volver a tocar el caso de uso de la
 * baja: se suscriben a este evento, y la tarea publica el mismo.
 *
 * `terminatedOn` es la **fecha de cese**, que puede no ser hoy; `occurredAt` es
 * el instante en que la baja se hizo efectiva. No son lo mismo y confundirlos
 * atribuiria la baja al dia equivocado. Si la aplica la tarea, `scheduled`,
 * `dueOn` (el dia en que tocaba aplicarla, el siguiente al cese) y `daysLate`
 * dicen cuanto tardo.
 */
final readonly class EmployeeOffboarded implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public string $terminatedOn,
        public ?string $reason,
        private DateTimeImmutable $occurredAt,
        /** Se aplico en el acto con un cese de hoy por «Aplicar ya» (despido con efecto inmediato). */
        public bool $applyNow = false,
        /** La aplico la tarea del sistema sobre una baja programada. */
        public bool $scheduled = false,
        /** Con `scheduled`: el dia civil en que tocaba aplicarla, `Y-m-d`. */
        public ?string $dueOn = null,
        /** Con `scheduled`: dias civiles de retraso sobre `dueOn`; 0 si se aplico a tiempo. */
        public ?int $daysLate = null,
        /**
         * Con `scheduled`: la jornada del cese siguio despues de su dia (un turno
         * de noche o una pausa) y la tarea tuvo que esperar. Explica unos
         * `daysLate` que no son una tarea parada.
         */
        public bool $deferred = false,
    ) {}

    public function eventName(): string
    {
        return 'workforce.employee_offboarded';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
