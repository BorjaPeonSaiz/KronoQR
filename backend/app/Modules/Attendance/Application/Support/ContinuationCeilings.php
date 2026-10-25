<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Support;

use App\Modules\Attendance\Domain\ValueObject\ContinuationCeiling;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;

/**
 * Construye la cota de continuacion de RN-05 con el descanso minimo entre
 * jornadas del perfil del centro ya resuelto (RN-10, regla dura 14).
 *
 * **Existe para que el umbral se lea en un solo sitio**, con el mismo patron que
 * {@see ClockingPolicies}. Lo usan el fichaje (`RegisterScanHandler`, la vuelta
 * de una pausa), el alta manual (`AddShiftEntryHandler`) y la correccion
 * (`CorrectShiftEntryHandler`). Si cada uno resolviera el perfil por su cuenta, el
 * dia que uno leyera otro campo el mismo hueco seria pausa en el quiosco y
 * jornada nueva en el panel.
 */
final readonly class ContinuationCeilings
{
    public static function forSite(CompliancePolicyProvider $compliance, int $siteId): ContinuationCeiling
    {
        return ContinuationCeiling::belowRestOf(
            WorkedDuration::ofMinutes($compliance->forSite($siteId)->minimumRestMinutes),
        );
    }
}
