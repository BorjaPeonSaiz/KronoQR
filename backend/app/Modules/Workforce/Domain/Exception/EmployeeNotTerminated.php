<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Se pide readmitir a una persona que no esta de baja (RN-27, ADR-062 §6): sigue
 * en alta, suspendida o con una baja solo programada.
 *
 * `409` y no `422`: no hay campo que corregir, lo que no encaja es el estado de
 * la ficha. `getMessage()` lleva solo el `employee_uuid` (regla dura 21).
 */
final class EmployeeNotTerminated extends WorkforceDomainException implements BusinessRejection
{
    public const string TRANSLATION_KEY = 'employees.errors.employee_not_terminated';

    public static function withUuid(string $uuid): self
    {
        return new self(sprintf('The employee %s is not terminated and cannot be rehired (RN-27).', $uuid));
    }
}
