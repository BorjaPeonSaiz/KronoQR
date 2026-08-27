<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Command\CreateSiteCommand;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Domain\Exception\SiteNameAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;

/**
 * Alta de centro de trabajo.
 *
 * La zona horaria se construye como objeto de valor y no como cadena: es el dato
 * del que depende RN-05, y un identificador mal escrito no puede llegar a la
 * base de datos para descubrirse meses despues cuadrando una nomina.
 */
final readonly class CreateSiteHandler
{
    public function __construct(private SiteRepository $sites) {}

    /**
     * @throws SiteNameAlreadyTaken
     * @throws UnknownTimezone
     */
    public function handle(CreateSiteCommand $command): Site
    {
        return $this->sites->add(
            Site::create($command->name, SiteTimezone::fromString($command->timezone))
        );
    }
}
