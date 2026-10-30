<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Se pide corregir la fecha de cese de un periodo sin baja efectiva (RN-27,
 * ADR-062 §7): sin baja no hay fecha que corregir, y una baja programada se
 * reprograma desde la baja.
 */
final class OffboardingNotEffective extends WorkforceDomainException implements BusinessRejection
{
    public const string TRANSLATION_KEY = 'employees.errors.offboarding_not_effective';

    public static function forPeriod(string $periodUuid): self
    {
        return new self(sprintf('The employment period %s has no effective offboarding to correct (RN-27).', $periodUuid));
    }
}
