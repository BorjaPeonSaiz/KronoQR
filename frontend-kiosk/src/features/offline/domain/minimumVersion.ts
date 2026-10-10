// VERSION MINIMA DE LA PWA Y MODO URGENTE (RF-KI-07, RF-KI-08, regla dura 19).
//
// El latido devuelve `minimum_app_version`: el NUCLEO `X.Y.Z` de la version del
// servidor. Una tablet con una PWA por debajo habla un contrato que el servidor
// ya no entiende del todo (caso real: el fichaje por PIN tras actualizar el
// servidor), y esperar a la ventana nocturna deja ese quiosco cojo durante el
// dia. Este fichero decide, PURO y sin red, si la tablet esta desfasada.
//
// SOLO EL NUCLEO. Igual que el servidor: `2.2.1-dev` contra `2.2.1` NO esta
// desfasada. Un sufijo (`-dev`, `+build`) nunca decide nada.
//
// EL BUCLE QUE SE EVITA. Si el servidor declara una minima que esta PWA no
// puede alcanzar (un `VERSION` mal puesto), la tablet recargaria para siempre.
// Cada recarga urgente y cada comprobacion sin version nueva se anotan
// (`UrgentUpdateAttempts`, persistente: una recarga borra la memoria) y, tras
// `MAX_URGENT_UPDATE_ATTEMPTS` recargas o `MAX_URGENT_CHECKS_WITHOUT_UPDATE`
// comprobaciones sin que la version cambie, el modo urgente se apaga (`gave_up`): vuelve
// la cadencia normal y se reporta `kiosk.update.unreachable`.

/** Recargas urgentes seguidas, sin cambio de version, tras las que se renuncia. */
export const MAX_URGENT_UPDATE_ATTEMPTS = 3

/**
 * Comprobaciones urgentes resueltas, seguidas, sin version nueva, tras las que se
 * renuncia (~30 min a una cada 5). Es el caso real de una minima inalcanzable:
 * el navegador no ve ningun service worker nuevo y nunca hay nada que recargar.
 */
export const MAX_URGENT_CHECKS_WITHOUT_UPDATE = 6

/** Entre dos comprobaciones urgentes de `registration.update()`: 5 min como minimo. */
export const URGENT_CHECK_MIN_INTERVAL_MS = 5 * 60_000

const CORE_PATTERN = /^(\d+)\.(\d+)\.(\d+)(?:[-+].*)?$/

export type VersionCore = readonly [number, number, number]

/** El nucleo `[X, Y, Z]` de una version SemVer, o `null` si no lo es. */
export function versionCore(version: string): VersionCore | null {
  const match = CORE_PATTERN.exec(version.trim())
  if (match === null) return null
  const parts = [Number(match[1]), Number(match[2]), Number(match[3])]
  if (!parts.every((part) => Number.isSafeInteger(part))) return null
  return [parts[0] ?? 0, parts[1] ?? 0, parts[2] ?? 0]
}

/**
 * `true` solo si AMBAS son SemVer y el nucleo de `current` es estrictamente
 * menor que el de `minimum`. Cualquier duda (version que no es SemVer, como
 * `dev`) es «no desfasada»: nunca se recarga por adivinar. Una `0.0.0-dev` SI
 * es SemVer y SI esta por debajo de cualquier minima, igual que en el servidor.
 */
export function isBelowMinimumVersion(current: string, minimum: string): boolean {
  const have = versionCore(current)
  const need = versionCore(minimum)
  if (have === null || need === null) return false
  for (let index = 0; index < 3; index += 1) {
    const a = have[index] ?? 0
    const b = need[index] ?? 0
    if (a !== b) return a < b
  }
  return false
}

export type MinimumVersionReading =
  /** El `200` no trae el campo: el servidor no declara minima (servidor `-dev`/`0.0.0` o anterior). */
  | { readonly status: 'absent' }
  /** Trae algo que no es SemVer: no se toca lo cacheado. */
  | { readonly status: 'invalid' }
  /** `version` es el NUCLEO normalizado `X.Y.Z`. */
  | { readonly status: 'valid'; readonly version: string }

/**
 * Lee `minimum_app_version` de un `200` del latido de forma DEFENSIVA, como
 * `parseUpdateWindowFromHeartbeatData`: `data` se trata como `unknown`.
 */
