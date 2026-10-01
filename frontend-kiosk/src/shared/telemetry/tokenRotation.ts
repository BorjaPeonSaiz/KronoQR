// Adopcion del relevo del token del quiosco (RF-ID-04, ADR-044, doc 02 §7.3).
//
// EL SERVIDOR ENTREGA EL RELEVO EN EL LATIDO (`KioskHeartbeat.rotated_token`).
// Esta tablet tiene que guardarlo SIN dejar de poder fichar en ningun instante:
//
//   1. El padron cacheado deriva su clave del token (`rosterCipher.ts`). Se
//      vuelve a cifrar con el token nuevo ANTES de cambiar el token, y dentro de
//      la misma exclusion mutua que protege el padron (`CachedRoster.rotateKey`),
//      para que ningun `refresh()` escriba una copia con la clave vieja entre
//      medias.
//   2. El token y su caducidad se escriben como una unidad
//      (`storeRotatedDeviceToken`).
//   3. Si algo falla, se CONSERVA el token viejo (sigue valido durante el
//      solape, ADR-044) y el relevo se descarta con un diagnostico sin datos
//      personales: el siguiente latido, firmado con el viejo, recibira otro
//      relevo (REENTREGA). El valor nuevo nunca se usa si no se ha podido
//      guardar bien, y por eso el servidor lo da por «no usado».
//
// SIEMPRE EL MAS RECIENTE, NO EL ULTIMO EN LLEGAR. Las adopciones se encadenan
// en serie (una promesa cola) y cada una compara contra el token que HAY en ese
// momento: el mismo valor solo refresca la caducidad, y un relevo se adopta solo
// si su id de Sanctum (el prefijo `<id>|` del valor, autoincremental) es MAYOR
// que el del vigente. Dos latidos simultaneos con el mismo token por rotar dan
// DOS relevos (el segundo retira al primero sin usar); si sus respuestas llegan
// cruzadas, el primero llega muerto y adoptarlo dejaria a la tablet con un
// token invalido. El id ordena sin ambiguedad.
//
// RESPALDO. Al adoptar, el token que se sustituye se conserva (`previous`)
// hasta que el nuevo consiga su primer uso autenticado -justo cuando el
// servidor retira el anterior-. Si el nuevo resulta muerto, el cliente HTTP
// reintenta UNA vez con el respaldo y, si el servidor lo acepta, la tablet
// vuelve a el (`revertToPreviousToken`) y adopta el relevo vigente que le llegue
// en el siguiente latido. Nunca se llega a una falsa revocacion mientras exista
// un token vivo.
//
// REGLA DURA 19. Nada de esto lanza ni bloquea: `adoptRotatedToken` siempre
// resuelve con un desenlace, y el escaneo no la espera nunca.

import {
  clearPreviousDeviceToken,
  readDeviceToken,
  readDeviceTokenExpiresAt,
  readPreviousDeviceToken,
  refreshDeviceTokenExpiresAt,
  storeRotatedDeviceToken,
} from './deviceIdentity'

/** `KioskHeartbeat.rotated_token`, ya validado. */
export interface RotatedToken {
  readonly value: string
  readonly expires_at: string
}

/** Lo que `CachedRoster.rotateKey` sabe decir: el cambio de token ocurre dentro de el. */
export type RosterRotationResult = 'committed' | 'commit_failed' | 'reseal_failed'

export interface RosterKeyRotator {
  /**
   * Re-cifra el padron con `newToken` y, solo si ha ido bien, llama a `commit`
   * (que cambia el token) dentro de la misma exclusion mutua.
   */
  rotateKey(newToken: string, commit: () => boolean): Promise<RosterRotationResult>
}

export type TokenAdoptionOutcome =
  | 'adopted'
  | 'already_current'
  | 'not_paired'
  | 'unavailable'
  | 'superseded'
  /** Su id no supera al del token vigente: llego tarde y cruzado, ya esta superado. */
  | 'stale'
  | 'commit_failed'
  | 'reseal_failed'
  | 'error'

/** Desenlaces tras los que el relevo se DESCARTA y hay que dejar diagnostico. */
export function isTokenAdoptionFailure(outcome: TokenAdoptionOutcome): boolean {
  return (
    outcome === 'commit_failed' ||
    outcome === 'reseal_failed' ||
    outcome === 'unavailable' ||
    outcome === 'error'
  )
}

/**
 * Id autoincremental de Sanctum (`<id>|<secreto>`). `null` si el valor no
 * tiene esa forma: sin id no se puede ordenar y el relevo se adopta (el id es
 * una proteccion contra el desorden, no una condicion para rotar).
 */
export function sanctumTokenId(value: string): number | null {
  const match = /^(\d{1,15})\|/.exec(value)
  return match === null ? null : Number(match[1])
}

let rotator: RosterKeyRotator | null = null

/** Lo registra el controlador de la cola (que es quien tiene el padron); devuelve como retirarlo al cerrarse. */
export function registerRosterKeyRotator(next: RosterKeyRotator): () => void {
  rotator = next
  return () => {
    if (rotator === next) rotator = null
  }
}

