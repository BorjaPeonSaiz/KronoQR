<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;

/**
 * El centro de la instalacion que se le diga (ADR-040: uno), o ninguno.
 */
final readonly class FixedInstallationSiteProvider implements InstallationSiteProvider
{
    public function __construct(private ?int $siteId = 1) {}

    public function installationSite(): ?InstallationSite
    {
        return $this->siteId === null ? null : new InstallationSite($this->siteId, 'Centro', 'Europe/Madrid');
    }
}
