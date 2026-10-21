<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkRecordPresence;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezoneLock;

/**
 * Lectura del centro de la instalacion. Es uno (ADR-040): no hay lista ni
 * busqueda por identificador.
 *
 * Lleva si la zona ya esta fija (RN-23): el panel no ofrece cambiarla cuando ya
 * hay registro horario. Es una lectura sin candado y puede quedar vieja un
 * instante despues; quien decide de verdad es `UpdateSiteHandler`, dentro de su
 * transaccion.
 */
final readonly class SiteQueries
{
    public function __construct(
        private SiteRepository $sites,
        private WorkRecordPresence $workRecord,
    ) {}

    public function installationSite(): ?SiteView
    {
        return UseCaseInstrumentation::run('workforce.read_installation_site', fn (): ?SiteView => $this->doInstallationSite());
    }

    private function doInstallationSite(): ?SiteView
    {
        $site = $this->sites->installationSite();

        if ($site === null) {
            return null;
        }

        return new SiteView($site, SiteTimezoneLock::fromWorkRecordPresence($this->workRecord->exists()));
    }
}
