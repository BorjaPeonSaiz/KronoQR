import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useQrScanner } from '@/features/scan/composables/useQrScanner'
import { withSetup } from './support/withSetup'

type DecodeCallback = (result: { getText: () => string } | undefined, error?: unknown) => void

const decodeCallbacks: DecodeCallback[] = []
const scannerStops: Array<ReturnType<typeof vi.fn>> = []
const readerConstructions: Array<Record<string, unknown> | undefined> = []
let decodeFromStreamRejects = false

vi.mock('@zxing/browser', () => ({
  BrowserQRCodeReader: class {
    constructor(_hints: unknown, options?: Record<string, unknown>) {
      readerConstructions.push(options)
    }

    async decodeFromStream(
      _stream: MediaStream,
      _preview: HTMLVideoElement,
      callback: DecodeCallback,
    ) {
      if (decodeFromStreamRejects) throw new DOMException('nope', 'NotReadableError')
      decodeCallbacks.push(callback)
      const stop = vi.fn()
      scannerStops.push(stop)
      return { stop }
    }
  },
}))

const trackStops: Array<ReturnType<typeof vi.fn>> = []

function installCamera(): void {
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: {
      getUserMedia: vi.fn(async () => {
        const stop = vi.fn()
        trackStops.push(stop)
        const track = {
          kind: 'video',
          stop,
          applyConstraints: vi.fn(async () => undefined),
          getCapabilities: vi.fn(() => ({})),
        }
        return {
          getTracks: () => [track],
          getVideoTracks: () => [track],
        } as unknown as MediaStream
      }),
    },
  })
}

function setVisibility(value: DocumentVisibilityState): void {
  Object.defineProperty(document, 'visibilityState', { value, configurable: true })
}

beforeEach(() => {
  decodeCallbacks.length = 0
  scannerStops.length = 0
  trackStops.length = 0
  readerConstructions.length = 0
  decodeFromStreamRejects = false
  installCamera()
  setVisibility('visible')
})

afterEach(() => {
  Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true })
})

function mountScanner(onDecoded = vi.fn(), onDiagnostic = vi.fn()) {
  const video = ref<HTMLVideoElement | null>(document.createElement('video'))
  return {
    onDecoded,
    onDiagnostic,
    ...withSetup(() => useQrScanner({ video, onDecoded, onDiagnostic })),
  }
}

