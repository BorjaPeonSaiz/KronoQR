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

/**
 * Resultado de `PairingCompleted` (RF-PD-06, tarea 5.6): el token con el que
 * la tablet firma `/scan`, `/kiosk/roster` y `/kiosk/heartbeat`, y el
 * `device.uuid` que la identifica de aqui en adelante. Se escribe el TOKEN
 * primero: si el `device_id` fallara al guardarse justo despues (almacenamiento
 * lleno a mitad de escritura, caso extremo), es preferible quedarse con un
 * token sin id de servidor —el quiosco sigue pudiendo fichar con el— que con
 * un id nuevo y sin token, que el guard del router manda directo a `/pair`.
 */
export function persistPairedDevice(token: string, deviceId: string): void {
  storeDeviceToken(token)
  storeDeviceId(deviceId)
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
