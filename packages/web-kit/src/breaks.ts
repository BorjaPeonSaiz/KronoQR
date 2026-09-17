// La pausa entre dos tramos, compartida por panel y portal (ADR-036, tarea
// 3.5, ADR-024). Antes de esta migracion cada SPA llevaba su propia copia y
// ya habian divergido: el panel devolvia `null` sin fila y formateaba con
// `durationParts` («1 h 30 min»); el portal usaba `?? 0` -silenciaba un
// fallo de analisis en vez de ocultar la fila- y enseñaba el minuto crudo
// («90 min»). Es exactamente el riesgo que ADR-036 existe para eliminar: una
// regla de negocio no puede vivir en dos sitios.
//
// Pura, sin Vue y sin i18n (mismo criterio que `workdayTotals.ts`): la resta
// de dos instantes no puede equivocarse dos veces.
import { formatLocalTime, minutesBetween } from './datetime'

/** Lo minimo del tramo ANTERIOR que hace falta para saber si cerro en pausa. */
export interface ShiftEntryClosingBreak {
  readonly closed_by: string | null
  readonly clocked_out_at: string | null
  readonly clocked_out_at_local: string | null
}

/** Lo minimo del tramo SIGUIENTE que hace falta para saber si continua una pausa. */
export interface ShiftEntryOpeningBreak {
  readonly opened_by: string
  readonly clocked_in_at: string
  readonly clocked_in_at_local: string
}

/** La pausa entre dos tramos: hora local de salida, hora local de vuelta, y su duracion en minutos. */
export interface BreakBetween {
  readonly from: string
  readonly to: string
  readonly minutes: number
}

/**
 * La pausa entre `previous` y `next`, o `null` si no la hay.
 *
 * Solo la marcan DOS escaneos concretos, no un hueco cualquiera (ADR-024):
 * `previous` cerrado por `break_start` y `next` abierto por `break_end`. Un
 * tramo corregido o dado de alta a mano entre los dos rompe la pareja y aqui
 * no aparece nada -es lo correcto: no hubo pausa fichada, hubo un tramo
 * nuevo-. `minutesBetween` puede devolver `null` si alguna marca no se puede
 * analizar (no deberia pasar con el contrato); entonces tampoco hay pausa
 * que enseñar, nunca un `NaN` ni un `0` que finja una duracion que no se
 * pudo calcular.
 */
export function breakBetween(
  previous: ShiftEntryClosingBreak,
  next: ShiftEntryOpeningBreak,
): BreakBetween | null {
  if (
    previous.closed_by !== 'break_start' ||
    next.opened_by !== 'break_end' ||
    previous.clocked_out_at === null ||
    previous.clocked_out_at_local === null
  ) {
    return null
  }

  const minutes = minutesBetween(previous.clocked_out_at, next.clocked_in_at)

  if (minutes === null) {
    return null
  }

  return {
    from: formatLocalTime(previous.clocked_out_at_local),
    to: formatLocalTime(next.clocked_in_at_local),
    minutes,
  }
}
