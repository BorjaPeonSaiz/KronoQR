<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Command\CreateSiteCommand;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Domain\Exception\SiteAlreadyConfigured;
use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;

/**
 * Crea **el** centro de trabajo de la instalacion (ADR-040).
 *
 * Solo tiene exito una vez: es lo que ejecuta el asistente de puesta en marcha
 * (RF-PD-03) y, hasta que exista, la semilla. No hay endpoint que lo exponga.
 *
 * La comprobacion previa es cortesia —un mensaje del dominio antes de tocar la
 * base de datos—; la garantia es el indice `sites_single_row_uidx`, que el
 * repositorio traduce a la misma excepcion si dos puestas en marcha
 * coincidieran.
 */
final readonly class CreateSiteHandler
{
    public function __construct(private SiteRepository $sites) {}

    /**
     * @throws SiteAlreadyConfigured
     * @throws UnknownTimezone
     */
    public function handle(CreateSiteCommand $command): Site
    {
        if ($this->sites->installationSite() instanceof Site) {
            throw SiteAlreadyConfigured::make();
        }

        return $this->sites->add(
            Site::create($command->name, SiteTimezone::fromString($command->timezone))
        );
    }
}