/**
 * Lee `rotated_token` de un `200` de `POST /kiosk/heartbeat` de forma
 * DEFENSIVA: `data` se trata como `unknown` porque un servidor anterior no lo
 * trae, y uno defectuoso podria traerlo mal (la clave ausente significa «no
 * toca», nunca `null`). Cualquier forma que no encaje devuelve `null` y el
 * quiosco sigue con su token.
 */
export function parseRotatedToken(data: unknown): RotatedToken | null {
  if (typeof data !== 'object' || data === null) return null
  const raw = (data as Record<string, unknown>)['rotated_token']
  if (typeof raw !== 'object' || raw === null) return null
  const { value, expires_at: expiresAt } = raw as Record<string, unknown>
  if (typeof value !== 'string' || value === '') return null
  if (typeof expiresAt !== 'string' || Number.isNaN(Date.parse(expiresAt))) return null
  return { value, expires_at: expiresAt }
}

let tail: Promise<unknown> = Promise.resolve()

async function adoptNow(rotated: RotatedToken): Promise<TokenAdoptionOutcome> {
  const current = readDeviceToken()
  // Sin token no hay emparejamiento: aceptar un relevo aqui reviviria un
  // dispositivo desvinculado entre que se envio el latido y llego la respuesta.
  if (current === null) return 'not_paired'

  if (rotated.value === current) {
    refreshDeviceTokenExpiresAt(rotated.expires_at)
    return 'already_current'
  }

  const currentId = sanctumTokenId(current)
  const rotatedId = sanctumTokenId(rotated.value)
  if (currentId !== null && rotatedId !== null && rotatedId <= currentId) return 'stale'

  if (rotator === null) return 'unavailable'

  const result = await rotator.rotateKey(rotated.value, () => {
    // Dentro de la exclusion del padron: si entre tanto el dispositivo se ha
    // desvinculado o reemparejado, este relevo ya no es suyo.
    if (readDeviceToken() !== current) return false
    // El respaldo es el que YA hubiera (el vigente aun no se ha usado con
    // exito, asi que el que vale es el anterior) o, si no hay, el vigente.
    const backup = readPreviousDeviceToken() ?? {
      value: current,
      expiresAt: readDeviceTokenExpiresAt(),
    }
    return storeRotatedDeviceToken(rotated.value, rotated.expires_at, backup)
  })

  if (result === 'committed') return 'adopted'
  if (result === 'commit_failed' && readDeviceToken() !== current) return 'superseded'
  return result
}

async function revertNow(): Promise<TokenAdoptionOutcome> {
  const current = readDeviceToken()
  const previous = readPreviousDeviceToken()
  if (current === null) return 'not_paired'
  if (previous === null || previous.value === current) return 'already_current'
  if (rotator === null) return 'unavailable'

  const result = await rotator.rotateKey(previous.value, () => {
    if (readDeviceToken() !== current) return false
    return storeRotatedDeviceToken(previous.value, previous.expiresAt, null)
  })
  if (result === 'committed') return 'adopted'
  return result
}

/**
 * Guarda el relevo. Nunca lanza. Las llamadas se ejecutan en serie y en orden
 * de llegada, y solo gana el de id mayor (ver la cabecera).
 */
export function adoptRotatedToken(rotated: RotatedToken): Promise<TokenAdoptionOutcome> {
  return enqueue(() => adoptNow(rotated))
}

function enqueue(task: () => Promise<TokenAdoptionOutcome>): Promise<TokenAdoptionOutcome> {
  const run = tail.then(task, task)
  const safe = run.catch((): TokenAdoptionOutcome => 'error')
  tail = safe
  return safe
}

/**
 * El respaldo ha sido ACEPTADO por el servidor cuando el vigente daba `401`: el
 * vigente es un token muerto. La tablet vuelve al respaldo (re-cifrando el
 * padron con su clave) y el siguiente latido, firmado con el, trae el relevo
 * vigente. Nunca lanza.
 */
export function revertToPreviousToken(): Promise<TokenAdoptionOutcome> {
  return enqueue(revertNow)
}

/**
 * El token vigente `token` ha conseguido un uso autenticado: el servidor ya
 * retiro a los anteriores, el respaldo ya no sirve y se borra.
 */
export function confirmDeviceToken(token: string): void {
  if (readDeviceToken() !== token) return
  if (readPreviousDeviceToken() === null) return
  clearPreviousDeviceToken()
}

/**
 * Lo que el cliente HTTP necesita para convivir con el relevo: el token vigente
 * (que rota, doc 02 §7.3), el respaldo y los dos avisos. Se reparte con
 * `createApiClient({ ...deviceTokenApiOptions })` en cada pantalla.
 */
export const deviceTokenApiOptions = {
  deviceToken: readDeviceToken,
  fallbackDeviceToken: (): string | null => readPreviousDeviceToken()?.value ?? null,
  onDeviceTokenConfirmed: confirmDeviceToken,
  onFallbackTokenConfirmed: (): void => {
    void revertToPreviousToken()
  },
} as const
