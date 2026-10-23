// Ajustes del quiosco que cachea el latido (RF-KI-07, RF-KI-08, RF-AT-10, RF-AT-12).
//
// Antes vivian en `shared/telemetry/deviceIdentity.ts` (hoy `shared/device/deviceIdentity.ts`) junto a la identidad y el
// token (R1-RV-06). Son preferencias tecnicas de la instalacion: las escribe el
// planificador del latido tras cada `200` y se leen en local para que el boton
// «Pausa», el aviso de desfase, la puerta de actualizacion y el diagnostico
// funcionen sin red.
//
// `localStorage` aqui SI es adecuado, y no contradice la prohibicion de usarlo
// para la cola: son unos pocos bytes cuya perdida no tiene consecuencias. La cola
// es registro legal sin escribir y va a IndexedDB.

import { isValidTimeZone } from '@kronoqr/web-kit/datetime'
import type { UpdateWindow } from '@/features/offline/domain/updateWindow'
import {
  DEFAULT_UPDATE_QUIET_MINUTES,
  DEFAULT_UPDATE_WINDOW,
  isValidUpdateWindow,
} from '@/features/offline/domain/updateWindow'
import type { UrgentUpdateAttempts } from '@/features/offline/domain/minimumVersion'
import { isUrgentUpdateAttempts } from '@/features/offline/domain/minimumVersion'
import { deviceStorage } from './deviceStorage'

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
 * Zona horaria del centro (`KioskHeartbeat.site_time_zone`, IANA, V2-KI-01,
 * RF-AT-05, RF-KI-07). MISMO PATRON que los ajustes de arriba: la cachea el
 * latido para que la hora de la confirmacion y la ventana de actualizacion se
 * lean en el reloj de pared del centro tambien sin red. Solo PRESENTACION: no
 * interviene en nada que se encole (regla dura 3).
 */
const SITE_TIME_ZONE_KEY = 'kronoqr.kiosk.site_time_zone'

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

/**
 * Huella del codigo de servicio cacheada por el ultimo latido con `200`
 * (RF-KI-08, tarea 3.3). `null` = sin codigo configurado en esta instalacion,
 * O tablet que aun no ha latido nunca: los dos casos abren la pantalla de
 * diagnostico sin pedir codigo (decision 7 de la tarea 3.3), que es la
 * respuesta correcta para los dos.
 */
export function readServiceCodeHash(): string | null {
  const storage = deviceStorage()
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
  const storage = deviceStorage()
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
  const storage = deviceStorage()
  if (storage === null) return false
  try {
    return storage.getItem(BREAK_CLOCKING_ENABLED_KEY) === '1'
  } catch {
    return false
  }
}

/** Lo llama SOLO el planificador del latido, tras cada `200`. */
export function storeBreakClockingEnabled(enabled: boolean): void {
  const storage = deviceStorage()
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
  const storage = deviceStorage()
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
  const storage = deviceStorage()
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
  const storage = deviceStorage()
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
  const storage = deviceStorage()
  if (storage === null) return
  try {
    storage.setItem(UPDATE_WINDOW_KEY, JSON.stringify(window))
  } catch {
    // La puerta se queda con la ventana que ya tenia cacheada (degradacion honesta).
  }
}

/** Minutos de silencio vigentes (RF-KI-07, tarea 3.12). Igual criterio que `readUpdateWindow`: nunca `null`. */
export function readUpdateQuietMinutes(): number {
  const storage = deviceStorage()
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
  const storage = deviceStorage()
  if (storage === null) return
  try {
    storage.setItem(UPDATE_QUIET_MINUTES_KEY, String(minutes))
  } catch {
    // Degradacion honesta, igual que el resto de ajustes de este fichero.
  }
}

/**
 * Zona del centro cacheada, o `null` = usar la de la tablet: tablet que aun no
 * ha latido con una version que la trajera, servidor que la omite, o un nombre
 * que el navegador no reconoce (se descarta aqui, sin error: la hora se pinta
 * igual con la zona del aparato, regla dura 19).
 */
export function readSiteTimeZone(): string | null {
  const storage = deviceStorage()
  if (storage === null) return null
  try {
    const stored = storage.getItem(SITE_TIME_ZONE_KEY)
    return stored !== null && isValidTimeZone(stored) ? stored : null
  } catch {
    return null
  }
}

/**
 * Lo llama SOLO el planificador del latido, tras cada `200`. `undefined` (el
 * servidor la omite: aun no hay centro, o no la pudo leer) CONSERVA la
 * cacheada, igual que `minimum_app_version` ausente en un latido que no dijo
 * nada; con fichajes hechos la zona del centro no cambia (ADR-056). Un nombre
 * que `Intl` no reconoce BORRA la cacheada: mas vale la zona de la tablet que
 * una que no se sabe leer.
 */
export function storeSiteTimeZone(zone: string | undefined): void {
  if (zone === undefined) return
  const storage = deviceStorage()
  if (storage === null) return
  try {
    if (isValidTimeZone(zone)) storage.setItem(SITE_TIME_ZONE_KEY, zone)
    else storage.removeItem(SITE_TIME_ZONE_KEY)
  } catch {
    // Degradacion honesta: se sigue con la zona que ya tenia, o con la de la tablet.
  }
}

/**
 * Instante ISO del ultimo escaneo (RF-KI-07, tarea 3.12). Sobrevive al
 * reinicio de la tablet (`localStorage`, ver `LAST_SCAN_AT_KEY`). `null` =
 * ningun escaneo conocido todavia -la puerta de actualizacion lo trata como
 * «sin escaneo», nunca como «hace mucho»-.
 */
export function readLastScanAt(): string | null {
  const storage = deviceStorage()
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
  const storage = deviceStorage()
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
  const storage = deviceStorage()
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
  const storage = deviceStorage()
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
  const storage = deviceStorage()
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
  const storage = deviceStorage()
  if (storage === null) return
  try {
    if (attempts === null) storage.removeItem(URGENT_UPDATE_ATTEMPTS_KEY)
    else storage.setItem(URGENT_UPDATE_ATTEMPTS_KEY, JSON.stringify(attempts))
  } catch {
    // Sin disco el contador sigue en memoria durante la sesion (`urgentUpdate.ts`);
    // solo se pierde al reiniciar, que es cuando `localStorage` vuelve a ser la fuente.
  }
}
