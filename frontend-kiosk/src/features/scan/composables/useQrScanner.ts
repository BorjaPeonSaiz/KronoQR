// Bucle de decodificacion continuo, sin interaccion del usuario (RF-KI-02).
//
// ADVERTENCIA QUE JUSTIFICA LA MITAD DE ESTE FICHERO (doc 03 §4.3):
//
//   «El bucle de decodificacion corre durante turnos de 8 horas. Una fuga aqui
//   tumba la tablet a media tarde y no aparece en pruebas de 5 minutos.»
//
// Lo que se hace en consecuencia:
//
//   1. `teardown()` es idempotente y para TODO: controles de ZXing, pistas de
//      video, temporizadores y escuchas. Se llama en `onUnmounted`, al ocultarse
//      la pagina y antes de cada rearranque.
//   2. La camara se libera cuando la pagina deja de ser visible. Una tablet con
//      la pantalla apagada y el sensor encendido es el caso que se descubre por
//      la temperatura del aparato, no por una traza.
//   3. Un perro guardian vigila que el bucle siga latiendo. ZXing detiene su
//      bucle ante un error que no sea «no he encontrado codigo» —y no hay forma
//      de enterarse desde fuera—, asi que si pasan segundos sin ningun intento,
//      se rearranca. Un quiosco que dejo de escanear en silencio a las 11:00 es
//      un dia entero de fichajes perdidos.
//   4. `@zxing/browser` se carga con `import()`. No hace falta para pintar la
//      primera pantalla y sacarlo del arranque es lo que mantiene el JS critico
//      dentro del presupuesto del Anexo A.

import type { Ref } from 'vue'
import { onUnmounted, readonly, ref } from 'vue'
import { errorTypeOf } from '@/shared/telemetry/errorType'
import type { CameraFailure } from './useCamera'
import { useCamera } from './useCamera'

export type ScannerState = 'idle' | 'starting' | 'scanning' | 'denied' | 'unavailable'

export type ScannerDiagnostic =
  | 'camera.permission_denied'
  | 'camera.unavailable'
  | 'scanner.start_failed'
  | 'scanner.decoder_load_failed'
  | 'scanner.watchdog_restart'

/** Sin ningun intento de decodificacion en este tiempo, se rearranca. */
const WATCHDOG_SILENCE_MS = 12_000
const WATCHDOG_TICK_MS = 4_000

/** Un intento cada 100 ms: 10 por segundo, de sobra para un gesto humano. */
const DELAY_BETWEEN_ATTEMPTS_MS = 100
/** Tras acertar, ZXing espera esto antes de volver a intentarlo. */
const DELAY_BETWEEN_SUCCESS_MS = 800

interface ScannerControls {
  stop: () => void
}

export interface UseQrScannerOptions {
  readonly video: Ref<HTMLVideoElement | null>
  readonly onDecoded: (text: string) => void
  readonly onDiagnostic?: (
    code: ScannerDiagnostic,
    context: Record<string, string | number | boolean>,
  ) => void
}

export interface QrScanner {
  readonly state: Readonly<Ref<ScannerState>>
  readonly torchAvailable: Readonly<Ref<boolean>>
  readonly torchOn: Readonly<Ref<boolean>>
  start(): Promise<void>
  stop(): void
  toggleTorch(): Promise<void>
}

