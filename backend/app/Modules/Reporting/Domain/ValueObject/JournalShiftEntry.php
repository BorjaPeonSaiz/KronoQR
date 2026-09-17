<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Un tramo **vigente** del detalle de jornada (RF-PA-03).
 *
 * ## Las dos marcas, y las dos horas de cada una
 *
 * Regla dura 9: `clockedInAt` es cuando la persona ficho —lo que vale para el
 * registro legal— y `clockInRecordedAt` cuando el servidor lo recibio. Si el
 * fichaje viajo en la cola offline del quiosco (RF-KI-04), se diferencian en
 * horas, y esa diferencia es lo que el panel tiene que poder explicar en lugar
 * de esconder.
 *
 * `recordedAt` es una tercera marca y no una repeticion: dice cuando se escribio
 * **esta version de la fila**. En una version corregida es el momento de la
 * correccion; en una fichada, el del fichaje.
 *
 * ## Quien lo abrio y quien lo cerro (RF-AT-12, tarea 3.5)
 *
 * Con el fichaje de pausa, dos tramos consecutivos pueden ser **una sola
 * jornada con un descanso en medio** o dos jornadas distintas, y en la tabla se
 * ven exactamente igual: dos filas con un hueco. `openedBy` y `closedBy` son lo
 * que los distingue, y salen de `scan_events.result` —no de una heuristica
 * sobre el hueco—. Un tramo cerrado con `break_start` y el siguiente abierto con
 * `break_end` son una pausa; sin eso, el panel y el portal solo podrian enseñar
 * un hueco mudo que cada cual interpretaria a su manera (ADR-024, consecuencias).
 *
 * El tiempo de la pausa **no esta en ningun tramo**, asi que la duracion del dia
 * ya era correcta antes de esta tarea: lo que faltaba era poder explicarla.
 *
 * ## Un turno nocturno es un tramo
 *
 * Nada aqui parte un tramo a medianoche (RN-05, ADR-006, regla dura 4): un
 * 22:00 → 06:00 es una sola instancia de esta clase, en la jornada de su hora de
 * inicio.
 *
 * ## La zona horaria viaja con el tramo
 *
 * Y no con la jornada: un traslado de centro no reescribe donde ocurrieron las
 * jornadas anteriores, asi que el tramo conserva su `siteId` y su zona. La
 * conversion a hora local ocurre en la capa de presentacion (regla dura 3); aqui
 * el instante esta en UTC y la zona es un dato mas.
 */
