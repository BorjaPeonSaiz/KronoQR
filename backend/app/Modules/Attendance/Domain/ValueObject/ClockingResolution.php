<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Exception\BreakEndWithoutShiftEntry;
use App\Modules\Attendance\Domain\Policy\ScanIntentPolicy;

/**
 * Lo que el dominio decidio que hace este escaneo: la accion **y**, cuando es
 * una vuelta de pausa, de que tramo viene.
 *
 * Es la salida de {@see ScanIntentPolicy}
 * y existe como objeto de valor —y no como un simple {@see ClockingAction}—
 * porque un `BREAK_END` sin saber a que jornada vuelve **no es una decision
 * completa**: por RN-05 y ADR-024 la jornada que continua es la del tramo que
 * cerro la pausa, en cualquier dia natural, y la fecha civil del escaneo no
 * sirve para encontrarla. Un enum no puede llevar ese dato.
 *
 * ## Los estados imposibles no se construyen
 *
 * Los constructores tienen nombre y el constructor es privado: la unica forma
 * de obtener un `BREAK_END` es dar el tramo del que viene, y la unica forma de
 * dar un tramo es que la accion sea `BREAK_END`. Asi el caso de uso no puede
 * llamar a `findWorkDayOfAnyShiftEntry(null)` ni olvidarse de llamarlo; el tipo lo
 * dice.
 *
 * **No conoce el reloj ni la jornada** (regla dura 2): decide sobre hechos que
 * recibe.
 */
final readonly class ClockingResolution
{
    private function __construct(
        public ClockingAction $action,
        /** Tramo cuya jornada continua este escaneo. No nulo **solo** si la accion es `BREAK_END`. */
        public ?string $continuesShiftEntryUuid,
    ) {}

    /** RF-AT-02: se abre jornada, o se abre un tramo mas de la jornada en curso. */
    public static function clockIn(): self
    {
        return new self(ClockingAction::CLOCK_IN, null);
    }

    /** RF-AT-03: se cierra el tramo abierto y la jornada queda terminada. */
    public static function clockOut(): self
    {
        return new self(ClockingAction::CLOCK_OUT, null);
    }

    /** RF-AT-12: se cierra el tramo abierto y la jornada **sigue viva**. */
    public static function breakStart(): self
    {
        return new self(ClockingAction::BREAK_START, null);
    }

    /**
     * RF-AT-12 y RN-05: se abre un tramo en la jornada del tramo que la pausa
     * cerro, **aunque hayan cambiado de dia natural**.
     *
     * El `uuid` no es decorativo: es lo que el caso de uso pasa a
     * `WorkDayRepository::findWorkDayOfAnyShiftEntry()` en lugar de buscar por
     * la fecha del escaneo. Sin el, una pausa de las 02:00 a las 02:30 en un
     * turno 22:00 -> 06:00 partiria la jornada en dos —240 min el dia D y 210 el
     * D+1— que es exactamente lo que ADR-024 y la regla dura 4 prohiben.
     *
     * **Que ese tramo siga vigente no es asunto de aqui.** Entre la pausa y la
     * vuelta puede haber pasado una correccion (RN-13) que lo dejo `superseded`,
     * y su jornada sigue siendo la misma: por eso la consulta es la que admite
     * tramos retirados. Y si el `uuid` no existiera —purga por retencion—, quien
     * llama degrada a { self::clockIn()} sobre la jornada de la fecha y marca
     * el escaneo para revision humana, nunca falla (regla dura 19).
     *
     * @throws BreakEndWithoutShiftEntry si se intenta una vuelta de pausa sin decir de donde vuelve
     */
    public static function breakEnd(string $shiftEntryUuid): self
    {
        if (trim($shiftEntryUuid) === '') {
            throw BreakEndWithoutShiftEntry::create();
        }

        return new self(ClockingAction::BREAK_END, $shiftEntryUuid);
    }

    /**
     * El tramo cuya jornada hay que cargar, o `null` si este escaneo no
     * continua ninguna.
     *
     * Es la pregunta que el caso de uso hace antes de elegir entre
     * `findWorkDayOfAnyShiftEntry()` y el camino de siempre
     * (`findOpenWorkDayFor() ?? findWorkDayFor() ?? start()`).
     */
    public function continuesWorkDayOf(): ?string
    {
        return $this->continuesShiftEntryUuid;
    }

    public function opensEntry(): bool
    {
        return $this->action->opensEntry();
    }

    public function closesEntry(): bool
    {
        return $this->action->closesEntry();
    }
}
