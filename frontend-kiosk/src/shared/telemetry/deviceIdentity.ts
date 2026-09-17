// Identidad tecnica del dispositivo para telemetria.
//
// EL `device_id` DEFINITIVO lo emite el servidor al confirmar el
// emparejamiento (`POST /api/v1/kiosk/pair/confirm`, recogido por la tablet en
// `POST /api/v1/kiosk/pair/claim`, tarea 5.6, `features/pairing/`).
// `persistPairedDevice()` (aqui abajo) escribe ese `device.uuid` en la MISMA
// clave que este fichero ya sabia leer; `resolveDeviceId()` no necesita
// distinguir entre los dos origenes, porque devuelve lo que haya en la clave,
// sea el identificador local generado aqui o el del servidor. Antes de
// emparejar, o tras una desvinculacion (token revocado, ver
// `features/pairing/application/deviceRevocation.ts`), lo que queda es un
// identificador local estable entre recargas: sirve para que un error
// reportado en ese hueco siga siendo atribuible a un aparato concreto.
//
// TODO LO DE EMPAREJAMIENTO SE ESCRIBE Y SE LEE DESDE AQUI, y a proposito: las
// claves de `localStorage` viven en un solo sitio para que no exista una
// segunda copia del nombre por la que un lector y un escritor puedan
// desincronizarse. `ScanView.vue`, `PinView.vue` y `PairingView.vue` importan
// estas funciones directamente; no hay ningun modulo intermedio en
// `features/pairing/` que las envuelva.
//
// `localStorage` aqui SI es adecuado, y no contradice la prohibicion de usarlo
// para la cola: esto es una preferencia tecnica de 36 bytes que se puede perder
// sin consecuencias. La cola es registro legal sin escribir y va a IndexedDB.

import { uuidV7 } from '@/shared/ids/uuidV7'

const DEVICE_ID_KEY = 'kronoqr.kiosk.device_id'

/**
 * Token de dispositivo emitido al emparejar. Lo escribe `persistPairedDevice`
 * y lo borra `clearDeviceToken` cuando el servidor revoca el dispositivo
 * (`deviceRevocation.ts`).
 *
 * Se lee y no se inventa: de este token se DERIVA la clave del padron cacheado
 * (RL-12, doc 02 §7.1). Sin token no hay clave, y sin clave no se cachea nada
 * — que es la respuesta correcta, no un problema. Ver `cachedRoster.ts`.
 */
const DEVICE_TOKEN_KEY = 'kronoqr.kiosk.device_token'

/**
 * Caducidad del token y nombre del quiosco (`PairingCompleted.token.expires_at`
 * y `PairingCompleted.device.name`, tarea 3.3). Antes de esta tarea
 * `persistPairedDevice` los descartaba: la pantalla de diagnostico (RF-KI-08)
 * es la primera en necesitarlos, y solo para MOSTRARLOS — ninguna decision de
 * negocio depende de ellos, por eso viven en `localStorage` con el mismo
 * criterio que el resto de este fichero.
 */
const DEVICE_TOKEN_EXPIRES_AT_KEY = 'kronoqr.kiosk.device_token_expires_at'
const DEVICE_NAME_KEY = 'kronoqr.kiosk.device_name'

/**
 * Huella del codigo de servicio (`KioskHeartbeat.service_code_hash`, RF-KI-08,
 * tarea 3.3). La escribe el planificador del latido (`heartbeat.ts`) tras cada
 * `200`, nunca el codigo en claro. `null` = la instalacion no tiene codigo
 * configurado; en ese caso la pantalla de diagnostico se abre sin pedirlo.
 */
const SERVICE_CODE_HASH_KEY = 'kronoqr.kiosk.service_code_hash'

/**
 * Los dos ajustes de la tarea 3.5 (RF-AT-12, RF-AT-10), MISMO PATRON que
 * `service_code_hash`: los escribe el planificador del latido tras cada
 * `200` y se leen en local para que el boton «Pausa» y el aviso de desfase
 * funcionen sin red. `KioskHeartbeat.break_clocking_enabled` y
 * `.clock_skew_tolerance_seconds` son obligatorios en el contrato, asi que
 * -a diferencia de `service_code_hash`- no hay un `null` de «el servidor no
 * dijo nada»: solo «esta tablet no ha latido nunca en esta sesion», que es
 * el estado que los valores por defecto de abajo representan.
 */
const BREAK_CLOCKING_ENABLED_KEY = 'kronoqr.kiosk.break_clocking_enabled'
const CLOCK_SKEW_TOLERANCE_SECONDS_KEY = 'kronoqr.kiosk.clock_skew_tolerance_seconds'

/** Version de la PWA. La inyecta Vite desde `package.json` (ver `vite.config.ts`). */
export const APP_VERSION: string = __APP_VERSION__

