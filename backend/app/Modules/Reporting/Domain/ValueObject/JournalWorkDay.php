<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Una jornada del detalle de RF-PA-03: sus tramos vigentes, su total y por que
 * cambio.
 *
 * ## La suma cuadra porque el total ES la suma
 *
 * `totalMinutes()` no es un campo que alguien rellena: se calcula sumando los
 * tramos vigentes, que es literalmente lo que dice RN-06 —*«el total se
 * recalcula como suma de los tramos»*— y lo mismo que hace el agregado antes de
 * emitir el evento que alimenta `daily_totals`. Asi el panel no puede pintar un
 * total que no cuadre con sus partes, que es lo que el principio «el dato tiene
 * consecuencias» prohibe, y no hay redondeo que pueda desajustarlo: los minutos
 * son enteros desde el esquema.
 *
 * **`daily_totals` no es la fuente aqui, y es deliberado.** Es una proyeccion
 * reconstruible (regla dura 7) y el registro con valor probatorio son los
 * tramos. Leer el total de la proyeccion haria que una divergencia —que RN-06 y
 * la reconciliacion de RF-PR-02 existen para que no ocurra— se enseñara en
 * pantalla como si fuera el registro. De la proyeccion se lee solo
 * `recalculatedAt`, que es informacion sobre la propia proyeccion y no sobre la
 * jornada.
 *
 * ## Nada desaparece
 *
 * `shiftEntries` trae solo las versiones vigentes, pero una jornada cuyos tramos
 * se anularon todos **sigue existiendo**: lista vacia, total cero y su historico
 * intacto en `corrections` (regla dura 5). Ocultarla haria desaparecer de la
 * pantalla justo el dia que alguien necesita explicar.
 */
final readonly class JournalWorkDay
{
    /**
     * @param  list<JournalShiftEntry>  $shiftEntries  Solo las vigentes, por hora de entrada.
     * @param  list<JournalCorrection>  $corrections  Toda la cadena, de la mas antigua a la mas reciente.
     */
    public function __construct(
        /** Fecha civil de la jornada, `YYYY-MM-DD` en la zona del centro (RN-05). */
        public string $workDate,
        public string $timeZone,
        /** Nulo si la jornada todavia no tiene fila en `daily_totals`. */
        public ?DateTimeImmutable $recalculatedAt,
        public array $shiftEntries,
        public array $corrections,
    ) {
        if ($workDate === '') {
            throw new InvalidArgumentException('Una jornada del detalle necesita su fecha.');
        }

        if ($timeZone === '') {
            throw new InvalidArgumentException('Una jornada sin zona horaria obligaria al cliente a adivinarla (regla dura 3).');
        }
    }

    /**
     * Minutos trabajados en la jornada (RN-06). Un turno abierto aporta cero.
     */
    public function totalMinutes(): int
    {
        $total = 0;

        foreach ($this->shiftEntries as $entry) {
            $total += $entry->contributedMinutes();
        }

        return $total;
    }

    /**
     * Numero de tramos vigentes. Es la longitud de `shiftEntries` y no un
     * contador aparte: dos formas de contar lo mismo acaban discrepando.
     */
    public function shiftCount(): int
    {
        return \count($this->shiftEntries);
    }

    /**
     * RN-01: hay un turno sin cerrar. El panel tiene que decirlo, porque el
     * total de esta jornada todavia va a subir.
     */
    public function hasOpenShift(): bool
    {
        foreach ($this->shiftEntries as $entry) {
            if ($entry->clockedOutAt === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * RN-07 y RN-08: algun tramo quedo clasificado como `anomalous`. **No es un
     * error**: el tramo esta cerrado con sus marcas reales y lo que dice es que
     * una persona tiene que mirarlo.
     */
    public function hasIncident(): bool
    {
        foreach ($this->shiftEntries as $entry) {
            if ($entry->status === 'anomalous') {
                return true;
            }
        }

        return false;
    }
}
