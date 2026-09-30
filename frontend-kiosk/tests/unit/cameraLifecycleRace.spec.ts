// KT4 (RF-KI-02, RF-KI-01): parar o desmontar con `getUserMedia()` TODAVIA en
// curso.
//
// Es la fuga de camara que aparece en turnos de 8 h y nunca en una prueba de
// cinco minutos: la pantalla se desmonta (o la tablet se bloquea) en el segundo
// que tarda el permiso en concederse, `stop()` no encuentra ningun stream que
// parar, y el que llega despues se queda vivo con el sensor encendido y nadie
// que lo apague. Aqui cada `getUserMedia` y cada `decodeFromStream` se retienen a
// mano para poder parar EN MEDIO, y se comprueba que ninguna pista queda abierta.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useCamera } from '@/features/scan/composables/useCamera'
import { useQrScanner } from '@/features/scan/composables/useQrScanner'
import { withSetup } from './support/withSetup'

interface OpenedCamera {
  readonly stream: MediaStream
  readonly stop: ReturnType<typeof vi.fn>
}

/** Cada llamada a `getUserMedia` queda pendiente hasta que la prueba la resuelva. */
const pendingOpen: Array<(camera: OpenedCamera) => void> = []
const opened: OpenedCamera[] = []
/** Cuando no es `null`, `applyConstraints` (enfoque) espera a esta promesa. */
let focusGate: Promise<void> | null = null
/** Cuando no es `null`, `decodeFromStream` espera a esta promesa. */
let decodeGate: Promise<void> | null = null
const scannerStops: Array<ReturnType<typeof vi.fn>> = []

vi.mock('@zxing/browser', () => ({
  BrowserQRCodeReader: class {
    async decodeFromStream() {
      if (decodeGate !== null) await decodeGate
      const stop = vi.fn()
      scannerStops.push(stop)
      return { stop }
    }
  },
}))

function makeCamera(): OpenedCamera {
  const stop = vi.fn()
  const track = {
    kind: 'video',
    stop,
    applyConstraints: vi.fn(async () => {
      if (focusGate !== null) await focusGate
    }),
    getCapabilities: vi.fn(() => ({ focusMode: ['continuous'] })),
  }
  const stream = {
    getTracks: () => [track],
    getVideoTracks: () => [track],
  } as unknown as MediaStream
  const camera = { stream, stop }
  opened.push(camera)
  return camera
}

function installDeferredCamera(): void {
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: {
      getUserMedia: vi.fn(
        () =>
          new Promise<MediaStream>((resolve) => {
            pendingOpen.push((camera) => resolve(camera.stream))
          }),
      ),
    },
  })
}

/** Concede el permiso a la PRIMERA solicitud pendiente. */
function grantPermission(): OpenedCamera {
  const camera = makeCamera()
  const resolve = pendingOpen.shift()
  if (resolve === undefined) throw new Error('no hay ninguna solicitud de camara pendiente')
  resolve(camera)
  return camera
}

function setVisibility(value: DocumentVisibilityState): void {
  Object.defineProperty(document, 'visibilityState', { value, configurable: true })
}

/** Cede el control hasta que las microtareas encadenadas terminen. */
async function settle(): Promise<void> {
  await vi.advanceTimersByTimeAsync(0)
}

beforeEach(() => {
  vi.useFakeTimers()
  pendingOpen.length = 0
  opened.length = 0
  scannerStops.length = 0
  focusGate = null
  decodeGate = null
  installDeferredCamera()
  setVisibility('visible')
})

afterEach(() => {
  vi.useRealTimers()
  Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true })
})

describe('useCamera: parar con getUserMedia en curso (KT4, RF-KI-02, RF-KI-01)', () => {
  it('si se para ANTES de que llegue el stream, al llegar se apaga y no queda camara viva', async () => {
    const { result, wrapper } = withSetup(() => useCamera())

    const starting = result.start()
    expect(result.state.value).toBe('starting')

    result.stop()
    const camera = grantPermission()
    const outcome = await starting

    expect(outcome).toBeNull()
    expect(camera.stop).toHaveBeenCalledTimes(1)
    expect(result.stream.value).toBeNull()
    expect(result.state.value).toBe('idle')
    wrapper.unmount()
  })

  it('si se para MIENTRAS se aplica el enfoque continuo, tambien se apaga', async () => {
    let releaseFocus: () => void = () => undefined
    focusGate = new Promise<void>((resolve) => {
      releaseFocus = resolve
    })
    const { result, wrapper } = withSetup(() => useCamera())

    const starting = result.start()
    const camera = grantPermission()
    await settle() // el stream ya llego y el enfoque esta en vuelo

    result.stop()
    releaseFocus()
    const outcome = await starting

    expect(outcome).toBeNull()
    expect(camera.stop).toHaveBeenCalledTimes(1)
    expect(result.stream.value).toBeNull()
    wrapper.unmount()
  })

  it('un arranque NUEVO tras parar no hereda al anterior: la generacion solo invalida lo viejo', async () => {
    const { result, wrapper } = withSetup(() => useCamera())

    const first = result.start()
    result.stop()
    const second = result.start()

    const stale = grantPermission()
    const fresh = grantPermission()

    expect(await first).toBeNull()
    expect(await second).not.toBeNull()
    expect(stale.stop).toHaveBeenCalledTimes(1)
    expect(fresh.stop).not.toHaveBeenCalled()
    expect(result.state.value).toBe('running')

    result.stop()
    expect(fresh.stop).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
})

describe('useQrScanner: desmontar con la camara o el decodificador a medias (KT4, RF-KI-02)', () => {
  function mountScanner() {
    const video = ref<HTMLVideoElement | null>(document.createElement('video'))
    return withSetup(() => useQrScanner({ video, onDecoded: vi.fn() }))
  }

  it('desmontar con getUserMedia pendiente: el stream que llega despues se apaga y no se lanza el decodificador', async () => {
    const scanner = mountScanner()

    const starting = scanner.result.start()
    scanner.wrapper.unmount()

    const camera = grantPermission()
    await starting
    await settle()

    expect(camera.stop).toHaveBeenCalled()
    expect(scannerStops).toHaveLength(0)
  })

  it('desmontar con el decodificador arrancando: se detiene el decodificador Y se apaga la camara', async () => {
    let releaseDecoder: () => void = () => undefined
    decodeGate = new Promise<void>((resolve) => {
      releaseDecoder = resolve
    })
    const scanner = mountScanner()

    const starting = scanner.result.start()
    const camera = grantPermission()
    await settle() // camara abierta, decodificador todavia arrancando

    scanner.wrapper.unmount()
    releaseDecoder()
    await starting
    await settle()

    expect(scannerStops).toHaveLength(1)
    expect(scannerStops[0]).toHaveBeenCalled()
    expect(camera.stop).toHaveBeenCalled()
  })

  it('ocultar y volver a mostrar con getUserMedia pendiente deja UNA sola camara viva', async () => {
    const scanner = mountScanner()

    const first = scanner.result.start()
    setVisibility('hidden')
    document.dispatchEvent(new Event('visibilitychange'))
    setVisibility('visible')
    document.dispatchEvent(new Event('visibilitychange'))

    const stale = grantPermission()
    const fresh = grantPermission()
    await first
    await settle()

    expect(stale.stop).toHaveBeenCalled()
    expect(fresh.stop).not.toHaveBeenCalled()

    scanner.wrapper.unmount()
    expect(fresh.stop).toHaveBeenCalled()
  })
})
