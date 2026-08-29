<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Domain\Model\Site;

/**
 * Lectura del centro de la instalacion. Es uno (ADR-040): no hay lista ni
 * busqueda por identificador.
 */
final readonly class SiteQueries
{
    public function __construct(private SiteRepository $sites) {}

    public function installationSite(): ?Site
    {
        return $this->sites->installationSite();
    }
}