export function parseMinimumVersionFromHeartbeatData(data: unknown): MinimumVersionReading {
  if (typeof data !== 'object' || data === null) return { status: 'absent' }
  const raw = (data as Record<string, unknown>)['minimum_app_version']
  if (raw === undefined || raw === null) return { status: 'absent' }
  if (typeof raw !== 'string') return { status: 'invalid' }
  const core = versionCore(raw)
  if (core === null) return { status: 'invalid' }
  return { status: 'valid', version: core.join('.') }
}

/**
 * Lo hecho para llegar a la minima DESDE una version de origen (`from`). Va
 * atado SOLO a `from` (no a la minima): si el servidor alterna entre dos
 * minimas, la cuenta no se reinicia y el bucle sigue cortandose.
 */
export interface UrgentUpdateAttempts {
  /** Ultima minima que motivo un intento (informativa; no reinicia la cuenta). */
  readonly minimum: string
  readonly from: string
  /** Recargas urgentes ya hechas desde `from` sin que la version cambiara. */
  readonly count: number
  /** Comprobaciones urgentes resueltas sin que apareciera ninguna version nueva. */
  readonly checks: number
}

export type UrgentUpdateMode = 'none' | 'urgent' | 'gave_up'

/** Por que se rindio: tras recargar sin avanzar, o porque el navegador no ve ninguna version nueva. */
export type UrgentGiveUpMotive = 'reloads' | 'no_update'

export interface UrgentUpdateInput {
  readonly current: string
  readonly minimum: string | null
  readonly attempts: UrgentUpdateAttempts | null
}

function attemptsFrom(
  current: string,
  attempts: UrgentUpdateAttempts | null,
): UrgentUpdateAttempts | null {
  return attempts !== null && attempts.from === current ? attempts : null
}

/**
 * - `none`: sin minima, o la tablet ya la cumple.
 * - `urgent`: por debajo y aun quedan intentos.
 * - `gave_up`: por debajo, pero `MAX_URGENT_UPDATE_ATTEMPTS` recargas, o
 *   `MAX_URGENT_CHECKS_WITHOUT_UPDATE` comprobaciones sin version nueva, desde
 *   ESTA misma version no la han movido. Si la version cambio entre medias
 *   hubo progreso y la cuenta empieza de cero.
 */
export function urgentUpdateState(input: UrgentUpdateInput): UrgentUpdateMode {
  if (input.minimum === null || !isBelowMinimumVersion(input.current, input.minimum)) return 'none'
  return urgentGiveUpReason(input.current, input.attempts) === null ? 'urgent' : 'gave_up'
}

/** `null` si aun no se ha rendido. */
export function urgentGiveUpReason(
  current: string,
  attempts: UrgentUpdateAttempts | null,
): UrgentGiveUpMotive | null {
  const record = attemptsFrom(current, attempts)
  if (record === null) return null
  if (record.count >= MAX_URGENT_UPDATE_ATTEMPTS) return 'reloads'
  if (record.checks >= MAX_URGENT_CHECKS_WITHOUT_UPDATE) return 'no_update'
  return null
}

/** El registro tras una recarga urgente mas desde `current` hacia `minimum`. */
export function nextUrgentAttempts(
  current: string,
  minimum: string,
  previous: UrgentUpdateAttempts | null,
): UrgentUpdateAttempts {
  const record = attemptsFrom(current, previous)
  return { minimum, from: current, count: (record?.count ?? 0) + 1, checks: record?.checks ?? 0 }
}

/** El registro tras una comprobacion urgente resuelta sin version nueva. */
export function nextUrgentChecks(
  current: string,
  minimum: string,
  previous: UrgentUpdateAttempts | null,
): UrgentUpdateAttempts {
  const record = attemptsFrom(current, previous)
  return { minimum, from: current, count: record?.count ?? 0, checks: (record?.checks ?? 0) + 1 }
}

/** Ha aparecido una version nueva: las comprobaciones en vacio dejan de contar. */
export function withoutUrgentChecks(
  current: string,
  previous: UrgentUpdateAttempts | null,
): UrgentUpdateAttempts | null {
  const record = attemptsFrom(current, previous)
  return record === null ? previous : { ...record, checks: 0 }
}

/** Valida lo leido del disco: cualquier otra forma es «sin registro». */
export function isUrgentUpdateAttempts(value: unknown): value is UrgentUpdateAttempts {
  if (typeof value !== 'object' || value === null) return false
  const { minimum, from, count, checks } = value as Record<string, unknown>
  return (
    typeof minimum === 'string' &&
    typeof from === 'string' &&
    typeof count === 'number' &&
    Number.isInteger(count) &&
    count >= 0 &&
    typeof checks === 'number' &&
    Number.isInteger(checks) &&
    checks >= 0
  )
}
