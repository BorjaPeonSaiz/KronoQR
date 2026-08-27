// Aritmetica de la jornada. Pura, sin Vue y sin i18n, porque es la parte que no
// puede equivocarse: cada minuto que sale de aqui acaba en una nomina.
//
// Dos decisiones que no son de estilo:
//
//  1. **Nunca se muestran decimales.** `8,08 h` no significa nada para quien
//     revisa una nomina y ademas invita a redondear. Se dan horas y minutos
//     enteros, que es como llegan del servidor (RN-06, ADR-007).
//  2. **La suma de las partes se compara con el total, no se sustituye por el.**
//     Si el total del dia y la suma de sus tramos no coinciden, la pantalla lo
//     dice y enseña los dos numeros. Elegir uno en silencio seria tapar
//     exactamente el fallo que hace falta ver.
import type { WorkDayDetail, WorkDayShiftEntry } from '@/shared/api/types'

/**
 * Una duracion partida en horas y minutos, lista para un texto de i18n.
 *
 * Es un alias de tipo y no una interfaz a proposito: asi encaja como parametros
 * con nombre de `vue-i18n` sin escribir una firma de indice que no significa
 * nada.
 */
export type DurationParts = {
  hours: number
  /** Minutos con dos digitos: `05`, no `5`. `8 h 5 min` se lee mal a la primera. */
  minutes: string
}

export function durationParts(totalMinutes: number): DurationParts {
  const safe = Number.isFinite(totalMinutes) ? Math.max(Math.trunc(totalMinutes), 0) : 0

  return {
    hours: Math.floor(safe / 60),
    minutes: String(safe % 60).padStart(2, '0'),
  }
}

/**
 * Suma de los tramos vigentes.
 *
 * Un tramo abierto (`duration_minutes: null`) aporta **cero**: inventarle
 * minutos hasta «ahora» seria dar por trabajado lo que todavia se esta
 * trabajando, y ese numero acabaria cuadrando con nada.
 */
export function sumShiftMinutes(entries: readonly WorkDayShiftEntry[]): number {
  return entries.reduce((total, entry) => total + (entry.duration_minutes ?? 0), 0)
}

export interface WorkDayTotals {
  /** Lo que suman los tramos que se estan enseñando. */
  summed: number
  /** Lo que declara el servidor para el dia (`daily_totals`, RN-06). */
  declared: number
  /** Si las partes cuadran con el total. Cuando no, la pantalla lo dice. */
  agree: boolean
  /** Cuantos tramos siguen abiertos: explica por que el total todavia va a subir. */
  openEntries: number
}

export function workDayTotals(day: WorkDayDetail): WorkDayTotals {
  const summed = sumShiftMinutes(day.shift_entries)

  return {
    summed,
    declared: day.total_minutes,
    agree: summed === day.total_minutes,
    openEntries: day.shift_entries.filter((entry) => entry.duration_minutes === null).length,
  }
}
