<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Query;

/**
 * Lo que pide quien consulta el registro horario de alguien: a quien y de
 * cuando.
 *
 * Las dos fechas son **opcionales** y llegan tal y como vinieron en la URL, sin
 * interpretar: resolver la omision necesita la zona del centro del empleado, y
 * eso solo se sabe despues de buscarlo. Lo hace {@see ReadEmployeeWorkDays}.
 */
final readonly class EmployeeWorkDayRange
{
    public function __construct(
        public string $employeeUuid,
        /** `YYYY-MM-DD` o `null` para «los 31 dias que terminan en `to`». */
        public ?string $from = null,
        /** `YYYY-MM-DD` o `null` para «hoy en la zona del centro». */
        public ?string $to = null,
    ) {}
}
