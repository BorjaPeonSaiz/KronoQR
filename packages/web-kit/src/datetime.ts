// Presentacion del tiempo, compartida por las SPA del panel y del portal
// (ADR-036).
//
// Regla dura 3: todo instante llega en UTC. La conversion de UTC a la zona
// del centro para LEERLO ocurre AQUI y en ningun otro sitio, y la zona se pasa
// siempre de forma explicita: **nunca se usa la del navegador de quien mira**.
// Un responsable que abre el panel desde su casa en Canarias, o un empleado
// que consulta el portal desde su movil en otro pais, tienen que ver las
// horas de SU centro, que son las que constan en el contrato de esa persona.
//
// La LECTURA de la hora de pared (`readWallClock`, `wallClockOf`) vive tambien
// aqui y es la unica: el quiosco la usa para el saludo y la ventana de
// actualizacion, y el panel para la conversion inversa. La direccion CONTRARIA
// -de lo que alguien teclea pensando en la hora del centro al `UtcTimestamp`
// que exige el contrato- no vive aqui: la necesita solo
// `frontend-admin/src/features/workdays/zonedTime.ts`, al corregir un tramo
// (RF-PA-04), y es un algoritmo bastante mas largo que el resto de este
// fichero; pero lee con `readWallClock`, no con una copia.
//
// Las fechas civiles (`hired_at`, `work_date`) NO son instantes y no se
// convierten: un `2026-08-14` es el 14 de agosto en cualquier zona.

/** Zona de reserva cuando todavia no se sabe la del centro. Nunca la del navegador. */
export const FALLBACK_TIMEZONE = 'UTC'

// --- Hora de pared en una zona -----------------------------------------------
//
// Calculada con `Intl.DateTimeFormat` y `timeZone`, NO con `getHours()`, que
// lee la zona del aparato. El dia del cambio de hora el reloj de pared salta
// (la hora que no existe nunca se lee) o se repite (se lee dos veces): es lo
// que ve la gente en la pared, y es lo que se quiere.

const validityCache = new Map<string, boolean>()
const wallClockFormatterCache = new Map<string, Intl.DateTimeFormat>()

