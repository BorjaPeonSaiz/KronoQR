// Ensamblado PURO de lo que muestra la pantalla de diagnostico (RF-KI-08,
// tarea 3.3, decision 9). Ninguna funcion de aqui toca el DOM, `vue-i18n` ni
// `Intl`: recibe lo que las distintas fuentes (camara, latido, cola, padron,
// token, bateria, wake lock, reporter, aviso de privacidad) ya saben en el
// instante en que se abre la pantalla, y decide QUE filas existen y QUE avisos
// se muestran. `DiagnosticsView.vue` traduce y formatea; esto solo decide.
//
// NUNCA UN NOMBRE. `DiagnosticsSources.token` no lleva el token en claro, solo
// su identificador corto (`tokenShortId`, calculado por quien llama con
// `sha256Hex`, nunca aqui): esta pantalla identifica por `device_id`, nunca por
// quien ficha (regla dura 21).

import type { CameraState } from '@/features/scan/composables/useCamera'

/**
 * Umbral del paso 4 de la tarea (doc 02, decision de la 3.3): por debajo de
 * esto, un fotograma recortado por el sistema operativo (encuadre automatico,
 * Windows Studio Effects) puede leer peor una tarjeta gastada, y conviene
 * saberlo sin que nadie tenga que abrir la consola del navegador.
 */
export const MIN_CAMERA_WIDTH = 1280
export const MIN_CAMERA_HEIGHT = 720

export type CameraWarningCode = 'background_blur' | 'focus_not_continuous' | 'low_resolution'

/**
 * Avisos de camara SIN BLOQUEAR (regla dura 19): informan, nunca impiden
 * seguir viendo el resto de la pantalla ni volver a fichar. `settings === null`
 * -camara cerrada, o navegador sin `getSettings()`- no es un aviso: es la
 * ausencia de datos, y de eso no hay nada que avisar.
 */
export function cameraWarnings(settings: MediaTrackSettings | null): readonly CameraWarningCode[] {
  if (settings === null) return []

  const warnings: CameraWarningCode[] = []
  if (settings.backgroundBlur === true) warnings.push('background_blur')
  if (settings.focusMode !== undefined && settings.focusMode !== 'continuous') {
    warnings.push('focus_not_continuous')
  }
  const width = settings.width ?? 0
  const height = settings.height ?? 0
  if (width > 0 && height > 0 && (width < MIN_CAMERA_WIDTH || height < MIN_CAMERA_HEIGHT)) {
    warnings.push('low_resolution')
  }
  return warnings
}

export interface DiagnosticsSources {
  readonly deviceId: string
  /** `false` sin token de dispositivo (tablet vista desde `/pair`). */
  readonly paired: boolean
  readonly camera: {
    readonly state: CameraState
    readonly settings: MediaTrackSettings | null
    readonly torchAvailable: boolean
  }
  readonly network: {
    readonly online: boolean
    /**
     * Señal VIVA de `OfflineQueueController.onReachability` (el `SyncRunner`,
     * revision de la 3.3: antes se deducia del ultimo latido y se quedaba
     * congelada mientras la pantalla estaba abierta). `null` = todavia no se
     * sabe (sin token, o ningun intento de red completado en esta sesion).
     */
    readonly reachable: boolean | null
    /** `null` = esta tablet no ha completado ningun latido en esta sesion. */
    readonly lastHeartbeat: { readonly beatAt: string; readonly skewSeconds: number | null } | null
  }
  readonly queue: {
    readonly size: number
    readonly oldestOccurredAt: string | null
    readonly durable: boolean
    readonly syncing: boolean
  }
  readonly roster: {
    readonly generatedAt: string | null
    readonly entryCount: number
    readonly pinAvailable: boolean
  }
  readonly token: {
    readonly present: boolean
    readonly expiresAt: string | null
    readonly deviceName: string | null
    /** Ocho hex de `sha256(token)`. `null` sin token. Nunca el token ni su `id|`. */
    readonly shortId: string | null
  }
  readonly appVersion: string
  readonly serviceWorkerActive: boolean | null
  readonly battery: {
    readonly supported: boolean
    readonly level: number | null
    readonly charging: boolean | null
  }
  readonly wakeLock: { readonly supported: boolean; readonly active: boolean }
  readonly pendingErrors: number
  readonly privacyControllerConfigured: boolean
}

export interface DiagnosticsSnapshot {
  readonly deviceId: string
  readonly paired: boolean
  readonly camera: DiagnosticsSources['camera'] & {
    readonly warnings: readonly CameraWarningCode[]
  }
  readonly network: DiagnosticsSources['network']
  readonly queue: DiagnosticsSources['queue']
  readonly roster: DiagnosticsSources['roster']
  readonly token: DiagnosticsSources['token']
  readonly appVersion: string
  readonly serviceWorkerActive: boolean | null
  readonly battery: DiagnosticsSources['battery']
  readonly wakeLock: DiagnosticsSources['wakeLock']
  readonly pendingErrors: number
  readonly privacyControllerConfigured: boolean
}

/**
 * Arma el ensamblado a partir de las fuentes. `network.reachable` llega YA
 * resuelto desde `sources` (la suscripcion viva al `SyncRunner`): esta
 * funcion no lo deduce de nada, solo lo pasa.
 */
export function buildDiagnosticsSnapshot(sources: DiagnosticsSources): DiagnosticsSnapshot {
  return {
    deviceId: sources.deviceId,
    paired: sources.paired,
    camera: { ...sources.camera, warnings: cameraWarnings(sources.camera.settings) },
    network: sources.network,
    queue: sources.queue,
    roster: sources.roster,
    token: sources.token,
    appVersion: sources.appVersion,
    serviceWorkerActive: sources.serviceWorkerActive,
    battery: sources.battery,
    wakeLock: sources.wakeLock,
    pendingErrors: sources.pendingErrors,
    privacyControllerConfigured: sources.privacyControllerConfigured,
  }
}
