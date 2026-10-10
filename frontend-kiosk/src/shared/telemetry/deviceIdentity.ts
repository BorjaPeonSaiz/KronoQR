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
import type { UpdateWindow } from '@/features/offline/domain/updateWindow'
import {
  DEFAULT_UPDATE_QUIET_MINUTES,
  DEFAULT_UPDATE_WINDOW,
  isValidUpdateWindow,
} from '@/features/offline/domain/updateWindow'
import type { UrgentUpdateAttempts } from '@/features/offline/domain/minimumVersion'
import { isUrgentUpdateAttempts } from '@/features/offline/domain/minimumVersion'

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
 * Token ANTERIOR, conservado como respaldo tras un relevo (RF-ID-04, ADR-044).
 * El servidor lo mantiene vivo durante el solape; si el relevo resulta ser un
 * token muerto (dos respuestas cruzadas por una reconexion: el servidor retira
 * el primer relevo al emitir el segundo), este es el que sigue valiendo. Se
 * borra en cuanto el token vigente consigue su primer uso autenticado, que es
 * justo cuando el servidor retira el anterior.
 */
const PREVIOUS_TOKEN_KEY = 'kronoqr.kiosk.device_token_previous'
const PREVIOUS_TOKEN_EXPIRES_AT_KEY = 'kronoqr.kiosk.device_token_previous_expires_at'

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

/**
 * Ventana de actualizacion del quiosco (`KioskHeartbeat.update_window`,
 * RF-KI-07, tarea 3.12). MISMO PATRON que `service_code_hash` y los dos
 * ajustes de arriba: el planificador del latido (`heartbeat.ts`) la cachea
 * tras cada `200` para que la puerta de `features/offline/domain/
 * updateWindow.ts` funcione sin red. `localStorage` y no Dexie: es
 * preferencia de instalacion (36 bytes), no registro legal — el mismo
 * criterio de la cabecera de este fichero, no el de la cola.
 */
const UPDATE_WINDOW_KEY = 'kronoqr.kiosk.update_window'
const UPDATE_QUIET_MINUTES_KEY = 'kronoqr.kiosk.update_quiet_minutes'

/**
 * Instante del ULTIMO escaneo que ha pasado por esta tablet (RF-KI-07, tarea
 * 3.12): lo escribe `features/offline/useOfflineQueue.ts` en cuanto el
 * empleado ficha -exito, rechazo o encolado, da igual: lo que importa es que
 * hubo alguien delante de la camara-, y lo lee la puerta de actualizacion
 * para no aplicar nada dentro de los `quiet_minutes` siguientes.
 * `localStorage`, no memoria: SOBREVIVE AL REINICIO de la tablet, que es
 * justo cuando mas importa -una version pendiente que se comprueba de nuevo
 * al arrancar no puede olvidar que hubo alguien fichando cinco minutos antes
 * de apagarse-. `null` = ningun escaneo conocido (tablet nueva, o
 * `localStorage` vacio), que la puerta trata como «sin escaneo» (nunca como
 * «hace mucho»).
 */
const LAST_SCAN_AT_KEY = 'kronoqr.kiosk.last_scan_at'

/**
 * Version minima de la PWA que declaro el servidor en el ultimo latido
 * (`KioskHeartbeat.minimum_app_version`, RF-KI-07) y los intentos de recarga
 * urgente ya hechos hacia ella. MISMO PATRON que los demas ajustes del latido
 * (`localStorage`, sobreviven al reinicio). Los intentos DEBEN persistir: cada
 * recarga borra la memoria, y un contador en memoria no cortaria nunca el bucle.
 */
const MINIMUM_APP_VERSION_KEY = 'kronoqr.kiosk.minimum_app_version'
const URGENT_UPDATE_ATTEMPTS_KEY = 'kronoqr.kiosk.urgent_update_attempts'

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
 * Ventana de actualizacion vigente (RF-KI-07, tarea 3.12). SIEMPRE devuelve
 * una ventana valida: `DEFAULT_UPDATE_WINDOW` mientras esta tablet no haya
 * latido nunca con una version que la trajera -«sin configuracion recibida
 * todavia, la de serie», decision 9 de la tarea-, nunca `null`: a diferencia
 * de `readClockSkewToleranceSeconds`, aqui no hay un estado «sin banda» que
 * pintar, hay una puerta que decidir, y decidirla exige una ventana con la
 * que comparar.
 */
