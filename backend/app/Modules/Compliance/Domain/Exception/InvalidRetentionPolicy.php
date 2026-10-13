<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

use App\Modules\Compliance\Domain\Policy\RetentionPolicy;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;

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

    /**
     * El plazo del perfil esta por debajo del suelo legal de su jurisdiccion
     * (RL-02: 4 años en España, art. 34.9 ET). Solo puede pasar con una fila
     * editada a mano o con un pais del catalogo sin su `CHECK`: el panel no lo
     * admite.
     */
    public static function belowLegalFloor(int $years, LegalRetentionFloor $floor): self
    {
        return new self(
            'La politica de retencion no puede conservar el registro '.$years.' anos: la jurisdiccion '
            .$floor->jurisdiction->code.' obliga a conservarlo al menos '.$floor->years.' (RL-02). '
            .'Sube retention_years en el perfil de cumplimiento del centro. No se ha purgado nada.'
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
