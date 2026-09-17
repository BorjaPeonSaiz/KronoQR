<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\DebouncePolicy;
use DateTimeImmutable;

/**
 * Un escaneo del empleado que **ya produjo un tramo**: su instante real, lo que
 * hizo con la jornada y el tramo que abrio o cerro.
 *
 * Es lo que el dominio necesita saber de los escaneos anteriores de una persona
 * para decidir sobre el que llega ahora, y lo necesita **por dos reglas
 * distintas**:
 *
 * - **RF-AT-06** — el anti-rebote ya no puede mirar solo instantes: un
 *   `break_end` a veinte segundos de un `break_start` no es un rebote
 *   (ADR-024). {@see DebouncePolicy}.
 * - **RF-AT-12 y RN-05** — para saber si el escaneo que llega es una vuelta de
 *   pausa hay que saber si el anterior fue una pausa, y **de que tramo**: la
 *   jornada que continua es la de aquel tramo, no la de la fecha civil de hoy
 *   (ADR-024, consecuencia 2). {@see \App\Modules\Attendance\Domain\Policy\
 *   ScanIntentPolicy}.
 *
 * ## Solo los aceptados
 *
 * Un rechazo no entra aqui, y el anti-rebote lo dice desde la tarea 1.4: si un
 * `rejected_debounce` reiniciara la ventana bastaria con pasar la tarjeta cada
 * cincuenta segundos para prolongarla indefinidamente. Que el tipo solo pueda
 * llevar una {@see ClockingAction} —donde no hay ningun caso de rechazo— es lo
 * que hace imposible construir el estado equivocado, en vez de confiar en que
 * el adaptador se acuerde de filtrar.
 *
 * **No conoce el reloj** (regla dura 2): `occurredAt` es el momento real del
 * escaneo (regla dura 9), nunca la hora de recepcion.
 */
final readonly class AcceptedScan
{
    public function __construct(
        /** `scan_events.occurred_at`, en UTC (reglas duras 3 y 9). */
        public DateTimeImmutable $occurredAt,
        /** Que hizo con la jornada. Nunca un rechazo: por eso el tipo es {@see ClockingAction}. */
        public ClockingAction $action,
        /**
         * El tramo que este escaneo abrio o cerro.
         *
         * **Nulo solo en un caso que el producto no produce hoy**: todo escaneo
         * aceptado escribe su `shift_entry_id` y el `CHECK`
         * `scan_events_chk_rejected_has_no_shift_entry` lo respalda por el otro
         * lado. Se admite el nulo porque un dato importado o corregido a mano
         * podria no tenerlo, y en ese caso hay una respuesta correcta que dar
         * —no se puede continuar una jornada que no se sabe cual es, asi que se
         * empieza una— en lugar de reventar en el camino de fichaje, que es lo
         * que regla dura 19 prohibe.
         */
        public ?string $shiftEntryUuid = null,
    ) {
        TimeRange::assertUtc('occurredAt', $occurredAt);
    }

    /**
     * El tramo cuya jornada habria que continuar si este escaneo dejo una
     * **pausa en curso**, o `null` si no la dejo.
     *
     * Devuelve el tramo y no un booleano porque las dos condiciones son una
     * sola pregunta: un `break_start` del que no consta el tramo **no deja nada
     * que continuar**, ya que la jornada a la que volver es precisamente la de
     * ese tramo (ADR-024, consecuencia 2) y sin el la unica forma de nombrarla
     * seria la fecha civil, que es el error que ese ADR existe para impedir.
     * Con un booleano, quien preguntara tendria que volver a leer el campo y
     * convencer al tipado de que esta vez no es nulo.
     */
    public function breakInProgressShiftEntry(): ?string
    {
        return $this->action === ClockingAction::BREAK_START ? $this->shiftEntryUuid : null;
    }

    /** Segundos entre este escaneo y otro instante, sin signo (RF-AT-06). */
    public function distanceInSecondsTo(DateTimeImmutable $instant): int
    {
        return abs($this->occurredAt->getTimestamp() - $instant->getTimestamp());
    }

    /**
     * Si este escaneo ocurrio **antes o a la vez** que ese instante.
     *
     * Existe porque {@see distanceInSecondsTo()} mide magnitud y hay una regla
     * —la continuacion de la pausa— que necesita ademas el **orden**: la cola
     * offline puede sincronizar un escaneo cuyo `occurred_at` es anterior al del
     * ultimo registrado (regla dura 9), y un escaneo que ocurrio ANTES de la
     * pausa no puede ser la vuelta de esa pausa por muy cerca que este.
     *
     * El caso de igualdad cuenta como «antes»: dos marcas en el mismo segundo no
     * estan desordenadas, y de descartar la segunda ya se encarga el anti-rebote
     * de RF-AT-06, que es quien tiene esa competencia.
     */
    public function precedes(DateTimeImmutable $instant): bool
    {
        return $this->occurredAt->getTimestamp() <= $instant->getTimestamp();
    }
}
