// Control explicito del `MediaStream`.
//
// No se le delega a ZXing la obtencion de la camara: se pide aqui, con las
// restricciones que el quiosco necesita, y se le entrega el stream ya abierto.
// Motivo: la resolucion, el enfoque continuo y la linterna deciden si una
// tarjeta gastada se lee a la primera o hay que insistir tres veces con una cola
// detras.
//
// LIBERACION DE RECURSOS. `stop()` para todas las pistas y suelta la referencia,
// y es idempotente: llamarlo dos veces no falla. Es lo que se invoca en
// `onUnmounted` y en cada cambio de visibilidad. El bucle corre 8 horas
// seguidas; una pista de video que no se para mantiene el sensor encendido,
// calienta el aparato y se lleva la bateria.

import type { Ref, ShallowRef } from 'vue'
import { readonly, ref, shallowRef } from 'vue'
import { errorTypeOf } from '@/shared/telemetry/errorType'

export type CameraState = 'idle' | 'starting' | 'running' | 'denied' | 'unavailable'

export type CameraFailure = 'permission_denied' | 'unavailable'

export interface UseCameraOptions {
  readonly width?: number
  readonly height?: number
  readonly onFailure?: (
    failure: CameraFailure,
    context: Record<string, string | number | boolean>,
  ) => void
}

export interface CameraController {
  readonly state: Readonly<Ref<CameraState>>
  readonly stream: Readonly<ShallowRef<MediaStream | null>>
  readonly torchAvailable: Readonly<Ref<boolean>>
  readonly torchOn: Readonly<Ref<boolean>>
  start(): Promise<MediaStream | null>
  stop(): void
  toggleTorch(): Promise<void>
}

/** 720p: suficiente para un QR version 3 a 20 cm y la mitad de pixeles que 1080p. */
const DEFAULT_WIDTH = 1280
const DEFAULT_HEIGHT = 720

export function useCamera(options: UseCameraOptions = {}): CameraController {
  const state = ref<CameraState>('idle')
  const stream = shallowRef<MediaStream | null>(null)
  const torchAvailable = ref(false)
  const torchOn = ref(false)

  function constraints(): MediaStreamConstraints {
    return {
      audio: false,
      video: {
        // `environment` en la tablet de pared es la camara que mira al empleado.
        facingMode: { ideal: 'environment' },
        width: { ideal: options.width ?? DEFAULT_WIDTH },
        height: { ideal: options.height ?? DEFAULT_HEIGHT },
        // Techo de 30 fps: el decodificador no aprovecha mas y cada fotograma de
        // mas es bateria y calor durante ocho horas.
        frameRate: { ideal: 30, max: 30 },
      },
    }
  }

  /**
   * Enfoque continuo. Va DESPUES de abrir el stream y no en las restricciones
   * iniciales: pedirlo de entrada hace que `getUserMedia` falle entero en los
   * dispositivos que no lo soportan, y quedarse sin camara por no poder enfocar
   * automaticamente seria absurdo.
   */
  async function applyContinuousFocus(track: MediaStreamTrack): Promise<void> {
    try {
      const capabilities = track.getCapabilities()
      if (capabilities.focusMode?.includes('continuous') === true) {
        await track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] })
      }
    } catch {
      // Sin enfoque continuo se sigue escaneando. No se reporta: no es un fallo.
    }
  }

  function detectTorch(track: MediaStreamTrack): void {
    try {
      torchAvailable.value = track.getCapabilities().torch === true
    } catch {
      torchAvailable.value = false
    }
  }

  // Contador de generacion: lo incrementa `stop()`. Sirve para detectar que se
  // ha pedido parar MIENTRAS `getUserMedia` seguia resolviendo.
  //
  // Sin esto hay una fuga real y dificil de ver: si la pantalla se desmonta —o
  // la tablet se bloquea— en el segundo que tarda el permiso de camara en
  // concederse, `stop()` no encuentra ningun stream que parar, y el que llega
  // despues se queda vivo con el sensor encendido y sin nadie que lo apague. Es
  // exactamente el patron que sobrevive a una prueba de cinco minutos.
  let generation = 0

  function stopTracks(media: MediaStream): void {
    for (const track of media.getTracks()) {
      try {
        track.stop()
      } catch {
        // Una pista ya parada lanza en algunos navegadores. Da igual: el
        // objetivo era que dejara de estarlo.
      }
    }
  }

  async function start(): Promise<MediaStream | null> {
    if (stream.value !== null) return stream.value

    const media = globalThis.navigator?.mediaDevices
    if (media === undefined) {
      state.value = 'unavailable'
      options.onFailure?.('unavailable', { reason: 'no_media_devices' })
      return null
    }

    const startedGeneration = generation
    state.value = 'starting'
    try {
      const opened = await media.getUserMedia(constraints())

      if (startedGeneration !== generation) {
        stopTracks(opened)
        return null
      }

      const [track] = opened.getVideoTracks()
      if (track !== undefined) {
        detectTorch(track)
        await applyContinuousFocus(track)
      }

      if (startedGeneration !== generation) {
        stopTracks(opened)
        return null
      }

      stream.value = opened
      state.value = 'running'
      return opened
    } catch (error) {
      const name = errorTypeOf(error)
      const denied = name === 'NotAllowedError' || name === 'SecurityError'
      state.value = denied ? 'denied' : 'unavailable'
      options.onFailure?.(denied ? 'permission_denied' : 'unavailable', { error_type: name })
      return null
    }
  }

  function stop(): void {
    // Invalida cualquier arranque en vuelo antes de nada.
    generation += 1

    const opened = stream.value
    stream.value = null
    torchOn.value = false
    torchAvailable.value = false
    state.value = 'idle'

    if (opened === null) return
    stopTracks(opened)
  }

  async function toggleTorch(): Promise<void> {
    const opened = stream.value
    if (opened === null || !torchAvailable.value) return

    const next = !torchOn.value
    for (const track of opened.getVideoTracks()) {
      try {
        await track.applyConstraints({ advanced: [{ torch: next }] })
        torchOn.value = next
      } catch {
        torchAvailable.value = false
      }
    }
  }

  return {
    state: readonly(state),
    stream: stream as Readonly<ShallowRef<MediaStream | null>>,
    torchAvailable: readonly(torchAvailable),
    torchOn: readonly(torchOn),
    start,
    stop,
    toggleTorch,
  }
}