export function readUpdateWindow(): UpdateWindow {
  const storage = safeStorage()
  if (storage === null) return DEFAULT_UPDATE_WINDOW
  try {
    const stored = storage.getItem(UPDATE_WINDOW_KEY)
    if (stored === null || stored === '') return DEFAULT_UPDATE_WINDOW
    const parsed: unknown = JSON.parse(stored)
    return isValidUpdateWindow(parsed) ? parsed : DEFAULT_UPDATE_WINDOW
  } catch {
    return DEFAULT_UPDATE_WINDOW
  }
}

/** Lo llama SOLO el planificador del latido, tras cada `200` que traiga `update_window`. */
export function storeUpdateWindow(window: UpdateWindow): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    storage.setItem(UPDATE_WINDOW_KEY, JSON.stringify(window))
  } catch {
    // La puerta se queda con la ventana que ya tenia cacheada (degradacion honesta).
  }
}

/** Minutos de silencio vigentes (RF-KI-07, tarea 3.12). Igual criterio que `readUpdateWindow`: nunca `null`. */
export function readUpdateQuietMinutes(): number {
  const storage = safeStorage()
  if (storage === null) return DEFAULT_UPDATE_QUIET_MINUTES
  try {
    const stored = storage.getItem(UPDATE_QUIET_MINUTES_KEY)
    if (stored === null || stored === '') return DEFAULT_UPDATE_QUIET_MINUTES
    const parsed = Number(stored)
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : DEFAULT_UPDATE_QUIET_MINUTES
  } catch {
    return DEFAULT_UPDATE_QUIET_MINUTES
  }
}

/** Lo llama SOLO el planificador del latido, tras cada `200` que traiga `update_window`. */
export function storeUpdateQuietMinutes(minutes: number): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    storage.setItem(UPDATE_QUIET_MINUTES_KEY, String(minutes))
  } catch {
    // Degradacion honesta, igual que el resto de ajustes de este fichero.
  }
}

/**
 * Instante ISO del ultimo escaneo (RF-KI-07, tarea 3.12). Sobrevive al
 * reinicio de la tablet (`localStorage`, ver `LAST_SCAN_AT_KEY`). `null` =
 * ningun escaneo conocido todavia -la puerta de actualizacion lo trata como
 * «sin escaneo», nunca como «hace mucho»-.
 */
export function readLastScanAt(): string | null {
  const storage = safeStorage()
  if (storage === null) return null
  try {
    const stored = storage.getItem(LAST_SCAN_AT_KEY)
    return stored === null || stored === '' ? null : stored
  } catch {
    return null
  }
}

/** Lo llama SOLO `features/offline/useOfflineQueue.ts`, en cada escaneo que pasa por la cola. */
export function storeLastScanAt(occurredAtIso: string): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    storage.setItem(LAST_SCAN_AT_KEY, occurredAtIso)
  } catch {
    // La puerta de actualizacion se queda con el ultimo escaneo que ya tenia
    // cacheado (degradacion honesta): en el peor caso, trata un escaneo
    // reciente como si no hubiera ocurrido, y eso solo puede hacer la puerta
    // MAS permisiva, nunca menos -el resto de condiciones (cola vacia,
    // ventana) siguen aplicando igual.
  }
}

/** Nucleo `X.Y.Z` de la ultima version minima recibida. `null` = ninguna (o el servidor no la declara). */
export function readMinimumAppVersion(): string | null {
  const storage = safeStorage()
  if (storage === null) return null
  try {
    const stored = storage.getItem(MINIMUM_APP_VERSION_KEY)
    return stored === null || stored === '' ? null : stored
  } catch {
    return null
  }
}

/** Lo llama SOLO el planificador del latido, tras cada `200`. `null` borra la cacheada. */
export function storeMinimumAppVersion(version: string | null): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    if (version === null) storage.removeItem(MINIMUM_APP_VERSION_KEY)
    else storage.setItem(MINIMUM_APP_VERSION_KEY, version)
  } catch {
    // Degradacion honesta: sin almacenamiento no hay modo urgente que recordar.
  }
}

/** Intentos de recarga urgente registrados, o `null` si no hay (o el registro esta danado). */
export function readUrgentUpdateAttempts(): UrgentUpdateAttempts | null {
  const storage = safeStorage()
  if (storage === null) return null
  try {
    const stored = storage.getItem(URGENT_UPDATE_ATTEMPTS_KEY)
    if (stored === null || stored === '') return null
    const parsed: unknown = JSON.parse(stored)
    return isUrgentUpdateAttempts(parsed) ? parsed : null
  } catch {
    return null
  }
}

