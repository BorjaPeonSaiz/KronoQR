// Presentacion del tiempo.
//
// Regla dura 3: todo instante llega en UTC. La conversion a la zona del centro
// ocurre AQUI y en ningun otro sitio, y la zona se pasa siempre de forma
// explicita: **nunca se usa la del navegador de quien mira**. Un responsable que
// abre el panel desde su casa en Canarias no puede ver las horas de un hotel de
// Madrid corridas una hora.
//
// Las fechas civiles (`hired_at`, `terminated_at`) NO son instantes y no se
// convierten: un `2026-08-14` es el 14 de agosto en cualquier zona.

/** Zona de reserva cuando todavia no se sabe la del centro. Nunca la del navegador. */
export const FALLBACK_TIMEZONE = 'UTC'

const CIVIL_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/

function parseInstant(value: string): Date | null {
  const parsed = new Date(value)

  return Number.isNaN(parsed.getTime()) ? null : parsed
}

/**
 * Un instante UTC en la zona del centro: fecha y hora con minutos, sin segundos
 * ni decimales. Nunca redondea nada hacia arriba.
 */
export function formatInstant(value: string, timeZone: string, locale: string): string {
  const instant = parseInstant(value)

  if (instant === null) {
    return ''
  }

  return new Intl.DateTimeFormat(locale, {
    timeZone,
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(instant)
}

/**
 * La etiqueta corta de la zona (`CEST`, `WEST`) para acompañar a la hora. En un
 * listado que cruza centros, la hora sin zona es una hora inventada.
 */
export function formatZoneLabel(value: string, timeZone: string, locale: string): string {
  const instant = parseInstant(value) ?? new Date()

  const part = new Intl.DateTimeFormat(locale, { timeZone, timeZoneName: 'short' })
    .formatToParts(instant)
    .find((candidate) => candidate.type === 'timeZoneName')

  return part?.value ?? timeZone
}

/** Instante y zona juntos: `20/8/26, 9:14 (CEST)`. */
export function formatInstantWithZone(value: string, timeZone: string, locale: string): string {
  const formatted = formatInstant(value, timeZone, locale)

  return formatted === '' ? '' : `${formatted} (${formatZoneLabel(value, timeZone, locale)})`
}

/**
 * Una fecha civil `YYYY-MM-DD`. Se formatea en UTC a proposito: asi el dia que
 * se enseña es exactamente el que trae el dato, sin desplazarlo ninguna zona.
 */
export function formatCivilDate(value: string, locale: string): string {
  if (!CIVIL_DATE_PATTERN.test(value)) {
    return ''
  }

  return new Intl.DateTimeFormat(locale, { timeZone: 'UTC', dateStyle: 'medium' }).format(
    new Date(`${value}T00:00:00Z`),
  )
}

/** La fecha civil de hoy en la zona del centro, para preseleccionar un formulario. */
export function todayInZone(timeZone: string, now: Date = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now)

  return parts
}

// --- LocalTimestamp del contrato --------------------------------------------
//
// Algunas respuestas traen cada instante DOS veces: en UTC y ya resuelto en la
// zona del centro, con el desplazamiento escrito (`LocalTimestamp`). Cuando el
// servidor lo manda resuelto, la hora que se pinta **se lee, no se convierte**:
// convertirla otra vez seria repetir en el navegador un calculo que ya se hizo
// con la zona buena, y en una noche de cambio de hora las dos cuentas no tienen
// por que dar lo mismo.
//
// Por eso esto es analisis de texto y no `new Date(...)`: un `Date` obliga a
// elegir una zona para volver a formatear, y la unica que hay a mano en el
// navegador es la equivocada.

/** Las partes de un `LocalTimestamp`, tal y como vienen escritas. */
export interface LocalTimestampParts {
  /** Fecha civil en la zona del centro, `YYYY-MM-DD`. */
  date: string
  /** Hora y minutos, `HH:MM`. Sin segundos: una nomina no los mira. */
  time: string
  /** Desplazamiento respecto a UTC, `+01:00`. */
  offset: string
}

const LOCAL_TIMESTAMP_PATTERN =
  /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}):\d{2}(?:\.\d{1,6})?([+-]\d{2}:\d{2})$/

/** Lee un `LocalTimestamp`. `null` si no lo es: nunca se adivina una hora. */
export function readLocalTimestamp(value: string): LocalTimestampParts | null {
  const match = LOCAL_TIMESTAMP_PATTERN.exec(value)

  if (match === null) {
    return null
  }

  const [, date, time, offset] = match

  return date === undefined || time === undefined || offset === undefined
    ? null
    : { date, time, offset }
}

/** `06:00` de un `LocalTimestamp`, sin convertir nada. Vacio si el valor no lo es. */
export function formatLocalTime(value: string): string {
  return readLocalTimestamp(value)?.time ?? ''
}

/** `05:00` de un instante UTC, para enseñar la marca tal y como esta almacenada. */
export function formatUtcTime(value: string): string {
  const instant = parseInstant(value)

  if (instant === null) {
    return ''
  }

  return new Intl.DateTimeFormat('en-GB', {
    timeZone: 'UTC',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(instant)
}

/**
 * Minutos entre dos instantes UTC, redondeados hacia abajo. Es una DURACION, no
 * una conversion de zona: sirve para decir cuanto tardo en llegar un fichaje que
 * viajo en la cola del quiosco (regla dura 9). `null` si falta alguno.
 */
export function minutesBetween(from: string, to: string | null): number | null {
  const start = parseInstant(from)
  const end = to === null ? null : parseInstant(to)

  if (start === null || end === null) {
    return null
  }

  return Math.floor((end.getTime() - start.getTime()) / 60_000)
}
