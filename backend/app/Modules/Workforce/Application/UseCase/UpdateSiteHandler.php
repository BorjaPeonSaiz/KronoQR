<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Command\UpdateSiteCommand;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Domain\Exception\SiteNameAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;

/**
 * Modifica el centro de la instalacion: nombre o zona horaria.
 *
 * **Cambiar la zona cambia el calculo de las jornadas siguientes** (RN-05) y
 * por eso queda auditado por el oyente de `Compliance`. No reescribe el pasado.
 *
 * `null` solo antes de la puesta en marcha: no hay centro que modificar.
 */
final readonly class UpdateSiteHandler
{
    public function __construct(private SiteRepository $sites) {}

    /**
     * @throws SiteNameAlreadyTaken
     * @throws UnknownTimezone
     */
    public function handle(UpdateSiteCommand $command): ?Site
    {
        $site = $this->sites->installationSite();

        if ($site === null) {
            return null;
        }

        if ($command->name !== null) {
            $site = $site->rename($command->name);
        }

        if ($command->timezone !== null) {
            $site = $site->relocateTo(SiteTimezone::fromString($command->timezone));
        }

        $this->sites->save($site);

        return $site;
    }
}
