<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha registrado un contrato para una persona, y con el ha quedado cerrado el
 * anterior si lo habia (**RF-GP-02**).
 *
 * ## Por que este hecho se audita
 *
 * `weekly_hours` es la cifra contra la que el informe de RF-IN-03 mide la
 * jornada de alguien: cambiarlo cambia su desviacion y sus horas de exceso. Es
 * un **parametro del calculo**, y el bloque D de la revision de cumplimiento
 * mete esos cambios en la misma familia que un cambio de rol o de umbral. Ante
 * la duda de si algo con efecto sobre horas de trabajo se audita, la respuesta
 * es si.
 *
 * ## Que lleva y que no
 *
 * Lo que hace falta para reconstruir el cambio: a quien, desde cuando, cuantas
 * horas y hasta cuando quedo el anterior. **Ningun nombre** (regla dura 21): la
 * persona viaja por su UUID publico, que es el identificador con el que trabajan
 * la API, el registro legal y el trail.
 *
 * **Sin actor.** Quien lo registro lo resuelve el asiento de auditoria a partir
 * de la sesion en curso: es una propiedad de la peticion y no del hecho, igual
 * que en el resto de los eventos de este modulo.
 */
final readonly class EmploymentContractRegistered implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        public float $weeklyHours,
        public ?float $annualHours,
        /** `continua`, `partida` o `turnos` (doc 01 §5.5). */
        public string $scheduleType,
        /** Primer dia de vigencia del contrato nuevo, `YYYY-MM-DD`. */
        public string $validFrom,
        /**
         * Ultimo dia de vigencia en el que quedo el contrato anterior, o `null`
         * si no habia ninguno. Es lo que convierte el asiento en algo
         * reconstruible: sin esta fecha no se sabe si el nuevo abrio la serie o
         * cerro otra.
         */
        public ?string $previousValidTo,
        private DateTimeImmutable $occurredAt,
    ) {}

    #[\Override]
    public function eventName(): string
    {
        return 'workforce.employment_contract_registered';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
