<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Domain\ValueObject\ComplianceProfileField;
use App\Modules\Shared\Application\Port\RetentionYearsFloor;

/**
 * El suelo de `compliance_profiles.retention_years`, el mismo que valida el
 * panel al editar el perfil ({@see ComplianceProfileField::minimum()}).
 */
final readonly class ProfileRetentionYearsFloor implements RetentionYearsFloor
{
    public function minimumRetentionYears(): int
    {
        return ComplianceProfileField::RetentionYears->minimum();
    }
}
