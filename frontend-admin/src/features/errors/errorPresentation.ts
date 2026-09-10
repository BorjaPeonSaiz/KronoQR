// Presentacion pura (sin Vue) del historico de errores (RF-PD-15, tarea 5.12):
// el color del nivel, el periodo en UTC y el «que hacer» por origen y nivel.
// Se prueba sin montar `ErrorsView`, igual que `incidentPresentation.ts`.
import { minutesBetween } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import type { DurationParts } from '@kronoqr/web-kit/workdayTotals'
import type { ErrorLevel, ErrorSource } from '@/shared/api/types'

// LA SEVERIDAD SE DICE CON PALABRAS, NO SOLO CON COLOR (WCAG 2.2 AA, 1.4.1): el
// texto («Error», «Crítico») va siempre junto al color de fondo, nunca solo.
// Ningun color propio (doc 06 §7): tokens `--kq-*` compartidos con el resto
// del panel. `critical` es lo mas grave -danger-, `error` es aviso.
const LEVEL_CLASSES: Record<ErrorLevel, string> = {
  critical: 'bg-kq-danger-soft text-kq-danger',
  error: 'bg-kq-warning-soft text-kq-warning',
}

export function levelBadgeClass(level: ErrorLevel): string {
  return LEVEL_CLASSES[level]
}

/**
 * Clave de i18n del bloque «que hacer» de un grupo, por origen y nivel
 * (decision 13 de la ficha 5.12). Cada combinacion tiene su propio texto,
 * escrito para quien no conoce el sistema: `locales/{es,en}.json`,
 * `errorEvents.whatToDo.<source>.<level>`.
 */
export function whatToDoKey(source: ErrorSource, level: ErrorLevel): string {
  return `errorEvents.whatToDo.${source}.${level}`
}

/**
 * Cuanto ha pasado desde `lastSeenAt`, contra el reloj del SERVIDOR (regla
 * dura 3), en horas y minutos enteros -nunca decimales-. Nunca negativo: un
 * reloj local ligeramente adelantado no puede dar una antiguedad negativa.
 */
export function ageSinceLastSeen(lastSeenAt: string, serverNowMs: number): DurationParts {
  // El `Math.max` SI hace falta aqui, a diferencia de `IncidentTable`/
  // `PresenceTable`: `last_seen_at` lo declara el cliente que reporto el
  // error (una tablet, un navegador), con su propio reloj, y puede llegar
  // adelantado respecto al `serverNowMs` extrapolado antes de la primera
  // foto del servidor.
  const minutes = Math.max(minutesBetween(lastSeenAt, new Date(serverNowMs).toISOString()) ?? 0, 0)

  return durationParts(minutes)
}

/** Los presets de periodo que ofrece el filtro. `custom` exige `from`/`to` propios. */
export type ErrorPeriodPreset = '7' | '30' | '90' | 'custom'

export interface ErrorPeriodCustomInput {
  /** Fecha civil `YYYY-MM-DD`, o cadena vacia si no se ha escrito. */
  from: string
  to: string
}

export interface ErrorPeriodBounds {
  from?: string
  to?: string
}

/**
 * Los limites `from`/`to` que se mandan al servidor para un preset de periodo.
 *
 * `nowMs` tiene que ser el reloj del SERVIDOR extrapolado
 * (`errors.store.ts#serverNowMs`), nunca `Date.now()` a secas: con un reloj
 * local atrasado, un `to` calculado sobre el navegador dejaba fuera errores
 * recien creados que el servidor ya conocia (hallazgo I4 del cierre de la
 * Fase 5). Los presets (7/30/90 dias) **no llevan `to`**: son «los ultimos N
 * dias», sin cota superior, para que un error que acaba de ocurrir -aunque el
 * reloj local siga desfasado tras la extrapolacion- nunca quede fuera por una
 * cota que no aporta nada (`from` ya acota la ventana por el lado que
 * importa). El periodo `custom` se declara **en UTC explicito** -nunca en la
 * zona del navegador, que seria adivinar (regla dura 3)-: la fecha civil que
 * se escribe se interpreta como el dia calendario en UTC, y los campos lo
 * dejan escrito («Desde (UTC)»). Ahi si tiene sentido un `to`: es la fecha que
 * la persona ha escrito a proposito, no un limite calculado.
 */
export function periodBounds(
  preset: ErrorPeriodPreset,
  custom: ErrorPeriodCustomInput,
  nowMs: number,
): ErrorPeriodBounds {
  if (preset === 'custom') {
    return {
      ...(custom.from === '' ? {} : { from: `${custom.from}T00:00:00.000Z` }),
      ...(custom.to === '' ? {} : { to: `${custom.to}T23:59:59.999Z` }),
    }
  }

  const days = Number.parseInt(preset, 10)

  return { from: new Date(nowMs - days * 86_400_000).toISOString() }
}
