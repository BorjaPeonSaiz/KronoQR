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