describe('bucle de decodificacion continuo', () => {
  it('arranca sin que nadie toque nada (RF-KI-02)', async () => {
    const scanner = mountScanner()
    await scanner.result.start()

    expect(scanner.result.state.value).toBe('scanning')
    scanner.wrapper.unmount()
  })

  it('limita el ritmo de intentos: 8 horas de bucle no pueden ir a toda maquina', async () => {
    const scanner = mountScanner()
    await scanner.result.start()

    expect(readerConstructions[0]).toMatchObject({
      delayBetweenScanAttempts: 100,
      delayBetweenScanSuccess: 800,
    })
    scanner.wrapper.unmount()
  })

  it('entrega el texto decodificado', async () => {
    const scanner = mountScanner()
    await scanner.result.start()

    decodeCallbacks[0]?.({ getText: () => 'FH1.a3.token.sig' })

    expect(scanner.onDecoded).toHaveBeenCalledWith('FH1.a3.token.sig')
    scanner.wrapper.unmount()
  })

  it('NO reporta los fotogramas sin codigo: son el caso normal diez veces por segundo', async () => {
    const scanner = mountScanner()
    await scanner.result.start()

    for (let index = 0; index < 100; index += 1) {
      decodeCallbacks[0]?.(undefined, new Error('NotFoundException'))
    }

    expect(scanner.onDecoded).not.toHaveBeenCalled()
    expect(scanner.onDiagnostic).not.toHaveBeenCalled()
    scanner.wrapper.unmount()
  })

  it('LIBERA la camara al desmontar: es la fuga que tumba la tablet a media tarde', async () => {
    const scanner = mountScanner()
    await scanner.result.start()

    scanner.wrapper.unmount()

    expect(scannerStops[0]).toHaveBeenCalled()
    expect(trackStops[0]).toHaveBeenCalled()
  })

  it('LIBERA la camara cuando la pagina deja de verse', async () => {
    const scanner = mountScanner()
    await scanner.result.start()

    setVisibility('hidden')
    document.dispatchEvent(new Event('visibilitychange'))

    expect(trackStops[0]).toHaveBeenCalled()
    expect(scanner.result.state.value).toBe('idle')
    scanner.wrapper.unmount()
  })

  it('la recupera al volver a verse, sin intervencion de nadie', async () => {
    const scanner = mountScanner()
    await scanner.result.start()

    setVisibility('hidden')
    document.dispatchEvent(new Event('visibilitychange'))
    setVisibility('visible')
    document.dispatchEvent(new Event('visibilitychange'))

    await vi.waitFor(() => expect(scanner.result.state.value).toBe('scanning'))
    expect(trackStops).toHaveLength(2)
    scanner.wrapper.unmount()
  })

  it('parar dos veces no deja nada a medias', async () => {
    const scanner = mountScanner()
    await scanner.result.start()

    scanner.result.stop()
    scanner.result.stop()

    expect(scannerStops[0]).toHaveBeenCalledTimes(1)
    scanner.wrapper.unmount()
  })

  it('no abre dos bucles si se arranca dos veces', async () => {
    const scanner = mountScanner()
    await scanner.result.start()
    await scanner.result.start()

    expect(decodeCallbacks).toHaveLength(1)
    scanner.wrapper.unmount()
  })

  it('rearranca solo si el bucle deja de latir: un quiosco mudo pierde una jornada', async () => {
    vi.useFakeTimers()
    const scanner = mountScanner()
    await vi.advanceTimersByTimeAsync(0)
    await scanner.result.start()

    // Trece segundos sin un solo intento de decodificacion.
    await vi.advanceTimersByTimeAsync(13_000)

    expect(scanner.onDiagnostic).toHaveBeenCalledWith(
      'scanner.watchdog_restart',
      expect.objectContaining({ silence_ms: expect.any(Number) }),
    )
    await vi.waitFor(() => expect(decodeCallbacks.length).toBe(2))

    scanner.wrapper.unmount()
    vi.useRealTimers()
  })

  it('no rearranca mientras el bucle sigue latiendo', async () => {
    vi.useFakeTimers()
    const scanner = mountScanner()
    await vi.advanceTimersByTimeAsync(0)
    await scanner.result.start()

    for (let index = 0; index < 20; index += 1) {
      decodeCallbacks[0]?.(undefined, new Error('NotFoundException'))
      await vi.advanceTimersByTimeAsync(1_000)
    }

    expect(scanner.onDiagnostic).not.toHaveBeenCalled()
    scanner.wrapper.unmount()
    vi.useRealTimers()
  })

  it('avisa si la camara no se puede abrir, y no se queda escaneando en falso', async () => {
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: {
        getUserMedia: vi.fn(async () => {
          throw new DOMException('denied', 'NotAllowedError')
        }),
      },
    })

    const scanner = mountScanner()
    await scanner.result.start()

    expect(scanner.result.state.value).toBe('denied')
    expect(scanner.onDiagnostic).toHaveBeenCalledWith(
      'camera.permission_denied',
      expect.objectContaining({ error_type: 'NotAllowedError' }),
    )
    scanner.wrapper.unmount()
  })

  it('suelta la camara si el decodificador no arranca', async () => {
    decodeFromStreamRejects = true
    const scanner = mountScanner()
    await scanner.result.start()

    expect(scanner.result.state.value).toBe('unavailable')
    expect(trackStops[0]).toHaveBeenCalled()
    expect(scanner.onDiagnostic).toHaveBeenCalledWith(
      'scanner.start_failed',
      expect.objectContaining({ error_type: 'NotReadableError' }),
    )
    scanner.wrapper.unmount()
  })

  it('avisa si no hay elemento de video donde pintar', async () => {
    const onDiagnostic = vi.fn()
    const video = ref<HTMLVideoElement | null>(null)
    const { result, wrapper } = withSetup(() =>
      useQrScanner({ video, onDecoded: vi.fn(), onDiagnostic }),
    )

    await result.start()

    expect(onDiagnostic).toHaveBeenCalledWith('scanner.start_failed', {
      reason: 'no_video_element',
    })
    wrapper.unmount()
  })
})
