<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Exception\ScanIsNotOutOfOrder;
use App\Modules\Attendance\Domain\Model\WorkDay;
use DateTimeImmutable;

/**
 * El **fichaje irreconciliable** de RN-18 (glosario del doc 01 §13): un escaneo
 * que no puede encajar en la jornada, se repita las veces que se repita.
 *
 * Es la resolucion que devuelve {@see WorkDay::outOfOrderScanFor()}, y describe
 * **el tramo contra el que choca** y el instante del escaneo. Existe como objeto
 * de valor —y no como un `bool`— por lo que lleva dentro: esos instantes son lo
 * que una persona necesita para entender la incidencia y encontrar el fichaje en
 * el log. Un booleano obligaria a volver a buscarlos fuera, y esa segunda
 * busqueda podria no coincidir con la que tomo la decision.
 *
 * ## Dos formas, una por cada camino del fichaje
 *
 * - {@see beforeOpenEntry()} — **al cerrar**: el escaneo no es posterior a la
 *   entrada del turno abierto. Lo prohibe RN-03, que exige salida
 *   **estrictamente** posterior; por eso la igualdad tambien es irreconciliable.
 * - {@see overlappingClosedEntry()} — **al abrir**: el tramo que se crearia no
 *   tiene fin, asi que pisa a cualquier tramo ya cerrado que siga vivo despues
 *   de su inicio. Lo prohibe RN-02, con la semantica `[inicio, fin)` de la
 *   restriccion de exclusion; por eso aqui la igualdad **si** cuadra —entrar a
 *   la misma hora a la que se salio no es solapar—.
 *
 * Los dos limites se comportan al reves **porque los gobiernan dos reglas
 * distintas**, y eso es justo lo que este objeto deja escrito en un solo sitio.
 *
 * ## Los estados imposibles no se construyen
 *
 * El constructor es privado y cada camino tiene el suyo, que rechaza un escaneo
 * que **si** cuadra. Quien recibe uno de estos sabe por el tipo que RN-18 se
 * cumple; no hay ninguna rama donde haya que comprobarlo otra vez ni ningun
 * sitio donde olvidarse de hacerlo.
 *
 * ## No es un error: es un desenlace
 *
 * No hereda de ninguna excepcion y nadie lo lanza. El escaneo ocurrio de verdad
 * —alguien paso su tarjeta— y lo unico que el sistema no puede es derivar de el
 * un tramo. Por eso se registra con su resultado propio, se marca para revision
 * y espera a una correccion humana (RN-13), en vez de reintentarse para siempre
 * contra un servidor que siempre va a decir lo mismo (regla dura 19).
 *
 * **No conoce el reloj** (regla dura 2) ni la zona del centro: los instantes
 * llegan resueltos y en UTC, que es lo que hace que el veredicto sea el mismo
 * las dos noches del cambio de hora (RN-09).
 */
final readonly class OutOfOrderScan
{
    private function __construct(
        /** Entrada del tramo con el que este escaneo choca. */
        public DateTimeImmutable $openedAt,
        /** Salida de ese tramo, o `null` cuando el tramo sigue abierto. */
        public ?DateTimeImmutable $closedAt,
        /** `occurred_at` del escaneo: el momento real, nunca el de recepcion (regla dura 9). */
        public DateTimeImmutable $occurredAt,
    ) {}

    /**
     * Camino de cierre: el escaneo **no es posterior** a la entrada del turno
     * abierto, asi que no puede cerrarlo (RN-03).
     *
     * El limite es el de RN-03 leido al reves: si el escaneo fuera estrictamente
     * posterior, el tramo se podria cerrar y esto no seria un fichaje
     * irreconciliable. La igualdad SI lo es —daria un tramo de duracion cero,
     * que no es representable— y por eso el operador no es `>=`.
     */
    public static function beforeOpenEntry(DateTimeImmutable $openedAt, DateTimeImmutable $occurredAt): self
    {
        TimeRange::assertUtc('openedAt', $openedAt);
        TimeRange::assertUtc('occurredAt', $occurredAt);

        if ($occurredAt->getTimestamp() > $openedAt->getTimestamp()) {
            throw ScanIsNotOutOfOrder::afterOpenEntry($occurredAt, $openedAt);
        }

        return new self($openedAt, null, $occurredAt);
    }

    /**
     * Camino de apertura: el tramo que este escaneo abriria **pisaria a uno ya
     * cerrado** (RN-02).
     *
     * El tramo nuevo no tiene fin, asi que solapa con todo lo que siga vivo
     * despues de su inicio: la comprobacion es {@see TimeRange::endsAfter()}, la
     * misma que usa `WorkDay::guardNothingExtendsBeyond()` para lanzar. Que el
     * intervalo llegue como `TimeRange` no es comodidad: es lo que garantiza que
     * el tramo con el que se compara es un tramo posible (RN-03, UTC).
     */
    public static function overlappingClosedEntry(TimeRange $entry, DateTimeImmutable $occurredAt): self
    {
        TimeRange::assertUtc('occurredAt', $occurredAt);

        if (! $entry->endsAfter($occurredAt)) {
            throw ScanIsNotOutOfOrder::afterClosedEntry($occurredAt, $entry->end);
        }

        return new self($entry->start, $entry->end, $occurredAt);
    }
}
