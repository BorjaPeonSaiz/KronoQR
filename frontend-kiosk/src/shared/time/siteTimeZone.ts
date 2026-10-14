// La hora de PARED del centro (V2-KI-01, RF-AT-05, RF-KI-07).
//
// El servidor manda en cada latido la zona del centro (`KioskHeartbeat.
// site_time_zone`, un nombre IANA como `Atlantic/Canary`). La tablet LA USA PARA
// PRESENTAR: la hora que ve el empleado en la confirmacion y la franja en la que
// puede actualizarse. No interviene en nada que se encole: `occurred_at` viaja
// siempre en UTC (regla dura 3).
//
// Hasta recibirla, o si el navegador no reconoce el nombre, se usa la zona de la
// tablet: nunca se deja de pintar la hora ni de decidir la puerta (regla dura
// 19). Por eso aqui `null`/`undefined`/nombre invalido significan lo mismo:
// «la zona del aparato».
//
// Se calcula con `Intl.DateTimeFormat` y `timeZone`, NO con `getHours()`, que
// lee la zona del aparato. El dia del cambio de hora el reloj de pared salta
// (la hora que no existe nunca se lee) o se repite (se lee dos veces): es lo
// que ve la gente en la pared, y es lo que se quiere.

const validityCache = new Map<string, boolean>()
const formatterCache = new Map<string, Intl.DateTimeFormat>()

/** `true` si el navegador reconoce `name` como zona horaria. */
export function isValidTimeZone(name: string): boolean {
  if (name === '') return false
  const cached = validityCache.get(name)
  if (cached !== undefined) return cached
  let valid: boolean
  try {
    new Intl.DateTimeFormat('en-GB', { timeZone: name })
    valid = true
  } catch {
    valid = false
  }
  validityCache.set(name, valid)
  return valid
}

/** La zona a usar: `name` si es valida; `undefined` (= la del aparato) en cualquier otro caso. */
export function resolveTimeZone(name: string | null | undefined): string | undefined {
  return typeof name === 'string' && isValidTimeZone(name) ? name : undefined
}

function wallClockFormatter(timeZone: string | undefined): Intl.DateTimeFormat {
  const key = timeZone ?? ''
  const cached = formatterCache.get(key)
  if (cached !== undefined) return cached
  const formatter = new Intl.DateTimeFormat('en-GB', {
    ...(timeZone === undefined ? {} : { timeZone }),
    hour: 'numeric',
    minute: 'numeric',
    hourCycle: 'h23',
  })
  formatterCache.set(key, formatter)
  return formatter
}

export interface WallClock {
  /** 0-23. */
  readonly hour: number
  /** 0-59. */
  readonly minute: number
}

/** Hora y minuto en el reloj de pared de `timeZone` (o del aparato). `null` si `instant` no es una fecha. */
export function wallClockOf(instant: Date, timeZone?: string | null): WallClock | null {
  if (Number.isNaN(instant.getTime())) return null
  const parts = wallClockFormatter(resolveTimeZone(timeZone)).formatToParts(instant)
  const hour = Number(parts.find((part) => part.type === 'hour')?.value)
  const minute = Number(parts.find((part) => part.type === 'minute')?.value)
  if (!Number.isFinite(hour) || !Number.isFinite(minute)) return null
  // Algunos motores devuelven `24` a medianoche con ciertos ciclos horarios.
  return { hour: hour % 24, minute }
}
