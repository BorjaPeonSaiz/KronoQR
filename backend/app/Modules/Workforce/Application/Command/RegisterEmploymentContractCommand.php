<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

use App\Modules\Workforce\Domain\ValueObject\ScheduleType;

/**
 * Lo que hace falta para registrar un contrato (**RF-GP-02**).
 *
 * Las fechas llegan como cadenas ISO tal y como vinieron en la peticion: el
 * `FormRequest` ya ha comprobado que son fechas, y convertirlas a
 * `DateTimeImmutable` es del caso de uso, que es quien sabe que una vigencia es
 * calendario y no un instante.
 *
 * **No lleva `validTo`.** Un contrato se registra abierto y lo cierra el
 * siguiente: dejar que quien lo da de alta escriba una fecha de fin permitiria
 * crear un hueco —dias sin contrato vigente— sin que nada avise, y el informe
 * los contaria como «sin contrato» sin que nadie entienda por que. Cuando la
 * relacion laboral termina, lo que hay es una baja (RF-GP-03), y ese si es un
 * hecho con su propio endpoint.
 */
final readonly class RegisterEmploymentContractCommand
{
    public function __construct(
        /** UUID publico de la persona. */
        public string $employeeUuid,
        public float $weeklyHours,
        public ?float $annualHours,
        public ScheduleType $scheduleType,
        /** Primer dia de vigencia, `YYYY-MM-DD`, en la zona del centro. */
        public string $validFrom,
        /**
         * Cuenta de gestion que lo registra. `null` en una semilla o una
         * importacion: ahi no hay nadie detras y forzar un autor obligaria a
         * inventar una cuenta de sistema.
         */
        public ?int $registeredByUserId = null,
    ) {}
}
