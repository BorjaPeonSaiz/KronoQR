// Registro del service worker (RF-KI-01).
//
// Precachea el *app shell* para que el quiosco arranque sin red: una tablet que
// se reinicia a las 05:50 con el router del hotel caido tiene que poder fichar a
// las 06:00.
//
// LA ACTUALIZACION NUNCA SE APLICA SOLA. `registerType: 'prompt'` en
// `vite.config.ts` y, aqui, `onNeedRefresh` que se limita a ANOTAR que hay
// version nueva. Una actualizacion que se aplica sola recarga la pagina, y si
// eso pasa a las 06:00 con quince personas en la cola, el quiosco esta muerto
// justo en el minuto que existe para cubrir.
//
// LA PUERTA (RF-KI-07, tarea 3.12). `applyUpdate` consulta un guardian
// (`canApply`, normalmente `features/offline/domain/updateWindow.ts` ->
// `canApplyUpdate`, cableado desde `main.ts`) antes de recargar: cola vacia,
// sin escaneo reciente y dentro de la ventana que declaro el centro.
//
// COMO SE ENTERA Y CUANDO APLICA (decision 10 de la tarea 3.12). La pagina de
// un quiosco vive dias sin volver a navegar, asi que dos temporizadores
// propios, no la deteccion por defecto del navegador (que solo comprueba en
// cada navegacion, y esta SPA no vuelve a navegar nunca):
//
//   1. Cada `checkForUpdateIntervalMs` (60 min de serie) se pregunta al
//      navegador si hay una version mas nueva publicada (`registration.update()`).
//   2. En cuanto hay una version pendiente (`onNeedRefresh`), un segundo
//      temporizador reevalua la puerta cada `retryIntervalMs` (1 min de
//      serie) y aplica en cuanto la deja pasar. Si la ventana esta cerrada
//      ahora, se reintenta al minuto siguiente; no hace falta que nadie
//      vuelva a pedirlo.

import type { RegisterSWOptions } from 'virtual:pwa-register'
import { errorMessageOf, errorTypeOf } from '@/shared/telemetry/errorType'
import { installTestHooks } from './testHooks'

/** Forma minima que necesita este fichero de `registerSW`, para poder inyectarla en pruebas. */
export type RegisterSWLoader = () => Promise<{
  readonly registerSW: (options?: RegisterSWOptions) => (reloadPage?: boolean) => Promise<void>
}>

const defaultLoadRegisterSW: RegisterSWLoader = () => import('virtual:pwa-register')

export interface ServiceWorkerRegistrationResult {
  /** Hay una version nueva esperando. No se aplica sin que la puerta lo permita. */
  readonly needsRefresh: () => boolean
  /**
   * Aplica la version pendiente y recarga, **si el guardian lo permite**. Se
   * llama tambien SOLA, desde el temporizador de reintento: exponerla no es
   * solo para pruebas o botones futuros.
   * @returns `false` si no habia nada que aplicar o si el momento no es bueno.
   */
  readonly applyUpdate: () => Promise<boolean>
  /** Libera los dos temporizadores. Solo pruebas: esta pantalla vive toda la sesion en produccion. */
  readonly dispose: () => void
}

export interface RegisterServiceWorkerOptions {
  readonly onUpdateAvailable?: () => void
  readonly onOfflineReady?: () => void
  readonly onError?: (context: Record<string, string | number | boolean>) => void
  /** `false` = ahora no. Por defecto, se permite: quien no pasa guardian, decide. */
  readonly canApply?: () => boolean
  /** Cada cuanto se comprueba si hay una version nueva publicada. 60 min de serie. */
  readonly checkForUpdateIntervalMs?: number
  /** Cada cuanto se reevalua la puerta mientras hay una version pendiente. 1 min de serie. */
  readonly retryIntervalMs?: number
  /**
   * Inyectable para pruebas: por defecto, `import('virtual:pwa-register')`.
   * Bajo Vitest ese modulo virtual no resuelve (limitacion del cargador de
   * modulos del entorno de pruebas en Windows, no del codigo de produccion:
   * la PWA real lo sirve `vite-plugin-pwa` en cada build); las pruebas del
   * planificador de aplicacion pasan aqui un doble que simula `onNeedRefresh`.
   */
  readonly loadRegisterSW?: RegisterSWLoader
}

const DEFAULT_CHECK_FOR_UPDATE_INTERVAL_MS = 60 * 60_000
const DEFAULT_RETRY_INTERVAL_MS = 60_000

/** `null` = ninguna tablet ha registrado nada todavia en esta sesion, o el navegador no tiene soporte. */
let sharedPendingState: (() => boolean) | null = null

/**
 * Estado de la actualizacion para la pantalla de diagnostico (RF-KI-08, tarea
 * 3.12). MISMO PATRON que `getLastHeartbeatResult` en `heartbeat.ts`: un
 * singleton de modulo, porque solo hay UN registro de service worker por
 * tablet y la pantalla de diagnostico no es quien lo crea.
 */
export function isUpdatePending(): boolean {
  return sharedPendingState?.() ?? false
}

