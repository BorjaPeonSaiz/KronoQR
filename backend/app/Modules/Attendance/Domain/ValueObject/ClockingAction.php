<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

/**
 * Lo que un escaneo **hace con la jornada**: las cuatro acciones de fichaje de
 * RF-AT-02, RF-AT-03 y RF-AT-12 (ADR-024).
 *
 * ## Por que sube al dominio ahora
 *
 * Hasta la tarea 3.5 este vocabulario vivia solo en `Application\Port\
 * ScanResult`, que es quien escribe `scan_events.result`, y el docblock de
 * aquel enum dejo escrito el criterio para moverlo: *«si una regla futura
 * tuviera que razonar sobre `clock_in` frente a `break_start`, el enum completo
 * subiria a `Domain/`»*. Esa regla es {@see \App\Modules\Attendance\Domain\
 * Policy\ScanIntentPolicy}, y ademas el anti-rebote de RF-AT-06 necesita saber
 * **que fue** el escaneo vecino para no tragarse una vuelta de pausa.
 *
 * Son dos enums y no uno por la restriccion 2 de ADR-025 leida al reves: el
 * dominio no puede nombrar `Application\Port\` (Deptrac: `AttendanceDomain` no
 * declara esa arista), pero el puerto si puede nombrar al dominio. Asi que el
 * concepto vive aqui y `ScanResult` lo traduce a la columna, igual que
 * {@see ScanRejectionReason} y la mitad de rechazo de aquel enum.
 *
 * ## Las cuatro, no dos
 *
 * `break_start` y `clock_out` son **estructuralmente identicos** —los dos
 * cierran el tramo abierto— y `break_end` y `clock_in` tambien (ADR-024). Lo
 * que los distingue no es lo que le pasa a `shift_entries`, sino lo que
 * significa: quien ficha la pausa sigue de jornada, y el registro tiene que
 * poder decirlo. Colapsarlos en dos haria que el informe, el portal y RN-12 no
 * pudieran distinguir una pausa de una salida, que es justo lo que RF-AT-12
 * viene a resolver.
 *
 * El valor de cada caso es el de la columna y el del contrato, para que no haya
 * dos alfabetos para lo mismo.
 */
enum ClockingAction: string
{
    /** RF-AT-02: abre tramo y, si no habia jornada, la abre tambien. */
    case CLOCK_IN = 'clock_in';

    /** RF-AT-03: cierra el tramo abierto y da por terminada la jornada. */
    case CLOCK_OUT = 'clock_out';

    /** RF-AT-12: cierra el tramo abierto **sin** dar por terminada la jornada. */
    case BREAK_START = 'break_start';

    /** RF-AT-12: abre un tramo nuevo **dentro de la jornada que la pausa dejo a medias**. */
    case BREAK_END = 'break_end';

    /**
     * Si esta accion crea un tramo.
     *
     * Es lo que el caso de uso traduce a `WorkDay::clockIn()`. Que se pregunte
     * aqui y no con un `match` en el handler es lo que impide que anadir una
     * quinta accion deje una rama sin cubrir en un `if` lejano.
     */
    public function opensEntry(): bool
    {
        return $this === self::CLOCK_IN || $this === self::BREAK_END;
    }

    /**
     * Si esta accion cierra el tramo abierto (`WorkDay::clockOut()`).
     *
     * Complementaria de {@see opensEntry()}: no hay accion de fichaje que no
     * haga una de las dos cosas, y esa es exactamente la razon por la que el
     * servidor no puede deducir la intencion (ADR-024, consecuencia 1).
     */
    public function closesEntry(): bool
    {
        return ! $this->opensEntry();
    }

    /**
     * Si la accion habla de una **pausa** y no de una jornada.
     *
     * Lo lee la resolucion de la intencion —una pausa continua la jornada— y lo
     * leera el detalle de jornada del panel y del portal (`opened_by` /
     * `closed_by` del contrato).
     */
    public function isBreak(): bool
    {
        return $this === self::BREAK_START || $this === self::BREAK_END;
    }

    /**
     * Si esta accion **continua una jornada ya abierta** en vez de empezar una.
     *
     * Es la mitad de ADR-024 que evita partir el turno de noche: un `break_end`
     * de las 02:30 pertenece a la jornada del dia anterior, no a la fecha civil
     * de su propio instante (RN-05, regla dura 4). La otra mitad —**cual**
     * jornada— la lleva {@see ClockingResolution}, porque un enum no puede
     * guardar el tramo del que viene.
     */
    public function continuesOpenWorkDay(): bool
    {
        return $this === self::BREAK_END;
    }
}
