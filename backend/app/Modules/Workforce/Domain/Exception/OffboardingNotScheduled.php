<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Se pide anular una baja programada de una persona que no tiene ninguna
 * (RN-14, ADR-062 §3): no hay fecha de cese que quitar.
 *
 * Es un `409` y no un `422`: la peticion no tiene campos que corregir, lo que no
 * encaja es el estado de la ficha, y la accion siguiente es releerla.
 * `getMessage()` lleva solo el `employee_uuid` (regla dura 21); el texto que ve
 * una persona sale de {@see self::TRANSLATION_KEY}.
 */
final class OffboardingNotScheduled extends WorkforceDomainException implements BusinessRejection
{
    public const string TRANSLATION_KEY = 'employees.errors.offboarding_not_scheduled';

    public static function withUuid(string $uuid): self
    {
        return new self(sprintf('The employee %s has no scheduled offboarding to cancel (RN-14).', $uuid));
    }
}