function safeStorage(): Storage | null {
  try {
    return globalThis.localStorage
  } catch {
    // Modo privado, almacenamiento deshabilitado por politica del dispositivo…
    // Nada de esto puede tumbar el quiosco.
    return null
  }
}

/** `null` mientras la tablet no este emparejada. Nunca viaja en telemetria. */
export function readDeviceToken(): string | null {
  const storage = safeStorage()
  if (storage === null) return null

  try {
    const stored = storage.getItem(DEVICE_TOKEN_KEY)
    return stored === null || stored === '' ? null : stored
  } catch {
    return null
  }
}

export function resolveDeviceId(): string {
  const storage = safeStorage()
  if (storage === null) return 'unpaired'

  try {
    const stored = storage.getItem(DEVICE_ID_KEY)
    if (stored !== null && stored !== '') return stored

    const fresh = uuidV7()
    storage.setItem(DEVICE_ID_KEY, fresh)
    return fresh
  } catch {
    return 'unpaired'
  }
}

/**
 * Caducidad del token guardada en el emparejamiento (tarea 3.3, solo lectura
 * para la pantalla de diagnostico). `null` si no hay token o si no se guardo
 * caducidad (tablets emparejadas antes de esta tarea).
 */
export function readDeviceTokenExpiresAt(): string | null {
  const storage = safeStorage()
  if (storage === null) return null
  try {
    const stored = storage.getItem(DEVICE_TOKEN_EXPIRES_AT_KEY)
    return stored === null || stored === '' ? null : stored
  } catch {
    return null
  }
}

/** Nombre del quiosco tal como lo puso quien administra el panel (tarea 3.3). */
export function readDeviceName(): string | null {
  const storage = safeStorage()
  if (storage === null) return null
  try {
    const stored = storage.getItem(DEVICE_NAME_KEY)
    return stored === null || stored === '' ? null : stored
  } catch {
    return null
  }
}

/**
 * Huella del codigo de servicio cacheada por el ultimo latido con `200`
 * (RF-KI-08, tarea 3.3). `null` = sin codigo configurado en esta instalacion,
 * O tablet que aun no ha latido nunca: los dos casos abren la pantalla de
 * diagnostico sin pedir codigo (decision 7 de la tarea 3.3), que es la
 * respuesta correcta para los dos.
 */
export function readServiceCodeHash(): string | null {
  const storage = safeStorage()
  if (storage === null) return null
  try {
    const stored = storage.getItem(SERVICE_CODE_HASH_KEY)
    return stored === null || stored === '' ? null : stored
  } catch {
    return null
  }
}

/**
 * Lo llama SOLO el planificador del latido (`heartbeat.ts`), tras cada `200`
 * de `POST /kiosk/heartbeat`. `null` BORRA la huella cacheada: si el panel
 * quita el codigo de servicio de la instalacion, el siguiente latido lo dice y
 * la pantalla de diagnostico deja de pedirlo, sin que nadie tenga que
 * desvincular ni reiniciar nada.
 */
export function storeServiceCodeHash(hash: string | null): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    if (hash === null) storage.removeItem(SERVICE_CODE_HASH_KEY)
    else storage.setItem(SERVICE_CODE_HASH_KEY, hash)
  } catch {
    // Sin almacenamiento no hay huella que guardar ni que borrar: la pantalla
    // de diagnostico seguira preguntando al `localStorage` (vacio) y se abrira
    // sin codigo, que es la degradacion honesta (regla dura 19 al reves: esto
    // nunca puede impedir fichar, y tampoco impide diagnosticar).
  }
}

/**
 * `false` mientras esta tablet no haya completado ningun latido en NINGUNA
 * sesion (nunca escrito en disco): «sin latido previo, `break_clocking_enabled`
 * es `false`» (decision 1 de la tarea 3.5) — el boton «Pausa» se queda oculto
 * hasta que el primer latido confirme que la instalacion lo tiene activado, en
 * vez de mostrarlo con un valor inventado.
 */
export function readBreakClockingEnabled(): boolean {
  const storage = safeStorage()
  if (storage === null) return false
  try {
    return storage.getItem(BREAK_CLOCKING_ENABLED_KEY) === '1'
  } catch {
    return false
  }
}

/** Lo llama SOLO el planificador del latido, tras cada `200`. */
export function storeBreakClockingEnabled(enabled: boolean): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    storage.setItem(BREAK_CLOCKING_ENABLED_KEY, enabled ? '1' : '0')
  } catch {
    // El boton se queda en el estado que ya tenia pintado (degradacion honesta).
  }
}

/**
 * `null` = esta tablet no ha completado ningun latido todavia (en esta sesion
 * ni en ninguna anterior): «mientras no haya latido: sin banda, no un valor
 * inventado» (decision 6 de la tarea 3.5). El aviso de desfase se queda
 * apagado hasta que exista un umbral de verdad, en vez de compararse contra
 * una constante que la instalacion podria haber cambiado.
 */
