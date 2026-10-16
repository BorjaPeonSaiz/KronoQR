<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Workforce\Application\Command\UpdateSiteCommand;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Application\Port\WorkRecordPresence;
use App\Modules\Workforce\Application\Query\SiteView;
use App\Modules\Workforce\Domain\Event\SiteConfigured;
use App\Modules\Workforce\Domain\Exception\SiteNameAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\SiteTimezoneLocked;
use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezoneLock;
use Illuminate\Database\ConnectionInterface;

/**
 * Modifica el centro de la instalacion: nombre o zona horaria.
 *
 * ## La zona queda fija con el primer tramo (RN-23, ADR-056)
 *
 * Mientras no haya registro horario, la zona se cambia aqui y queda auditada
 * como `site.updated`. **Desde el primer tramo, en cualquier estado, pedir otra
 * zona es `SiteTimezoneLocked` (`409`)** y no cambia nada: tampoco el nombre si
 * viajaba en la misma peticion, y no deja asiento. La misma zona que ya tiene no
 * es un cambio. Corregir una zona mal puesta es `CorrectSiteTimezoneHandler`,
 * por consola.
 *
 * **Todo ocurre dentro de la transaccion y con la fila del centro bloqueada**
 * (`FOR UPDATE`): se bloquea, se pregunta si hay registro horario y se aplica.
 * El fichaje y el alta manual leen la zona con `FOR KEY SHARE` antes de escribir
 * su tramo (`SiteCalendar::timezoneForRecording()`), asi que un primer fichaje
 * concurrente o confirmo antes —y esta consulta lo ve— o espera y ficha con la
 * zona nueva. Nunca queda la zona cambiada con un tramo atribuido con la vieja.
 *
 * El asiento va **dentro** de la transaccion (ADR-027, regla dura 6): si falla,
 * la zona no cambia. El orden de candados es el de ADR-046: la fila padre —el
 * centro— antes que la cadena de `audit_log`.
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
        private WorkRecordPresence $workRecord,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws SiteNameAlreadyTaken
     * @throws SiteTimezoneLocked si ya hay registro horario y la zona pedida es otra
     * @throws UnknownTimezone
     */
    public function handle(UpdateSiteCommand $command): ?SiteView
    {
        return UseCaseInstrumentation::run('workforce.update_site', fn (): ?SiteView => $this->doHandle($command));
    }

    private function doHandle(UpdateSiteCommand $command): ?SiteView
    {
        // Antes de abrir la transaccion: una zona que no existe es un `422` y no
        // necesita bloquear nada.
        $timezone = $command->timezone === null ? null : SiteTimezone::fromString($command->timezone);

        return $this->connection->transaction(function () use ($command, $timezone): ?SiteView {
            $current = $this->sites->installationSiteForUpdate();

            if ($current === null) {
                return null;
            }

            // Con la fila del centro ya bloqueada: ver el docblock de la clase.
            $lock = SiteTimezoneLock::fromWorkRecordPresence($this->workRecord->exists());

            $site = $current;

            if ($command->name !== null) {
                $site = $site->rename($command->name);
            }

            // Lanza antes de guardar nada: el nombre tampoco cambia (RN-23).
            if ($timezone !== null) {
                $site = $site->relocateTo($timezone, $lock);
            }

            // Nada que escribir y, sobre todo, nada que auditar. El `200` se
            // devuelve igual: para quien llama, el centro quedo como pedia.
            if ($site->name === $current->name && $site->timezone->equals($current->timezone)) {
                return new SiteView($current, $lock);
            }

            $this->sites->save($site);

            $this->events->publish(SiteConfigured::updated(
                siteId: (int) $site->id,
                name: $site->name,
                timezone: $site->timezone->identifier,
                previousName: $current->name,
                previousTimezone: $current->timezone->identifier,
                occurredAt: $this->clock->now(),
            ));

            return new SiteView($site, $lock);
        });
    }
}
