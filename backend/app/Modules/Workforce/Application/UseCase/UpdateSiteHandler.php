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
 * Modificacion de un centro.
 *
 * **Cambiar la zona horaria es un cambio con efecto sobre el computo legal**
 * (RN-05) y por eso este caso de uso es el sitio donde se enganchara el asiento
 * de auditoria de la tarea 1.14: no reescribe el pasado, pero decide a que
 * jornada se atribuyen los tramos siguientes.
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
        $site = $this->sites->findById($command->id);

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