/** `null` borra el registro (la tablet ya cumple la minima). */
export function storeUrgentUpdateAttempts(attempts: UrgentUpdateAttempts | null): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    if (attempts === null) storage.removeItem(URGENT_UPDATE_ATTEMPTS_KEY)
    else storage.setItem(URGENT_UPDATE_ATTEMPTS_KEY, JSON.stringify(attempts))
  } catch {
    // Sin disco el contador sigue en memoria durante la sesion (`urgentUpdate.ts`);
    // solo se pierde al reiniciar, que es cuando `localStorage` vuelve a ser la fuente.
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
  // Un emparejamiento nuevo empieza sin respaldo de ningun token anterior.
  clearPreviousDeviceToken()
  storeDeviceToken(token)
  storeDeviceId(deviceId)
  storeDeviceTokenExpiresAt(expiresAt)
  storeDeviceName(deviceName)
}

/** El respaldo guardado tras un relevo: el token que dejo de ser el vigente. */
export interface PreviousDeviceToken {
  readonly value: string
  readonly expiresAt: string | null
}

/** `null` si no hay respaldo (no ha habido relevo, o el vigente ya se uso con exito). */
export function readPreviousDeviceToken(): PreviousDeviceToken | null {
  const storage = safeStorage()
  if (storage === null) return null
  try {
    const value = storage.getItem(PREVIOUS_TOKEN_KEY)
    if (value === null || value === '') return null
    const expiresAt = storage.getItem(PREVIOUS_TOKEN_EXPIRES_AT_KEY)
    return { value, expiresAt: expiresAt === null || expiresAt === '' ? null : expiresAt }
  } catch {
    return null
  }
}

export function clearPreviousDeviceToken(): void {
  const storage = safeStorage()
  if (storage === null) return
  try {
    storage.removeItem(PREVIOUS_TOKEN_KEY)
    storage.removeItem(PREVIOUS_TOKEN_EXPIRES_AT_KEY)
  } catch {
    // Un respaldo que no se puede borrar solo cuesta un reintento de mas ante un 401.
  }
}

/**
 * Relevo del token del dispositivo (RF-ID-04, ADR-044): guarda el valor nuevo,
 * su caducidad y el respaldo (`previous`, `null` = sin respaldo) como una sola
 * unidad. `localStorage` no tiene transacciones, asi que la atomicidad se
 * consigue por ORDEN y con compensacion: primero lo informativo y el respaldo,
 * y el token el ULTIMO, de modo que el cambio que importa es un unico
 * `setItem`, que el navegador aplica entero o no aplica. Si algo falla, TODAS
 * las claves vuelven a lo que eran. Devuelve `false` si no se pudo y el quiosco
 * sigue con el token anterior intacto (nunca una caducidad nueva con el token
 * viejo, ni al reves).
 */
export function storeRotatedDeviceToken(
  token: string,
  expiresAt: string | null,
  previous: PreviousDeviceToken | null,
): boolean {
  const storage = safeStorage()
  if (storage === null) return false

  const keys = [
    PREVIOUS_TOKEN_KEY,
    PREVIOUS_TOKEN_EXPIRES_AT_KEY,
    DEVICE_TOKEN_EXPIRES_AT_KEY,
    DEVICE_TOKEN_KEY,
  ]
  const snapshot = new Map<string, string | null>()
  try {
    for (const key of keys) snapshot.set(key, storage.getItem(key))
  } catch {
    return false
  }

  const write = (key: string, value: string | null): void => {
    if (value === null) storage.removeItem(key)
    else storage.setItem(key, value)
  }

  try {
    write(PREVIOUS_TOKEN_KEY, previous?.value ?? null)
    write(PREVIOUS_TOKEN_EXPIRES_AT_KEY, previous?.expiresAt ?? null)
    write(DEVICE_TOKEN_EXPIRES_AT_KEY, expiresAt)
    write(DEVICE_TOKEN_KEY, token)
    if (storage.getItem(DEVICE_TOKEN_KEY) === token) return true
  } catch {
    // Se deshace abajo.
  }

  for (const [key, value] of snapshot) {
    try {
      write(key, value)
    } catch {
      // Sin almacenamiento no hay mas que hacer: el token solo se escribe el
      // ultimo, asi que si llego a fallar es que el anterior sigue intacto.
    }
  }
  return false
}

/**
 * Solo la caducidad, cuando el servidor reentrega el MISMO token que la tablet
 * ya tiene (informativa, ninguna decision depende de ella).
 */
export function refreshDeviceTokenExpiresAt(expiresAt: string): void {
  storeDeviceTokenExpiresAt(expiresAt)
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
    // Un dispositivo revocado no tiene respaldo: la revocacion no tiene solape.
    storage.removeItem(PREVIOUS_TOKEN_KEY)
    storage.removeItem(PREVIOUS_TOKEN_EXPIRES_AT_KEY)
  } catch {
    // Nada que hacer: sin almacenamiento no habia token que borrar de verdad.
  }
}
