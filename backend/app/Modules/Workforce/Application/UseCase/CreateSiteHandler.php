<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Command\CreateSiteCommand;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\SiteConfigured;
use App\Modules\Workforce\Domain\Exception\SiteAlreadyConfigured;
use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;
use Illuminate\Database\ConnectionInterface;

/**
 * Crea **el** centro de trabajo de la instalacion (ADR-040).
 *
 * Solo tiene exito una vez: es lo que ejecuta el asistente de puesta en marcha
 * (RF-PD-03, `POST /api/v1/setup/site`) y, hasta que exista, la semilla.
 * **No hay alta en `/api/v1/site`**, que es singular y no tiene ni alta ni lista
 * (Anexo B del doc 01): un alta permanente ahi seria la primera pieza del
 * multicentro que ADR-040 cerro.
 *
 * La comprobacion previa es cortesia —un mensaje del dominio antes de tocar la
 * base de datos—; la garantia es el indice `sites_single_row_uidx`, que el
 * repositorio traduce a la misma excepcion si dos puestas en marcha
 * coincidieran.
 *
 * ## El asiento va DENTRO de la transaccion, al contrario que el alta de empleado
 *
 * `WorkforceEventPublisher` se llama despues de confirmar en el resto del
 * modulo, y aqui no: `site.created` es la constancia de **con que zona horaria
 * nacio la instalacion**, que es el parametro con el que RN-05 atribuye cada
 * tramo a un dia (regla dura 6, ADR-027). Un centro creado sin ese asiento deja
 * el registro legal sin la pieza que explica como se calcularon las jornadas, y
 * eso no se puede reconstruir despues.
 */
final readonly class CreateSiteHandler
{
    public function __construct(
        private SiteRepository $sites,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws SiteAlreadyConfigured
     * @throws UnknownTimezone
     */
    public function handle(CreateSiteCommand $command): Site
    {
        if ($this->sites->installationSite() instanceof Site) {
            throw SiteAlreadyConfigured::make();
        }

        // La zona se valida ANTES de abrir la transaccion: una zona que no
        // existe no es un fallo de escritura, es un `422`, y no tiene por que
        // costar un `BEGIN`.
        $timezone = SiteTimezone::fromString($command->timezone);

        return $this->connection->transaction(function () use ($command, $timezone): Site {
            $site = $this->sites->add(Site::create($command->name, $timezone));

            // `add()` devuelve el centro ya con su `id`, asi que el asiento
            // puede nombrar el sujeto sobre el que se actuo.
            $this->events->publish(SiteConfigured::created(
                siteId: (int) $site->id,
                name: $site->name,
                timezone: $site->timezone->identifier,
                occurredAt: $this->clock->now(),
            ));

            return $site;
        });
    }
}
