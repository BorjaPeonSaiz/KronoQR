import { afterEach, describe, expect, it, vi } from 'vitest'
import { useWakeLock } from '@/features/scan/composables/useWakeLock'
import { withSetup } from './support/withSetup'

function fakeSentinel() {
  const listeners: Array<() => void> = []
  return {
    released: false,
    release: vi.fn(async () => undefined),
    addEventListener: (_type: string, listener: () => void) => listeners.push(listener),
    fireRelease: () => listeners.forEach((listener) => listener()),
  }
}

function installWakeLock(request: unknown): void {
  Object.defineProperty(navigator, 'wakeLock', { value: { request }, configurable: true })
}

function setVisibility(value: DocumentVisibilityState): void {
  Object.defineProperty(document, 'visibilityState', { value, configurable: true })
}

afterEach(() => {
  Reflect.deleteProperty(navigator, 'wakeLock')
  setVisibility('visible')
})

describe('bloqueo de pantalla', () => {
  it('lo pide al arrancar', async () => {
    const request = vi.fn(async () => fakeSentinel())
    installWakeLock(request)

    const { result, wrapper } = withSetup(() => useWakeLock())
    await result.request()

    expect(request).toHaveBeenCalledWith('screen')
    expect(result.active.value).toBe(true)
    wrapper.unmount()
  })

  it('VUELVE A PEDIRLO al recuperar el foco: el sistema lo suelta solo', async () => {
    const request = vi.fn(async () => fakeSentinel())
    installWakeLock(request)

    const { result, wrapper } = withSetup(() => useWakeLock())
    await result.request()

    // El sistema lo suelta al ocultarse la pagina.
    setVisibility('hidden')
    document.dispatchEvent(new Event('visibilitychange'))
    await Promise.resolve()

    setVisibility('visible')
    document.dispatchEvent(new Event('visibilitychange'))
    await vi.waitFor(() => expect(request).toHaveBeenCalledTimes(2))

    wrapper.unmount()
  })

  it('no malgasta intentos con la pagina oculta', async () => {
    const request = vi.fn(async () => fakeSentinel())
    installWakeLock(request)
    setVisibility('hidden')

    const { result, wrapper } = withSetup(() => useWakeLock())
    await result.request()

    expect(request).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('marca inactivo cuando el sistema lo revoca', async () => {
    const sentinel = fakeSentinel()
    installWakeLock(async () => sentinel)

    const { result, wrapper } = withSetup(() => useWakeLock())
    await result.request()
    sentinel.fireRelease()

    expect(result.active.value).toBe(false)
    wrapper.unmount()
  })

  it('un rechazo NO impide fichar, solo se anota', async () => {
    const denials: string[] = []
    installWakeLock(async () => {
      throw new DOMException('nope', 'NotAllowedError')
    })

    const { result, wrapper } = withSetup(() =>
      useWakeLock({ onDenied: (context) => denials.push(String(context['error_type'])) }),
    )
    await result.request()

    expect(result.active.value).toBe(false)
    expect(denials).toEqual(['NotAllowedError'])
    wrapper.unmount()
  })

  it('en un navegador sin la API no hace nada y no revienta', async () => {
    const { result, wrapper } = withSetup(() => useWakeLock())

    expect(result.supported).toBe(false)
    await result.request()
    expect(result.active.value).toBe(false)
    wrapper.unmount()
  })

  it('lo suelta al desmontar', async () => {
    const sentinel = fakeSentinel()
    installWakeLock(async () => sentinel)

    const { result, wrapper } = withSetup(() => useWakeLock())
    await result.request()
    wrapper.unmount()
    await Promise.resolve()

    expect(sentinel.release).toHaveBeenCalled()
  })
})
