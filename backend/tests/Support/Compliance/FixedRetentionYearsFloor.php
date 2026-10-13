<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Shared\Application\Port\RetentionYearsFloor;
use App\Modules\Shared\Domain\ValueObject\Jurisdiction;
use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;

/**
 * El suelo de años de conservacion que se le diga: el mismo para toda
 * jurisdiccion y todo centro, salvo los centros de `$bySite`. Apunta cada
 * `forSite()` que recibe, para comprobar cuantas veces se pregunta.
 */
final class FixedRetentionYearsFloor implements RetentionYearsFloor
{
    /** @var list<int> */
    public array $askedSites = [];

    /**
     * @param  array<int, int>  $bySite  Años por `site_id`, si alguno difiere de `$years`.
     */
    public function __construct(
        private readonly int $years = 1,
        private readonly array $bySite = [],
        private readonly string $jurisdiction = 'ES',
    ) {}

    public function forJurisdiction(Jurisdiction $jurisdiction): LegalRetentionFloor
    {
        return LegalRetentionFloor::fromCatalog($jurisdiction, $this->years);
    }

    public function forSite(int $siteId): LegalRetentionFloor
    {
        $this->askedSites[] = $siteId;

        return LegalRetentionFloor::fromCatalog(
            Jurisdiction::fromCode($this->jurisdiction),
            $this->bySite[$siteId] ?? $this->years,
        );
    }
}
