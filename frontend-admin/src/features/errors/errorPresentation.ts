// Presentacion pura (sin Vue) del historico de errores (RF-PD-15, tarea 5.12):
// el color del nivel, el periodo en UTC y el «que hacer» por origen y nivel.
// Se prueba sin montar `ErrorsView`, igual que `incidentPresentation.ts`.
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
  const minutes = Math.max(Math.floor((serverNowMs - Date.parse(lastSeenAt)) / 60_000), 0)

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
 * Los presets (7/30/90 dias) se cuentan hacia atras desde el reloj del
 * NAVEGADOR: son solo un filtro de conveniencia sobre `last_seen_at`, no un
 * dato que se presente (regla dura 3 protege la PRESENTACION del tiempo; esto
 * es una consulta). El periodo `custom` se declara **en UTC explicito**
 * -nunca en la zona del navegador, que seria adivinar (regla dura 3)-: la
 * fecha civil que se escribe se interpreta como el dia calendario en UTC, y
 * los campos lo dejan escrito («Desde (UTC)»).
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

  return {
    from: new Date(nowMs - days * 86_400_000).toISOString(),
    to: new Date(nowMs).toISOString(),
  }
}
