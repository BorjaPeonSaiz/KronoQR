<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Las dos magnitudes del plan que se cuentan (**ADR-028**, ADR-040).
 *
 * `personas` y `quioscos`. No hay una tercera: ADR-040 retiro `max_sites` porque
 * hay exactamente un centro por instalacion.
 *
 * El valor es el nombre del campo de la clave firmada y el que sale en el
 * asiento de `audit_log`, para que las tres representaciones —clave, trail y
 * `license:show`— usen la misma palabra.
 */
enum PlanLimit: string
{
    case Employees = 'max_employees';
    case Devices = 'max_devices';
}
