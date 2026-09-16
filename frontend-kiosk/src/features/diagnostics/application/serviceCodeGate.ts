// Puerta del codigo de servicio (RF-KI-08, tarea 3.3, decision 6).
//
// SE COMPRUEBA EN LOCAL, SIN RED. El servidor nunca ve el codigo que se teclea
// aqui: la tablet lo compara contra la huella que ya trae cacheada del ultimo
// latido (`deviceIdentity.ts` -> `readServiceCodeHash`). `sha256Hex` es la
// misma primitiva sincrona que usa el padron (`shared/crypto/sha256.ts`), por
// el mismo motivo -esto va en una pantalla que tiene que abrirse SIN red-.
//
// CINCO INTENTOS, SESENTA SEGUNDOS, Y PERSISTENTE (revision de la 3.3,
// segunda vuelta). No es una defensa fuerte -es una huella SHA-256 de un
// codigo de 8 a 12 cifras, forzable con acceso fisico al almacenamiento
// (riesgo aceptado, doc 07 §6)-, pero impide un bucle de prueba y error a
// mano delante de la tablet. `failures`/`lockedUntil` viven en
// `localStorage` (`kronoqr.kiosk.service_code_lockout`): antes de esta
// revision vivian en memoria, dentro de la instancia del gate que crea
// `DiagnosticsView.vue` en cada montaje, asi que pulsar «Volver a fichar» y
// volver a entrar -o recargar la pagina- reiniciaba el contador a cero y el
// bloqueo dejaba de bloquear nada. Se limpian al acertar. El reloj es
// inyectable: sin eso la prueba del bloqueo tendria que esperar 60 s de
// verdad.

import { sha256Hex } from '@/shared/crypto/sha256'

const DEFAULT_MAX_ATTEMPTS = 5
const DEFAULT_LOCKOUT_MS = 60_000
const LOCKOUT_STORAGE_KEY = 'kronoqr.kiosk.service_code_lockout'

export interface ServiceCodeGateOptions {
  readonly deviceId: string
  /** `KioskHeartbeat.service_code_hash` cacheada. Nunca `null` aqui: sin huella no hay puerta que montar. */
  readonly expectedHash: string
  readonly maxAttempts?: number
  readonly lockoutMs?: number
  /** Epoch ms. Inyectable para que el bloqueo se pueda probar sin esperar de verdad. */
  readonly now?: () => number
}

interface PersistedLockoutState {
  readonly failures: number
  readonly lockedUntil: number | null
}

const EMPTY_LOCKOUT_STATE: PersistedLockoutState = { failures: 0, lockedUntil: null }

function safeStorage(): Storage | null {
  try {
    return globalThis.localStorage
  } catch {
    // Modo privado, almacenamiento deshabilitado por politica del dispositivo:
    // el bloqueo vive solo en memoria para esta instancia, que es peor -no
    // sobrevive a un «Volver a fichar»- pero nunca impide diagnosticar.
    return null
  }
}

function isPersistedLockoutState(value: unknown): value is PersistedLockoutState {
  if (typeof value !== 'object' || value === null) return false
  const { failures, lockedUntil } = value as Record<string, unknown>
  return typeof failures === 'number' && (typeof lockedUntil === 'number' || lockedUntil === null)
}

function readPersistedLockoutState(): PersistedLockoutState {
  const storage = safeStorage()
  if (storage === null) return EMPTY_LOCKOUT_STATE
  try {
    const raw = storage.getItem(LOCKOUT_STORAGE_KEY)
    if (raw === null) return EMPTY_LOCKOUT_STATE
    const parsed: unknown = JSON.parse(raw)
    return isPersistedLockoutState(parsed) ? parsed : EMPTY_LOCKOUT_STATE
  } catch {
    return EMPTY_LOCKOUT_STATE
  }
}

function writePersistedLockoutState(state: PersistedLockoutState): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    if (state.failures === 0 && state.lockedUntil === null) {
      storage.removeItem(LOCKOUT_STORAGE_KEY)
    } else {
      storage.setItem(LOCKOUT_STORAGE_KEY, JSON.stringify(state))
    }
  } catch {
    // Sin almacenamiento persistente que escribir: el bloqueo de esta
    // instancia sigue funcionando en memoria hasta que se destruya.
  }
}

export type ServiceCodeAttemptResult =
  | { readonly outcome: 'accepted' }
  | { readonly outcome: 'rejected'; readonly attemptsLeft: number }
  | { readonly outcome: 'locked'; readonly retryAfterMs: number }

export interface ServiceCodeGate {
  attempt(code: string): ServiceCodeAttemptResult
  /** `true` mientras dure el bloqueo de los cinco fallos. */
  isLocked(): boolean
  /** Milisegundos que quedan de bloqueo, `0` si no esta bloqueada. */
  remainingLockoutMs(): number
}

export function createServiceCodeGate(options: ServiceCodeGateOptions): ServiceCodeGate {
  const maxAttempts = options.maxAttempts ?? DEFAULT_MAX_ATTEMPTS
  const lockoutMs = options.lockoutMs ?? DEFAULT_LOCKOUT_MS
  const now = options.now ?? (() => Date.now())

  const persisted = readPersistedLockoutState()
  let failures = persisted.failures
  let lockedUntil = persisted.lockedUntil

  function persist(): void {
    writePersistedLockoutState({ failures, lockedUntil })
  }

  function clearExpiredLock(): void {
    if (lockedUntil !== null && now() >= lockedUntil) {
      lockedUntil = null
      failures = 0
      persist()
    }
  }

  function isLocked(): boolean {
    clearExpiredLock()
    return lockedUntil !== null
  }

  function remainingLockoutMs(): number {
    clearExpiredLock()
    return lockedUntil === null ? 0 : Math.max(0, lockedUntil - now())
  }

  return {
    isLocked,
    remainingLockoutMs,

    attempt(code) {
      if (isLocked()) {
        return { outcome: 'locked', retryAfterMs: remainingLockoutMs() }
      }

      // Rechazo generico, sobre huellas de longitud fija, sin consultar al
      // servidor (regla dura 17, RS-03). No es una comparacion de TIEMPO
      // constante -un `===` de cadenas puede terminar antes por un prefijo
      // distinto-, y no hace falta que lo sea: la huella nunca viaja a
      // ningun sitio donde alguien pueda medir cuanto tarda esta llamada, asi
      // que no hay canal de temporizacion que explotar.
      const matches = sha256Hex(`${options.deviceId}:${code}`) === options.expectedHash
      if (matches) {
        failures = 0
        lockedUntil = null
        persist()
        return { outcome: 'accepted' }
      }

      failures += 1
      if (failures >= maxAttempts) {
        lockedUntil = now() + lockoutMs
        persist()
        return { outcome: 'locked', retryAfterMs: lockoutMs }
      }
      persist()
      return { outcome: 'rejected', attemptsLeft: maxAttempts - failures }
    },
  }
}
