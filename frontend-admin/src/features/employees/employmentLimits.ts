// Reglas del contrato de horas y del motivo de la correccion, en UN solo sitio: las comparten
// el alta de contrato (`ContractRegisterDialog`), la readmision (`RehireDialog`) y la ficha
// (`EmployeeContractsSection`). Si el contrato de la API cambia un tope, cambia aqui.
import type { Composer } from 'vue-i18n'
import type { ScheduleType } from '@/shared/api/types'

/** Los tipos de jornada del contrato (`ScheduleType`), en el orden en que se ofrecen. */
export const SCHEDULE_TYPES: readonly ScheduleType[] = ['continua', 'partida', 'turnos']

/**
 * Las horas como las lee RRHH: «37,5 h» (el numero en el idioma de la interfaz, con hasta dos
 * decimales) o «Sin valor» si no hay. `t` es el de `useI18n()`.
 */
export function formatHours(value: number | null, locale: string, t: Composer['t']): string {
  return value === null
    ? t('common.empty')
    : t('contracts.hoursValue', {
        hours: new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(value),
      })
}

/** Longitud del motivo de la correccion del cese (`TerminationDateCorrectionRequest.reason`). */
export const REASON_MIN_LENGTH = 10
export const REASON_MAX_LENGTH = 1000

/** Topes del contrato de readmision (`RehireContract`), los mismos que el alta de contrato. */
export const WEEKLY_HOURS_MAX = 168
export const ANNUAL_HOURS_MAX = 99999

/**
 * Un numero de horas escrito a mano (admite coma decimal), o `null` si esta vacio,
 * no es un numero, no es mayor que 0 o pasa de `max`.
 */
export function parsePositiveHours(raw: string, max: number): number | null {
  const normalised = raw.trim().replace(',', '.')

  if (normalised === '') {
    return null
  }

  const value = Number(normalised)

  return Number.isFinite(value) && value > 0 && value <= max ? value : null
}
