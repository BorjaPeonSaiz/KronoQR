<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

/**
 * **Como termino un tramo cerrado**: en una salida o en una pausa (RN-05,
 * RF-AT-12, ADR-024; 2.3.0).
 *
 * Es lo unico que distingue «se fue a casa» de «salio a descansar y vuelve»: con
 * las mismas marcas, el tramo que empieza al dia civil siguiente continua la
 * jornada solo si este termino en pausa. Sin el dato, un responsable podia
 * encadenar los turnos de dias seguidos en una sola jornada y esconder un
 * descanso insuficiente entre ellas (RN-10).
 *
 * Lo fija el escaneo («Pausa» cierra con `break_start`) o quien escribe el tramo
 * a mano (`ends_in_break`). Un tramo abierto no tiene cierre. Los valores son los
 * de `shift_entries.closed_by` y los de `scan_events.result` que cierran tramo.
 */
enum EntryClosure: string
{
    case CLOCK_OUT = 'clock_out';
    case BREAK_START = 'break_start';
}
