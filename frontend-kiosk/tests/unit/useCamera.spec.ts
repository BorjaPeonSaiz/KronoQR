import { afterEach, describe, expect, it, vi } from 'vitest'
import { useCamera } from '@/features/scan/composables/useCamera'
import { withSetup } from './support/withSetup'

interface FakeTrack {
  kind: string
  stop: ReturnType<typeof vi.fn>
  applyConstraints: ReturnType<typeof vi.fn>
  getCapabilities: ReturnType<typeof vi.fn>
}

function fakeTrack(capabilities: MediaTrackCapabilities = {}): FakeTrack {
  return {
    kind: 'video',
    stop: vi.fn(),
    applyConstraints: vi.fn(async () => undefined),
    getCapabilities: vi.fn(() => capabilities),
  }
}

function fakeStream(tracks: FakeTrack[]): MediaStream {
  return {
    getTracks: () => tracks,
    getVideoTracks: () => tracks.filter((track) => track.kind === 'video'),
  } as unknown as MediaStream
}

function installMediaDevices(getUserMedia: unknown): void {
  Object.defineProperty(navigator, 'mediaDevices', {
    value: { getUserMedia },
    configurable: true,
  })
}

afterEach(() => {
  Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true })
})

describe('control explicito del MediaStream', () => {
  it('pide 720p y limita la tasa de fotogramas', async () => {
    const getUserMedia = vi.fn<(constraints: MediaStreamConstraints) => Promise<MediaStream>>(
      async () => fakeStream([fakeTrack()]),
    )
    installMediaDevices(getUserMedia)

    const { result, wrapper } = withSetup(() => useCamera())
    await result.start()

    const constraints = getUserMedia.mock.calls[0]?.[0]
    const video = constraints?.video as MediaTrackConstraints
    expect(video.width).toEqual({ ideal: 1280 })
    expect(video.height).toEqual({ ideal: 720 })
    expect(video.frameRate).toEqual({ ideal: 30, max: 30 })
    expect(constraints?.audio).toBe(false)

    wrapper.unmount()
  })

  it('pide enfoque continuo solo si el aparato lo expone', async () => {
    const withFocus = fakeTrack({ focusMode: ['manual', 'continuous'] })
    installMediaDevices(async () => fakeStream([withFocus]))

    const { result, wrapper } = withSetup(() => useCamera())
    await result.start()

    expect(withFocus.applyConstraints).toHaveBeenCalledWith({
      advanced: [{ focusMode: 'continuous' }],
    })
    wrapper.unmount()
  })

  it('no se queda sin camara por no poder enfocar', async () => {
    const stubborn = fakeTrack({ focusMode: ['continuous'] })
    stubborn.applyConstraints.mockRejectedValue(new Error('nope'))
    installMediaDevices(async () => fakeStream([stubborn]))

    const { result, wrapper } = withSetup(() => useCamera())
    await result.start()

    expect(result.state.value).toBe('running')
    wrapper.unmount()
  })

  it('detecta la linterna y la enciende cuando existe', async () => {
    const track = fakeTrack({ torch: true })
    installMediaDevices(async () => fakeStream([track]))

    const { result, wrapper } = withSetup(() => useCamera())
    await result.start()
    expect(result.torchAvailable.value).toBe(true)

    await result.toggleTorch()
    expect(track.applyConstraints).toHaveBeenCalledWith({ advanced: [{ torch: true }] })
    expect(result.torchOn.value).toBe(true)

    wrapper.unmount()
  })

  it('no ofrece linterna en un aparato que no la tiene', async () => {
    installMediaDevices(async () => fakeStream([fakeTrack({})]))

    const { result, wrapper } = withSetup(() => useCamera())
    await result.start()
    await result.toggleTorch()

    expect(result.torchAvailable.value).toBe(false)
    expect(result.torchOn.value).toBe(false)
    wrapper.unmount()
  })

  it('LIBERA todas las pistas al parar: es lo que evita la fuga de las 8 horas', async () => {
    const first = fakeTrack()
    const second = fakeTrack()
    installMediaDevices(async () => fakeStream([first, second]))

    const { result, wrapper } = withSetup(() => useCamera())
    await result.start()
    result.stop()

    expect(first.stop).toHaveBeenCalledTimes(1)
    expect(second.stop).toHaveBeenCalledTimes(1)
    expect(result.state.value).toBe('idle')
    wrapper.unmount()
  })

  it('parar dos veces no falla ni vuelve a parar nada', async () => {
    const track = fakeTrack()
    installMediaDevices(async () => fakeStream([track]))

    const { result, wrapper } = withSetup(() => useCamera())
    await result.start()
    result.stop()
    result.stop()

    expect(track.stop).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('no abre una segunda camara si ya hay una abierta', async () => {
    const getUserMedia = vi.fn(async () => fakeStream([fakeTrack()]))
    installMediaDevices(getUserMedia)

    const { result, wrapper } = withSetup(() => useCamera())
    await result.start()
    await result.start()

    expect(getUserMedia).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('distingue permiso denegado de camara rota, y avisa de cada cosa', async () => {
    const failures: string[] = []
    installMediaDevices(async () => {
      throw new DOMException('denied', 'NotAllowedError')
    })

    const denied = withSetup(() => useCamera({ onFailure: (failure) => failures.push(failure) }))
    await denied.result.start()
    expect(denied.result.state.value).toBe('denied')
    denied.wrapper.unmount()

    installMediaDevices(async () => {
      throw new DOMException('busy', 'NotReadableError')
    })
    const broken = withSetup(() => useCamera({ onFailure: (failure) => failures.push(failure) }))
    await broken.result.start()
    expect(broken.result.state.value).toBe('unavailable')
    broken.wrapper.unmount()

    expect(failures).toEqual(['permission_denied', 'unavailable'])
  })

  it('sobrevive a un navegador sin mediaDevices', async () => {
    Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true })

    const { result, wrapper } = withSetup(() => useCamera())
    expect(await result.start()).toBeNull()
    expect(result.state.value).toBe('unavailable')
    wrapper.unmount()
  })
})