export function readClockSkewToleranceSeconds(): number | null {
  const storage = safeStorage()
  if (storage === null) return null
  try {
    const stored = storage.getItem(CLOCK_SKEW_TOLERANCE_SECONDS_KEY)
    if (stored === null || stored === '') return null
    const parsed = Number(stored)
    return Number.isFinite(parsed) ? parsed : null
  } catch {
    return null
  }
}

/** Lo llama SOLO el planificador del latido, tras cada `200`. */
export function storeClockSkewToleranceSeconds(seconds: number): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    storage.setItem(CLOCK_SKEW_TOLERANCE_SECONDS_KEY, String(seconds))
  } catch {
    // El aviso se queda con el umbral que ya tenia cacheado (degradacion honesta).
  }
}

/**
 * Guarda el token emitido en `PairingCompleted` (tarea 5.6). Interno: quien
 * empareja llama a `persistPairedDevice`, no a esto directamente.
 */
function storeDeviceToken(token: string): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    storage.setItem(DEVICE_TOKEN_KEY, token)
  } catch {
    // NO es un «sigue funcionando en memoria»: sin almacenamiento persistente
    // este `catch` deja `readDeviceToken()` devolviendo `null` para siempre, y
    // el guard del router (`router/index.ts`) manda cualquier navegacion de
    // vuelta a `/pair` en cuanto se recargue la pagina — la tablet NO puede
    // fichar hasta que el almacenamiento funcione. Es exactamente el motivo
    // por el que el runbook `alta-nuevo-quiosco.md` exige comprobar que el
    // modo quiosco elegido por el IT del cliente conserva `localStorage` entre
    // reinicios, antes de dar la instalacion por terminada.
  }
}

/**
 * Sobrescribe el identificador local con el `device.uuid` que confirma el
 * servidor (tarea 5.6). Es la misma clave que `resolveDeviceId` ya sabia leer:
 * no hay una segunda clave para «el id de verdad» frente a «el id local».
 * Interno: quien empareja llama a `persistPairedDevice`, no a esto directamente.
 */
function storeDeviceId(deviceId: string): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    storage.setItem(DEVICE_ID_KEY, deviceId)
  } catch {
    // Igual que en `storeDeviceToken`: sin almacenamiento persistente el
    // identificador de servidor no sobrevive a una recarga, y la telemetria
    // de esta sesion vuelve a usar el local generado por `resolveDeviceId`.
  }
}

function storeDeviceTokenExpiresAt(expiresAt: string): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    storage.setItem(DEVICE_TOKEN_EXPIRES_AT_KEY, expiresAt)
  } catch {
    // Solo se pierde una fila informativa de la pantalla de diagnostico, no el
    // fichaje: `expires_at` no gobierna nada (la rotacion automatica del token
    // la hace el servidor, ver el comentario del campo en el contrato).
  }
}

function storeDeviceName(name: string): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    storage.setItem(DEVICE_NAME_KEY, name)
  } catch {
    // Mismo criterio: solo afecta a una fila de diagnostico.
  }
}

/**
 * Resultado de `PairingCompleted` (RF-PD-06, tarea 5.6): el token con el que
 * la tablet firma `/scan`, `/kiosk/roster` y `/kiosk/heartbeat`, y el
 * `device.uuid` que la identifica de aqui en adelante. Se escribe el TOKEN
 * primero: si el `device_id` fallara al guardarse justo despues (almacenamiento
 * lleno a mitad de escritura, caso extremo), es preferible quedarse con un
 * token sin id de servidor —el quiosco sigue pudiendo fichar con el— que con
 * un id nuevo y sin token, que el guard del router manda directo a `/pair`.
 *
 * `expiresAt` y `deviceName` (tarea 3.3, RF-KI-08) van DESPUES de las dos
 * escrituras que si importan para fichar: son solo para la pantalla de
 * diagnostico, y un fallo de almacenamiento a mitad de estas dos ultimas no
 * puede degradar nada de lo anterior.
 */
export function persistPairedDevice(
  token: string,
  deviceId: string,
  expiresAt: string,
  deviceName: string,
): void {
  storeDeviceToken(token)
  storeDeviceId(deviceId)
  storeDeviceTokenExpiresAt(expiresAt)
  storeDeviceName(deviceName)
}

/**
 * Token revocado (RL-12, doc 01 §8.1: «purga al desvincular el dispositivo»).
 * Solo la tablet vuelve a la pantalla de emparejamiento; la cola offline NO se
 * toca aqui — eso lo decide `deviceRevocation.ts`, nunca esta funcion.
 */
export function clearDeviceToken(): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    storage.removeItem(DEVICE_TOKEN_KEY)
  } catch {
    // Nada que hacer: sin almacenamiento no habia token que borrar de verdad.
  }
}
