<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Command\UpdateSiteCommand;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\SiteConfigured;
use App\Modules\Workforce\Domain\Exception\SiteNameAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;
use Illuminate\Database\ConnectionInterface;

/**
 * Modifica el centro de la instalacion: nombre o zona horaria.
 *
 * **Cambiar la zona cambia el calculo de las jornadas siguientes** (RN-05) y por
 * eso queda auditado como `site.updated`. No reescribe el pasado: las jornadas
 * ya calculadas conservan su `work_date`.
 *
 * **Hasta la tarea 5.5 este docblock decia que el cambio quedaba auditado y no
 * lo estaba**: no se publicaba ningun evento y no habia ningun oyente. Se dice
 * aqui porque una promesa incumplida en un comentario es peor que la ausencia de
 * la promesa — el siguiente que lo leyera habria dado la traza por hecha.
 *
 * El asiento va **dentro** de la transaccion (ADR-027, regla dura 6): si falla,
 * la zona horaria no cambia. Mover las horas de toda la plantilla de un dia a
 * otro sin dejar constancia de quien lo hizo es exactamente lo que el trail
 * existe para impedir.
 *
 * **Un PATCH que no cambia nada no deja asiento.** El panel manda el formulario
 * entero, asi que guardar sin tocar nada es lo mas facil que puede pasar; un
 * `site.updated` con el mismo antes y despues llenaria el trail de ruido y
 * haria mas dificil encontrar el cambio de zona horaria que si importa.
 *
 * `null` solo antes de la puesta en marcha: no hay centro que modificar.
 */
final readonly class UpdateSiteHandler
{
    public function __construct(
        private SiteRepository $sites,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws SiteNameAlreadyTaken
     * @throws UnknownTimezone
     */
    public function handle(UpdateSiteCommand $command): ?Site
    {
        $current = $this->sites->installationSite();

        if ($current === null) {
            return null;
        }

        $site = $current;

        if ($command->name !== null) {
            $site = $site->rename($command->name);
        }

        if ($command->timezone !== null) {
            $site = $site->relocateTo(SiteTimezone::fromString($command->timezone));
        }

        $previousName = $current->name;
        $previousTimezone = $current->timezone->identifier;

        // Nada que escribir y, sobre todo, nada que auditar. El `200` se
        // devuelve igual: para quien llama, el centro quedo como pedia.
        if ($site->name === $previousName && $site->timezone->identifier === $previousTimezone) {
            return $current;
        }

        return $this->connection->transaction(function () use ($site, $previousName, $previousTimezone): Site {
            $this->sites->save($site);

            $this->events->publish(SiteConfigured::updated(
                siteId: (int) $site->id,
                name: $site->name,
                timezone: $site->timezone->identifier,
                previousName: $previousName,
                previousTimezone: $previousTimezone,
                occurredAt: $this->clock->now(),
            ));

            return $site;
        });
    }
}
