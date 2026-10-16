<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Query;

use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;

/**
 * Lo que devuelven la lectura y la modificacion del perfil de cumplimiento
 * (RF-PD-07, RL-02): el perfil y el suelo legal de su retencion.
 *
 * El suelo se resuelve en `Application` y viaja aqui para que el panel lo
 * pinte junto al campo (`retention_years_floor`) sin escribir el 4 en el
 * frontend, que seria duplicar el catalogo en un sitio sin prueba que lo ate.
 */
final readonly class ComplianceProfileView
{
    public function __construct(
        public ComplianceProfileSnapshot $profile,
        public LegalRetentionFloor $retentionFloor,
    ) {}
}