export function useQrScanner(options: UseQrScannerOptions): QrScanner {
  const state = ref<ScannerState>('idle')
  const camera = useCamera({
    onFailure: (failure: CameraFailure, context) => {
      state.value = failure === 'permission_denied' ? 'denied' : 'unavailable'
      options.onDiagnostic?.(
        failure === 'permission_denied' ? 'camera.permission_denied' : 'camera.unavailable',
        context,
      )
    },
  })

  let controls: ScannerControls | null = null
  let watchdog: ReturnType<typeof setInterval> | null = null
  let lastAttemptAtMs = 0
  let restarting = false
  let disposed = false

  function teardown(): void {
    if (watchdog !== null) {
      clearInterval(watchdog)
      watchdog = null
    }

    const current = controls
    controls = null
    if (current !== null) {
      try {
        current.stop()
      } catch {
        // Da igual por que se queje: lo siguiente para las pistas de todos modos.
      }
    }

    // Redundante con el `stop()` de ZXing, y a proposito. Es la unica linea que
    // garantiza que el sensor se apaga aunque la libreria cambie de opinion en
    // una version futura.
    camera.stop()

    if (state.value === 'scanning' || state.value === 'starting') state.value = 'idle'
  }

  async function launch(): Promise<void> {
    const element = options.video.value
    if (element === null) {
      state.value = 'unavailable'
      options.onDiagnostic?.('scanner.start_failed', { reason: 'no_video_element' })
      return
    }

    state.value = 'starting'

    const stream = await camera.start()
    if (stream === null) return // `useCamera` ya ha fijado el estado y avisado.
    if (disposed) {
      camera.stop()
      return
    }

    let BrowserQRCodeReader
    try {
      ;({ BrowserQRCodeReader } = await import('@zxing/browser'))
    } catch (error) {
      camera.stop()
      state.value = 'unavailable'
      options.onDiagnostic?.('scanner.decoder_load_failed', {
        error_type: errorTypeOf(error),
      })
      return
    }

    if (disposed) {
      camera.stop()
      return
    }

    try {
      const reader = new BrowserQRCodeReader(undefined, {
        delayBetweenScanAttempts: DELAY_BETWEEN_ATTEMPTS_MS,
        delayBetweenScanSuccess: DELAY_BETWEEN_SUCCESS_MS,
      })

      lastAttemptAtMs = Date.now()
      controls = await reader.decodeFromStream(stream, element, (result) => {
        // Se invoca tanto al acertar como al no encontrar nada. Los fallos de
        // decodificacion NO se reportan: son el caso normal diez veces por
        // segundo, y anotarlos llenaria `error_events` de ruido en una hora.
        lastAttemptAtMs = Date.now()
        if (result === undefined) return
        options.onDecoded(result.getText())
      })

      if (disposed) {
        teardown()
        return
      }

      state.value = 'scanning'
      startWatchdog()
    } catch (error) {
      camera.stop()
      state.value = 'unavailable'
      options.onDiagnostic?.('scanner.start_failed', {
        error_type: errorTypeOf(error),
      })
    }
  }

  function startWatchdog(): void {
    if (watchdog !== null) clearInterval(watchdog)
    watchdog = setInterval(() => {
      if (state.value !== 'scanning' || restarting) return
      if (Date.now() - lastAttemptAtMs < WATCHDOG_SILENCE_MS) return
      options.onDiagnostic?.('scanner.watchdog_restart', {
        silence_ms: Date.now() - lastAttemptAtMs,
      })
      void restart()
    }, WATCHDOG_TICK_MS)
  }

  async function restart(): Promise<void> {
    if (restarting || disposed) return
    restarting = true
    try {
      teardown()
      await launch()
    } finally {
      restarting = false
    }
  }

  async function start(): Promise<void> {
    if (disposed) return
    if (controls !== null) return
    await launch()
  }

  function stop(): void {
    teardown()
  }

  // Cambio de visibilidad: soltar la camara al ocultarse y recuperarla al
  // volver. Sin esto, una tablet que se bloquea de noche pasa ocho horas con el
  // sensor abierto.
  function onVisibilityChange(): void {
    if (disposed) return
    if (document.visibilityState === 'hidden') {
      teardown()
    } else {
      void start()
    }
  }

  document.addEventListener('visibilitychange', onVisibilityChange)

  onUnmounted(() => {
    disposed = true
    document.removeEventListener('visibilitychange', onVisibilityChange)
    teardown()
  })

  return {
    state: readonly(state),
    torchAvailable: camera.torchAvailable,
    torchOn: camera.torchOn,
    start,
    stop,
    toggleTorch: camera.toggleTorch,
  }
}
