<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;

/**
 * El suelo legal de la retencion de cada centro que aparece en los asientos de
 * purga, ya resuelto (RL-02, regla dura 14, ADR-057 enmienda del 10-10-2026).
 *
 * La conciliacion y la revision de una purga lo construyen con una consulta al
 * puerto por cada centro distinto, y la regla de los asientos lo lee aqui sin
 * saber de catalogos ni de jurisdicciones. **Es la unica cuenta del suelo
 * efectivo**: los dos caminos juzgan un asiento con el mismo numero.
 */
final readonly class PurgeFloors
{
    /**
     * @param  array<int, int>  $yearsBySite
     */
    private function __construct(
        private array $yearsBySite,
        private int $withoutSiteYears,
    ) {}

    /**
     * @param  array<int, LegalRetentionFloor>  $bySite  indexado por `site_id`
     * @param  LegalRetentionFloor|null  $installationSite  El del centro de la instalacion (ADR-040: hay uno), para los asientos que no dicen centro.
     */
    public static function of(array $bySite, ?LegalRetentionFloor $installationSite = null): self
    {
        $years = [];

        foreach ($bySite as $siteId => $floor) {
            $years[$siteId] = $floor->years;
        }

        return new self($years, $installationSite?->years ?? 1);
    }

    /**
     * El suelo del centro. Un asiento sin centro se juzga con el del centro de
     * la instalacion, que es el unico que puede haber purgado (ADR-040); sin el,
     * y para un centro desconocido, el minimo estructural 1. Un asiento sin
     * centro incumple ademas `site_id` en cualquier caso.
     */
    public function yearsFor(?int $siteId): int
    {
        return $siteId === null ? $this->withoutSiteYears : ($this->yearsBySite[$siteId] ?? 1);
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
