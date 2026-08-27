// Comprobaciones del rango de jornadas pedido, compartidas por las SPA del
// panel y del portal (ADR-036): las dos piden un rango de jornadas
// (`GET /employees/{uuid}/workdays` en el panel; `GET /me/workdays` y
// `GET /me/export` en el portal) con la misma forma y el mismo techo de
// contrato.
//
// Son fechas **civiles** (`YYYY-MM-DD`), no instantes: aqui no interviene
// ninguna zona horaria y por eso la cuenta se hace en UTC contra `Date.parse`,
// que es aritmetica de calendario y no una conversion.
//
// Lo que se comprueba aqui lo vuelve a comprobar el servidor. Se hace antes de
// llamar solo para no gastar una peticion —y una espera— en decir algo que ya se
// sabe.

/** Un rango de jornadas, por su `work_date` de inicio y fin, ambos inclusive. */
export interface DateRange {
  from: string
  to: string
}

/** Techo del contrato: el rango no puede pedir mas de 366 jornadas de una vez. */
export const MAX_RANGE_DAYS = 366

const CIVIL_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/

function toUtcDays(value: string): number | null {
  if (!CIVIL_DATE_PATTERN.test(value)) {
    return null
  }

  const time = Date.parse(`${value}T00:00:00Z`)

  return Number.isNaN(time) ? null : Math.round(time / 86_400_000)
}

/** Jornadas que abarca el rango, ambas incluidas. `null` si falta o no vale alguna. */
export function rangeLengthInDays(range: DateRange): number | null {
  const from = toUtcDays(range.from)
  const to = toUtcDays(range.to)

  if (from === null || to === null) {
    return null
  }

  return to - from + 1
}

/** El periodo termina antes de empezar. No se da la vuelta solo: se avisa. */
export function isInvertedRange(range: DateRange): boolean {
  const length = rangeLengthInDays(range)

  return length !== null && length <= 0
}

export function exceedsMaxRange(range: DateRange): boolean {
  const length = rangeLengthInDays(range)

  return length !== null && length > MAX_RANGE_DAYS
}
