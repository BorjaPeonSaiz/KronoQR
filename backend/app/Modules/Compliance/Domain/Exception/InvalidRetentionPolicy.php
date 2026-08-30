<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

use App\Modules\Compliance\Domain\Policy\RetentionPolicy;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;

/**
 * Un plazo de retencion que no puede ser (RL-11).
 *
 * Falla en el constructor de {@see RetentionPolicy}
 * y no mas tarde: un cero o un negativo en el perfil de cumplimiento significaria
 * «purgalo todo», y ese error tiene que detenerse antes de que nadie calcule una
 * fecha de corte con el.
 */
final class InvalidRetentionPolicy extends ComplianceDomainException
{
    public static function notPositive(string $what, int $value): self
    {
        return new self(
            'La politica de retencion no puede fijar '.$what.' en '.$value.'. '
            .'Revisa el perfil de cumplimiento del centro y ERROR_HISTORY_RETENTION_DAYS.'
        );
    }

    public static function hasNoShortCycle(RetentionScope $scope): self
    {
        return new self(
            'El ambito «'.$scope->value.'» se mide en anos de conservacion legal, no en dias: '
            .'su corte es workRecordCutoff().'
        );
    }
}
