<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

/**
 * Lo que la persona **declaro** en el quiosco al pasar la tarjeta: los tres
 * valores de `scan_events.intent` (ADR-024, RF-AT-12, doc 01 §5.5).
 *
 * ## `intent` no es `result`, y confundirlos falsearia el registro
 *
 * `intent` es lo que el quiosco **pide** y {@see ClockingAction} lo que el
 * servidor **decidio**. Son dos campos y no uno porque el servidor no puede
 * deducir el primero: `break_start` y `clock_out` cierran el mismo tramo
 * (ADR-024, consecuencia 1). Y son dos campos **tambien cuando no coinciden**:
 * una intencion que contradice el estado de la jornada se resuelve por la
 * estructura y se registra tal cual, sin rechazar nada (regla dura 19) y sin
 * abrir incidencia. Quien revise el escaneo vera las dos cosas y podra juzgar.
 *
 * ## Por que es del dominio y `ScanIntent` no desaparece
 *
 * El enum gemelo `Application\Port\ScanIntent` nacio en la tarea 1.3 para que
 * la columna y la cola offline de Dexie existieran antes que la regla, y dejo
 * escrito que *«cuando la 3.5 haga que la intencion decida algo, sera un
 * concepto de dominio»*. Eso ocurre aqui: {@see \App\Modules\Attendance\Domain\
 * Policy\ScanIntentPolicy} razona sobre esto, y el dominio no puede nombrar
 * `Application\Port\` (ADR-025, restriccion 2, verificado por Deptrac). El
 * puerto conserva su enum —es el vocabulario de la columna y del contrato— y lo
 * traduce con `ScanIntent::declared()`.
 *
 * El valor de cada caso es el de la columna, su `CHECK` y el esquema
 * `ScanIntent` del contrato.
 */
enum DeclaredIntent: string
{
    /**
     * No se declaro nada: el servidor resuelve por el estado de la jornada.
     *
     * Es el valor por defecto del contrato y **el camino de casi todo el
     * mundo** (decision 5 de la ficha 3.5): quien entra, quien sale y quien
     * vuelve de la pausa solo pasa la tarjeta. Que `auto` siga resolviendo a
     * `BREAK_END` tras un `break_start` es lo que hace que la vuelta no anada
     * ningun paso.
     */
    case AUTO = 'auto';

    case BREAK_START = 'break_start';

    case BREAK_END = 'break_end';

    /**
     * Si esta intencion **deshace** lo que acaba de hacer esa accion.
     *
     * Es el predicado que protege la correccion de una pausa mal pulsada
     * (ADR-024, «`ATTENDANCE_DEBOUNCE_SECONDS` necesita revision»): un
     * `break_end` a los veinte segundos de un `break_start` no es un rebote, es
     * alguien arreglando su propio error, y tragarselo le costaria las cuatro
     * horas que el tramo siguiente deja de contar en lugar de los veinte
     * segundos que de verdad estaban en juego (regla dura 19).
     *
     * `AUTO` no deshace nada **a proposito**: sin declaracion explicita, dos
     * lecturas seguidas de la misma tarjeta siguen siendo un solo gesto, que es
     * lo que RF-AT-06 existe para descartar. Y una intencion repetida —dos
     * `break_start`— tampoco: repetir no es deshacer.
     */
    public function reverses(ClockingAction $action): bool
    {
        return match ($this) {
            self::BREAK_START => $action === ClockingAction::BREAK_END,
            self::BREAK_END => $action === ClockingAction::BREAK_START,
            self::AUTO => false,
        };
    }
}
