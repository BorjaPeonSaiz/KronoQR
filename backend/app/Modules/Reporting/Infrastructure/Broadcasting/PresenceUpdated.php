<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Broadcasting;

use App\Modules\Reporting\Application\Support\PresenceChannels;
use App\Modules\Reporting\Application\Support\PresenceEntryPayload;
use App\Modules\Reporting\Domain\ValueObject\PresenceEntry;
use DateTimeImmutable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * El mensaje `presence.updated` del canal de presencia (**RF-PA-01**, ADR-011).
 *
 * Lleva la fila entera de esa persona —el esquema `LivePresenceEntry` del
 * contrato, construido por {@see PresenceEntryPayload}— para que el panel la
 * sustituya sin volver a consultar. Es lo que hace que la vista se mantenga al
 * dia con un mensaje por fichaje en lugar de con un sondeo por segundo.
 *
 * ## Nada del registro legal viaja por aqui
 *
 * ADR-011 lo dice por escrito: *«el canal difunde eventos de presencia para una
 * vista de lectura; el fichaje se registra por HTTP y su respuesta no depende de
 * que el canal este vivo»*. Perder este mensaje no pierde nada: el sondeo de 15
 * s lo recupera.
 *
 * ## `ShouldBroadcastNow` y no `ShouldBroadcast`
 *
 * Porque quien lo emite —{@see BroadcastPresenceChange}— **ya esta en la cola**.
 * Con `ShouldBroadcast` habria un segundo salto: un trabajo que encola otro
 * trabajo para lo mismo, con su latencia y su segundo modo de fallo. La
 * separacion entre el camino de fichaje y este mensaje la garantiza el listener,
 * que es quien esta encolado y quien difiere al commit.
 *
 * ## El nombre viaja aqui y a ningun log
 *
 * El canal es privado y su suscripcion la firma el servidor con el alcance de
 * quien pregunta, asi que el nombre solo llega a una pantalla autorizada. **No
 * se registra el payload en ningun log** (regla dura 21): quien depure esto tiene
 * el `employee_uuid` en el mensaje del listener y nada mas.
 */
final readonly class PresenceUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public PresenceEntry $entry,
        /** Momento real del cambio (regla dura 9): la marca del fichaje o la de la correccion. */
        public DateTimeImmutable $occurredAt,
    ) {}

    /**
     * El global y el del departamento de esa persona. **Nunca el de otro
     * departamento** (RF-ID-03).
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (string $channel): PrivateChannel => new PrivateChannel($channel),
            PresenceChannels::forEntry($this->entry),
        );
    }

    /**
     * Nombre estable del evento, y no el de la clase.
     *
     * El panel se suscribe a esta cadena: si viajara el nombre de la clase PHP,
     * renombrar o mover el fichero romperia los tres frontends sin que nada
     * fallara en el servidor.
     */
    public function broadcastAs(): string
    {
        return 'presence.updated';
    }

    /**
     * @return array{entry: array<string, mixed>, occurred_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'entry' => PresenceEntryPayload::of($this->entry),
            'occurred_at' => (string) PresenceEntryPayload::utc($this->occurredAt),
        ];
    }
}