export async function registerServiceWorker(
  options: RegisterServiceWorkerOptions = {},
): Promise<ServiceWorkerRegistrationResult> {
  let pending = false
  let update: ((reloadPage?: boolean) => Promise<void>) | null = null
  let registration: ServiceWorkerRegistration | undefined
  let checkTimer: ReturnType<typeof setInterval> | null = null
  let retryTimer: ReturnType<typeof setInterval> | null = null

  const canApply = options.canApply ?? ((): boolean => true)
  const checkIntervalMs = options.checkForUpdateIntervalMs ?? DEFAULT_CHECK_FOR_UPDATE_INTERVAL_MS
  const retryIntervalMs = options.retryIntervalMs ?? DEFAULT_RETRY_INTERVAL_MS
  const loadRegisterSW = options.loadRegisterSW ?? defaultLoadRegisterSW

  const noop: ServiceWorkerRegistrationResult = {
    needsRefresh: () => false,
    applyUpdate: async () => false,
    dispose: () => {},
  }

  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return noop

  function stopRetryTimer(): void {
    if (retryTimer === null) return
    clearInterval(retryTimer)
    retryTimer = null
  }

  /** Arma (o rearma) el intervalo de reintento, sin intentar aplicar todavia. */
  function armRetryTimer(): void {
    stopRetryTimer()
    retryTimer = setInterval(() => void applyUpdate(), retryIntervalMs)
  }

  async function applyUpdate(): Promise<boolean> {
    if (!pending || update === null) return false
    // Se consulta AQUI, cada vez que se llama: entre «hay version nueva» y
    // «aplicala» pueden pasar horas -o solo un minuto, con el temporizador de
    // reintento-, y lo que importa es el momento de la recarga. `pending` no
    // se limpia si se deniega: la version sigue ahi esperando un momento mejor.
    if (!canApply()) return false
    pending = false
    stopRetryTimer()
    try {
      await update(true)
      return true
    } catch (error) {
      // La recarga NO llego a completarse (el service worker no confirmo el
      // control a tiempo, por ejemplo): la version sigue pendiente -nada se
      // aplico de verdad- y el temporizador de reintento vuelve a armarse, en
      // vez de dejar una promesa rechazada suelta desde `void applyUpdate()`
      // (que dispara un `unhandledrejection` sin que nadie la escuche) y una
      // puerta que se queda creyendo que ya aplico cuando en realidad fallo.
      // No se reintenta EN EL ACTO: un fallo de `update(true)` es mas
      // probable que se repita en el mismo instante que pasado un minuto.
      pending = true
      options.onError?.({ error_type: errorTypeOf(error), message: errorMessageOf(error) })
      armRetryTimer()
      return false
    }
  }

  function startRetryTimer(): void {
    // El intervalo se arma ANTES del primer intento, no despues: si la puerta
    // deja pasar a la primera, `applyUpdate` llama a `stopRetryTimer()` en
    // cuanto decide aplicar, y necesita encontrar YA un intervalo que limpiar
    // -si se armara despues del intento, `stopRetryTimer()` no encontraria
    // nada (todavia `null`) y el intervalo quedaria vivo para siempre,
    // reintentando cada minuto una version que ya se aplico-.
    armRetryTimer()
    // Se intenta YA -la ventana puede estar abierta desde antes de que
    // llegara esta version- y despues cada minuto: si esta cerrada ahora,
    // puede abrirse pronto, y una tablet que vive dias sin volver a navegar
    // no puede esperar a la siguiente carga de pagina para comprobarlo.
    void applyUpdate()
  }

  try {
    const { registerSW } = await loadRegisterSW()
    update = registerSW({
      immediate: true,
      onNeedRefresh() {
        pending = true
        options.onUpdateAvailable?.()
        startRetryTimer()
      },
      onOfflineReady() {
        options.onOfflineReady?.()
      },
      onRegisteredSW(_swScriptUrl, reg) {
        registration = reg
      },
      onRegisterError(error: unknown) {
        options.onError?.({ error_type: errorTypeOf(error), message: errorMessageOf(error) })
      },
    })
  } catch (error) {
    options.onError?.({ error_type: errorTypeOf(error), message: errorMessageOf(error) })
    return noop
  }

  // Comprobacion periodica de version (decision 10 de la tarea 3.12): sin
  // esto, una tablet que nunca vuelve a navegar solo se enteraria de una
  // version nueva si alguien la reinicia a mano. Un fallo al comprobar no es
  // una averia (regla dura 19, al reves): la tablet sigue con la version que
  // ya tiene, y se reintenta en el siguiente ciclo.
  checkTimer = setInterval(() => {
    void registration?.update().catch(() => {})
  }, checkIntervalMs)

  const result: ServiceWorkerRegistrationResult = {
    needsRefresh: () => pending,
    applyUpdate,
    dispose: () => {
      if (checkTimer !== null) clearInterval(checkTimer)
      checkTimer = null
      stopRetryTimer()
      if (sharedPendingState === result.needsRefresh) sharedPendingState = null
    },
  }

  sharedPendingState = result.needsRefresh

  let appliedForTests = false
  installTestHooks({
    simulateUpdateAvailable: () => {
      pending = true
      // SIEMPRE se sustituye, no solo si `update` seguia en `null`: el `update`
      // real (de haberlo) llamaria a `sendSkipWaitingMessage()` sobre un
      // service worker que en este build no tiene ninguna version en espera
      // de verdad, y no recargaria nada -exactamente lo que la cabecera de
      // `testHooks.ts` explica que no se puede fabricar aqui-. El marcador
      // observable ocupa el MISMO sitio por el que pasaria esa recarga; la
      // puerta (`canApply`) que decide si se llega a el es la de produccion.
      update = async () => {
        appliedForTests = true
      }
      options.onUpdateAvailable?.()
      startRetryTimer()
    },
    hasAppliedUpdate: () => appliedForTests,
  })

  return result
}