final readonly class JournalShiftEntry
{
    /** Los dos escaneos que abren un tramo (`ClockingAction::opensEntry()`). */
    public const string OPENED_BY_CLOCK_IN = 'clock_in';

    public const string OPENED_BY_BREAK_END = 'break_end';

    /** Los dos que lo cierran. Un tramo abierto no tiene ninguno. */
    public const string CLOSED_BY_CLOCK_OUT = 'clock_out';

    public const string CLOSED_BY_BREAK_START = 'break_start';

    public function __construct(
        /** Identificador de ESTA version (ADR-035): el que acepta `PATCH /shift-entries/{uuid}`. */
        public string $uuid,
        public int $version,
        /** `open`, `closed` o `anomalous`: las vigentes. Nunca `voided` ni `superseded`. */
        public string $status,
        public int $siteId,
        /** Zona IANA del centro donde se ficho (`sites.timezone`). */
        public string $timeZone,
        public DateTimeImmutable $clockedInAt,
        public ?DateTimeImmutable $clockInRecordedAt,
        public string $clockInSource,
        public ?DateTimeImmutable $clockedOutAt,
        public ?DateTimeImmutable $clockOutRecordedAt,
        public ?string $clockOutSource,
        /** Nulo mientras el turno sigue abierto. Entonces aporta CERO al total del dia. */
        public ?int $durationMinutes,
        /** Cuando el servidor escribio esta version de la fila. */
        public DateTimeImmutable $recordedAt,
        /**
         * Que escaneo abrio este tramo: `clock_in` o `break_end` (RF-AT-12,
         * ADR-024, tarea 3.5).
         *
         * Sale de `scan_events.result` del escaneo que lo abrio. **Nunca es
         * nulo**: un tramo existe porque alguien entro, y un tramo declarado o
         * corregido a mano —que no tiene escaneo detras— va como `clock_in`,
         * que es lo que de hecho ocurrio. Es lo que permite al panel y al portal
         * enseñar «pausa» entre dos tramos en vez de un hueco mudo.
         */
        public string $openedBy = self::OPENED_BY_CLOCK_IN,
        /**
         * Que escaneo lo cerro: `clock_out`, `break_start`, o `null` mientras
         * siga abierto.
         *
         * `break_start` significa que la jornada **sigue viva** y que el tramo
         * siguiente vendra con `openedBy = 'break_end'`. Nulo **solo** si el
         * tramo sigue abierto: uno cerrado a mano, sin escaneo detras, va como
         * `clock_out`, y el constructor lo exige para que la respuesta no pueda
         * decir «sigue abierto» sobre un tramo que tiene hora de salida.
         */
        public ?string $closedBy = null,
    ) {
        if ($uuid === '') {
            throw new InvalidArgumentException('Un tramo del detalle de jornada necesita su identificador publico.');
        }

        if ($version < 1) {
            throw new InvalidArgumentException('La version de un tramo empieza en 1, y llego '.$version.'.');
        }

        if ($siteId < 1) {
            throw new InvalidArgumentException('Un tramo se ficho en un centro concreto.');
        }

        if ($timeZone === '') {
            throw new InvalidArgumentException('Un tramo sin zona horaria obligaria al cliente a adivinarla (regla dura 3).');
        }

        if ($durationMinutes !== null && $durationMinutes < 0) {
            throw new InvalidArgumentException('Un tramo no puede haber durado '.$durationMinutes.' minutos.');
        }

        $this->assertClockingMarks($openedBy, $closedBy, $clockedOutAt);
    }

    /**
     * Las tres reglas de `openedBy` y `closedBy` (RF-AT-12, ADR-024).
     *
     * En un metodo propio y no en el constructor porque son las de la tarea 3.5
     * y forman una sola idea —«que abrio y que cerro este tramo, y si eso encaja
     * con que este cerrado»— frente a las cinco comprobaciones de identidad que
     * el constructor ya tenia. El limite de complejidad del §3.5 lo obliga y de
     * paso lo deja mejor leido.
     */
    private function assertClockingMarks(string $openedBy, ?string $closedBy, ?DateTimeImmutable $clockedOutAt): void
    {
        if (! in_array($openedBy, [self::OPENED_BY_CLOCK_IN, self::OPENED_BY_BREAK_END], true)) {
            throw new InvalidArgumentException('Un tramo se abre con clock_in o con break_end, no con «'.$openedBy.'».');
        }

        if ($closedBy !== null && ! in_array($closedBy, [self::CLOSED_BY_CLOCK_OUT, self::CLOSED_BY_BREAK_START], true)) {
            throw new InvalidArgumentException('Un tramo se cierra con clock_out o con break_start, no con «'.$closedBy.'».');
        }

        // Las dos mitades de la misma verdad: si hay hora de salida, algo lo
        // cerro —un escaneo o una correccion— y el contrato lo declara
        // obligatorio. Un nulo aqui haria que el panel pintara «en curso» un
        // tramo terminado.
        if (($clockedOutAt instanceof DateTimeImmutable) !== ($closedBy !== null)) {
            throw new InvalidArgumentException(
                'Un tramo con hora de salida tiene que decir que lo cerro, y uno abierto no puede decirlo.'
            );
        }
    }

    /**
     * Lo que este tramo aporta al total del dia.
     *
     * Un turno abierto aporta cero: inventarle una duracion seria dar por
     * terminado lo que no ha terminado, y ese numero acaba en una nomina.
     */
    public function contributedMinutes(): int
    {
        return $this->durationMinutes ?? 0;
    }
}