/** `true` si el navegador reconoce `name` como zona horaria. */
export function isValidTimeZone(name: string): boolean {
  if (name === '') {
    return false
  }

  const cached = validityCache.get(name)

  if (cached !== undefined) {
    return cached
  }

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

/**
 * La zona a usar: `name` si es valida; `undefined` en cualquier otro caso, que
 * significa "la zona del aparato" (regla dura 19: el quiosco nunca deja de
 * pintar la hora). Si algun dia una SPA necesita otra zona de reserva, se
 * anade aqui un parametro; hoy nadie lo usa.
 */
export function resolveTimeZone(name: string | null | undefined): string | undefined {
  return typeof name === 'string' && isValidTimeZone(name) ? name : undefined
}

/** La hora de pared completa; `hour` es siempre 0-23 y `minute` 0-59. */
export interface WallClockParts {
  readonly year: number
  readonly month: number
  readonly day: number
  readonly hour: number
  readonly minute: number
}

function wallClockFormatter(timeZone: string | undefined): Intl.DateTimeFormat {
  const key = timeZone ?? ''
  const cached = wallClockFormatterCache.get(key)

  if (cached !== undefined) {
    return cached
  }

  const formatter = new Intl.DateTimeFormat('en-US', {
    ...(timeZone === undefined ? {} : { timeZone }),
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })

  wallClockFormatterCache.set(key, formatter)

  return formatter
}

/**
 * Que hora de pared lee `timeZone` en `instant` (milisegundos UTC o `Date`).
 * `timeZone` ya debe ser valida (si no, `Intl` lanza `RangeError`); `undefined`
 * es la zona del aparato. `null` si `instant` no es una fecha.
 */
export function readWallClock(
  instant: Date | number,
  timeZone: string | undefined,
): WallClockParts | null {
  const date = typeof instant === 'number' ? new Date(instant) : instant

  if (Number.isNaN(date.getTime())) {
    return null
  }

  const parts = wallClockFormatter(timeZone).formatToParts(date)
  const value = (type: Intl.DateTimeFormatPartTypes): number =>
    Number(parts.find((part) => part.type === type)?.value)

  const wall = {
    year: value('year'),
    month: value('month'),
    day: value('day'),
    hour: value('hour'),
    minute: value('minute'),
  }

  if (Object.values(wall).some((part) => !Number.isFinite(part))) {
    return null
  }

  // Algunos motores devuelven `24` a medianoche aun con `h23`.
  return { ...wall, hour: wall.hour % 24 }
}

/** Hora y minuto de pared. */
export interface WallClock {
  /** 0-23. */
  readonly hour: number
  /** 0-59. */
  readonly minute: number
}

/**
 * Hora y minuto en el reloj de pared de `timeZone`; si no es valida (o falta),
 * en la del aparato. `null` si `instant` no es una fecha.
 */
export function wallClockOf(instant: Date, timeZone?: string | null): WallClock | null {
  const wall = readWallClock(instant, resolveTimeZone(timeZone))

  return wall === null ? null : { hour: wall.hour, minute: wall.minute }
}

const CIVIL_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/

function parseInstant(value: string): Date | null {
  const parsed = new Date(value)

  return Number.isNaN(parsed.getTime()) ? null : parsed
}

/** Reloj de 24 h (`h23`, el de todo el producto) o de 12 h (`h12`). */
export type HourCycle = 'h23' | 'h12'

export interface InstantFormatOptions {
  /**
   * Por omision `h23`: el producto escribe las horas como `HH:MM` de 24 h en
   * todas partes (pantalla, CSV y PDF, doc 01), y un historial que dijera
   * `7:14 AM` en ingles junto a un `07:14` no se puede contrastar con una nomina.
   */
  readonly hourCycle?: HourCycle
}

export interface InstantWithSecondsOptions extends InstantFormatOptions {
  /**
   * Con segundos (`14:50:03`), solo donde hacen falta para leer el dato: dos
   * fichajes a 45 s de distancia (RN-16) o la pantalla de diagnostico del
   * quiosco. Por omision no, porque el registro se lee en minutos.
   */
  readonly seconds?: boolean
}

/** La ultima zona con la que se formateo un instante: la del centro, para calentar sin adivinar. */
let lastInstantZone: string | null = null

/**
 * Un instante UTC en la zona del centro: `8 oct 2026, 07:14` (`Oct 8, 2026,
 * 07:14` en ingles), sin segundos ni decimales salvo que se pidan. Nunca
 * redondea nada hacia arriba. Es el unico formateador de instantes con fecha
 * de las SPA, y su forma la decidio el propietario el 24-10-2026:
 *
 *  - la hora siempre `HH:MM` de 24 h, la misma que las marcas de los tramos:
 *    `dateStyle`/`timeStyle` escriben `7:14` en espanol y no se pueden poner
 *    al lado de un `07:14` que se contrasta con una nomina;
 *  - el mes con letras, como `formatCivilDate`: `10/8/26` es el 8 de octubre
 *    en ingles y el 10 de agosto en espanol, y quien lee no sabe cual.
 */
export function formatInstant(
  value: string,
  timeZone: string,
  locale: string,
  options: InstantWithSecondsOptions = {},
): string {
  const instant = parseInstant(value)

  if (instant === null) {
    return ''
  }

  lastInstantZone = timeZone

  return new Intl.DateTimeFormat(locale, {
    timeZone,
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    ...(options.seconds === true ? { second: '2-digit' } : {}),
    hourCycle: options.hourCycle ?? 'h23',
  }).format(instant)
}

/** Solo la hora, `HH:MM`, en la zona del centro. `''` si no es una fecha. */
export function formatTimeOfDay(
  value: string,
  timeZone: string,
  locale: string,
  options: InstantFormatOptions = {},
): string {
  const instant = parseInstant(value)

  if (instant === null) {
    return ''
  }

  return new Intl.DateTimeFormat(locale, {
    timeZone,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: options.hourCycle ?? 'h23',
  }).format(instant)
}

/**
 * Dia, mes y hora, sin año (`15/03, 00:20` en espanol, `03/15, 00:20` en ingles):
 * para una hora que cae en otro dia que la jornada que se esta leyendo.
 */
export function formatDayMonthTime(
  value: string,
  timeZone: string,
  locale: string,
  options: InstantFormatOptions = {},
): string {
  const instant = parseInstant(value)

  if (instant === null) {
    return ''
  }

  return new Intl.DateTimeFormat(locale, {
    timeZone,
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: options.hourCycle ?? 'h23',
  }).format(instant)
}

/**
 * La etiqueta corta de la zona (`CEST`, `WEST`) para acompañar a la hora: la
 * misma zona del centro escribe `CET` en invierno y `CEST` en verano, y una
 * hora sin ella no dice a cual de los dos lados del cambio pertenece.
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
 * Construir un `Intl.DateTimeFormat` cuesta cientos de microsegundos; una tabla de miles de filas
 * que lo hace por celda lo paga en cada una. Se guarda uno por idioma.
 */
const civilDateFormatters = new Map<string, Intl.DateTimeFormat>()

function civilDateFormatter(locale: string): Intl.DateTimeFormat {
  let formatter = civilDateFormatters.get(locale)

  if (formatter === undefined) {
    formatter = new Intl.DateTimeFormat(locale, { timeZone: 'UTC', dateStyle: 'medium' })
    civilDateFormatters.set(locale, formatter)
  }

  return formatter
}

/**
 * Una fecha civil `YYYY-MM-DD`. Se formatea en UTC a proposito: asi el dia que
 * se enseña es exactamente el que trae el dato, sin desplazarlo ninguna zona.
 */
export function formatCivilDate(value: string, locale: string): string {
  if (!CIVIL_DATE_PATTERN.test(value)) {
    return ''
  }

  return civilDateFormatter(locale).format(new Date(`${value}T00:00:00Z`))
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
// por que dar lo mismo. Esta es precisamente la funcion que una copia divergente
// dejo de usar en `frontend-portal` (ADR-036) — por eso vive en un unico sitio.
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

/**
 * Fecha y hora de un `LocalTimestamp` ya resuelto por el servidor (`14 mar 2026,
 * 06:00`): se lee, no se convierte. Si no se puede leer, cae al instante UTC
 * convertido a la zona del centro con `formatInstant`. Es el momento de una
 * correccion en el historial del panel y del portal, que tienen que decir la
 * misma hora la noche que cambia la hora.
 */
export function formatLocalDateTime(
  localValue: string,
  utcValue: string,
  timeZone: string,
  locale: string,
): string {
  const parts = readLocalTimestamp(localValue)

  return parts === null
    ? formatInstant(utcValue, timeZone, locale)
    : `${formatCivilDate(parts.date, locale)}, ${parts.time}`
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
 * Minutos entre dos instantes UTC con la regla de `ElapsedMinutes` del servidor
 * (RN-09): cada marca se trunca a su segundo ANTES de restar y la diferencia se
 * divide entre sesenta truncando. Asi una pausa de 10:00:00.900 a 10:15:00.100
 * son 15 minutos aqui y en el servidor, no 14. Los casos los fija
 * `backend/tests/Fixtures/elapsed-minutes.json`, que leen las dos pruebas.
 *
 * Es una DURACION, no una conversion de zona. Si `to` es anterior, el resultado
 * es negativo (truncado hacia cero, como `intdiv`) y quien llama decide que
 * hacer con el. `null` si falta alguno.
 */
export function minutesBetween(from: string, to: string | null): number | null {
  const start = parseInstant(from)
  const end = to === null ? null : parseInstant(to)

  if (start === null || end === null) {
    return null
  }

  const seconds = Math.floor(end.getTime() / 1000) - Math.floor(start.getTime() / 1000)

  // Resta el resto antes de dividir: trunca hacia cero sin el `-0` de `Math.trunc`.
  return (seconds - (seconds % 60)) / 60
}

/**
 * Calienta el formateo de instantes: la PRIMERA construccion de un `Intl.DateTimeFormat` con zona
 * horaria carga los datos del idioma y de la zona y cuesta decenas de ms en un portatil lento. Se
 * llama mientras se espera una respuesta, para que ese coste no caiga en la tarea que pinta el
 * resultado. No devuelve nada ni se enseña: la hora que se muestra sigue siendo la de la zona del
 * centro, resuelta por `formatInstant`. La zona es la que se
 * pasa o, si no, la ultima con la que se formateo algo: nunca la del navegador ni una inventada;
 * sin ninguna conocida solo se calienta la fecha civil.
 */
export function warmInstantFormatting(locale: string, timeZone?: string): void {
  const zone = timeZone ?? lastInstantZone

  if (zone !== null) {
    formatInstant('2026-01-01T00:00:00Z', zone, locale)
  }

  formatCivilDate('2026-01-01', locale)
}
