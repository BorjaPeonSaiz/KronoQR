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
// Cada recarga urgente se anota (`UrgentUpdateAttempts`, persistente: una
// recarga borra la memoria) y, tras `MAX_URGENT_UPDATE_ATTEMPTS` sin que la
// version cambie, el modo urgente se apaga para esa minima (`gave_up`): vuelve
// la cadencia normal y se reporta `kiosk.update.unreachable`.

/** Recargas urgentes seguidas, sin cambio de version, tras las que se renuncia. */
export const MAX_URGENT_UPDATE_ATTEMPTS = 3

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
 * menor que el de `minimum`. Cualquier duda (version ilegible, `0.0.0`
 * de desarrollo) es «no desfasada»: nunca se recarga por adivinar.
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

/** Intentos de recarga urgente ya hechos, anclados a la minima y a la version desde la que se hicieron. */
export interface UrgentUpdateAttempts {
  readonly minimum: string
  readonly from: string
  readonly count: number
}

export type UrgentUpdateMode = 'none' | 'urgent' | 'gave_up'

export interface UrgentUpdateInput {
  readonly current: string
  readonly minimum: string | null
  readonly attempts: UrgentUpdateAttempts | null
}

/**
 * - `none`: sin minima, o la tablet ya la cumple.
 * - `urgent`: por debajo y aun quedan intentos.
 * - `gave_up`: por debajo, pero `MAX_URGENT_UPDATE_ATTEMPTS` recargas desde
 *   ESTA misma version no la han movido. Si la version cambio entre medias hubo
 *   progreso y la cuenta empieza de cero.
 */
export function urgentUpdateState(input: UrgentUpdateInput): UrgentUpdateMode {
  if (input.minimum === null || !isBelowMinimumVersion(input.current, input.minimum)) return 'none'
  const attempts = input.attempts
  const count =
    attempts !== null && attempts.minimum === input.minimum && attempts.from === input.current
      ? attempts.count
      : 0
  return count >= MAX_URGENT_UPDATE_ATTEMPTS ? 'gave_up' : 'urgent'
}

/** El registro tras un intento mas desde `current` hacia `minimum`. */
export function nextUrgentAttempts(
  current: string,
  minimum: string,
  previous: UrgentUpdateAttempts | null,
): UrgentUpdateAttempts {
  const sameRun = previous !== null && previous.minimum === minimum && previous.from === current
  return { minimum, from: current, count: sameRun ? previous.count + 1 : 1 }
}

/** Valida lo leido del disco: cualquier otra forma es «sin registro». */
export function isUrgentUpdateAttempts(value: unknown): value is UrgentUpdateAttempts {
  if (typeof value !== 'object' || value === null) return false
  const { minimum, from, count } = value as Record<string, unknown>
  return (
    typeof minimum === 'string' &&
    typeof from === 'string' &&
    typeof count === 'number' &&
    Number.isInteger(count) &&
    count >= 0
  )
}
