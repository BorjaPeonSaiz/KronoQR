<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;

/**
 * El suelo legal de la retencion de cada centro que aparece en los asientos de
 * purga, ya resuelto (RL-02, regla dura 14, ADR-057 enmienda del 10-10-2026).
 *
 * La conciliacion lo construye una vez por pasada, con una consulta al puerto
 * por cada centro distinto, y la regla de los asientos lo lee aqui sin saber
 * de catalogos ni de jurisdicciones.
 */
final readonly class PurgeFloors
{
    /**
     * @param  array<int, int>  $yearsBySite
     */
    private function __construct(private array $yearsBySite) {}

    /**
     * @param  array<int, LegalRetentionFloor>  $bySite  indexado por `site_id`
     */
    public static function of(array $bySite): self
    {
        $years = [];

        foreach ($bySite as $siteId => $floor) {
            $years[$siteId] = $floor->years;
        }

        return new self($years);
    }

    /**
     * El suelo del centro, o 1 si el centro es nulo o desconocido: el minimo
     * estructural, que un asiento sin centro ya incumple por `site_id`.
     */
    public function yearsFor(?int $siteId): int
    {
        return $siteId === null ? 1 : ($this->yearsBySite[$siteId] ?? 1);
    }

    /**
     * El suelo con el que se juzga un asiento de purga: el mayor del de su
     * centro y el que el propio asiento declara. El asiento solo puede
     * endurecerse a si mismo, nunca rebajarse.
     */
    public function effectiveFor(AuditedPurge $purge): int
    {
        return max($this->yearsFor($purge->siteId), $purge->declaredFloorYears ?? 1);
    }
}
