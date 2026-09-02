<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * El centro de trabajo de la instalacion se ha creado o se ha modificado
 * (RF-PD-03, RN-05, regla dura 6).
 *
 * ## Por que esto se audita
 *
 * Por `timezone`. RN-05 define la jornada como «la fecha civil, **en la zona del
 * centro**, del `clocked_in_at` del tramo que abre la jornada»: ese campo es el
 * parametro con el que se atribuye cada tramo a un dia, y cambiarlo cambia el
 * dia al que se imputan las horas de todo el mundo a partir de ese momento.
 * Cae en la misma familia del bloque D que un umbral de calculo, y por el mismo
 * motivo: alguien puede mover horas de un dia a otro sin tocar un solo fichaje.
 *
 * **Es deuda saldada, no funcionalidad nueva.** `UpdateSiteHandler` afirmaba en
 * su docblock desde la tarea 1.6 que el cambio «queda auditado por el oyente de
 * `Compliance`» y no publicaba ningun evento: no habia ni oyente ni asiento. La
 * tarea 5.5 lo arregla porque necesita auditar el alta del centro y no podia
 * dejar la modificacion sin auditar al lado.
 *
 * ## Un evento y dos hechos
 *
 * `created` distingue el alta de la modificacion, y el listener elige entre
 * `site.created` y `site.updated`. Dos clases de evento habrian repetido el
 * mismo payload y el mismo listener para una diferencia que cabe en un booleano.
 *
 * ## Que viaja
 *
 * El identificador del centro, su nombre y su zona, **y en la modificacion
 * tambien los anteriores**. **No hay ningun dato personal aqui** (regla dura
 * 21): el nombre es el del hotel, que es lo que hace falta para que el asiento
 * diga sobre que se actuo.
 *
 * ## El valor anterior no es un adorno
 *
 * RL-04 pide que el asiento sea **reconstruible**: «la zona paso a
 * `Atlantic/Canary`» no permite responder a «¿que jornada tenia el turno de
 * noche del 3 de marzo?» sin ir a buscar el asiento anterior —que puede no
 * existir, si el centro se creo antes de la 5.5— ni saber siquiera si el PATCH
 * cambio la zona o solo el nombre. Es la misma convencion que
 * `InstallationSettingChanged`: `previous_value` y `new_value`.
 *
 * En el **alta** no hay valor anterior y los dos campos son `null`. Es
 * deliberado: `null` significa «no habia centro», no «no se sabe».
 */
final readonly class SiteConfigured implements DomainEvent
{
    private function __construct(
        public int $siteId,
        public string $name,
        public string $timezone,
        public ?string $previousName,
        public ?string $previousTimezone,
        public bool $created,
        private DateTimeImmutable $occurredAt,
    ) {}

    public static function created(
        int $siteId,
        string $name,
        string $timezone,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self($siteId, $name, $timezone, null, null, true, $occurredAt);
    }

    public static function updated(
        int $siteId,
        string $name,
        string $timezone,
        string $previousName,
        string $previousTimezone,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self($siteId, $name, $timezone, $previousName, $previousTimezone, false, $occurredAt);
    }

    public function eventName(): string
    {
        return $this->created ? 'workforce.site_created' : 'workforce.site_updated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
