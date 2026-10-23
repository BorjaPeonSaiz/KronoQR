// @vitest-environment jsdom
// R2-KI-04 / V21-2-KI-2: un bloqueo que llega DESPUES de desmontar se suelta.
// V21-2-KI-1: el arranque diferido de ScanView pide el bloqueo tras el primer
// pintado (y solo entonces), y no lo pide si se desmonta antes.

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { routes } from '@/router'
import { disposeOfflineQueue } from '@/features/offline/useOfflineQueue'
import { resetPinSealingStatus } from '@/features/pin/composables/usePinSealingStatus'
import { useWakeLock } from '@/features/scan/composables/useWakeLock'
import ScanView from '@/features/scan/ui/ScanView.vue'
import { createAppI18n } from '@/shared/i18n'
import { FIRST_PAINT_FALLBACK_MS } from '@/shared/ui/afterFirstPaint'
import { withSetup } from './support/withSetup'

function fakeSentinel(): {
  released: boolean
  release: ReturnType<typeof vi.fn>
  addEventListener: (type: string, listener: () => void) => void
} {
  return {
    released: false,
    release: vi.fn(async () => undefined),
    addEventListener: () => undefined,
  }
}

function installWakeLock(request: unknown): void {
  Object.defineProperty(navigator, 'wakeLock', { value: { request }, configurable: true })
}

afterEach(() => {
  Reflect.deleteProperty(navigator, 'wakeLock')
})

describe('el bloqueo que llega tras desmontar se libera (R2-KI-04)', () => {
  it('peticion pendiente + desmontar + resolver: se suelta y no queda activo', async () => {
    const sentinel = fakeSentinel()
    let resolveRequest: (value: typeof sentinel) => void = () => undefined
    installWakeLock(
      vi.fn(
        () =>
          new Promise<typeof sentinel>((resolve) => {
            resolveRequest = resolve
          }),
      ),
    )

    const { result, wrapper } = withSetup(() => useWakeLock())
    const pending = result.request()
    wrapper.unmount()
    resolveRequest(sentinel)
    await pending

    expect(sentinel.release).toHaveBeenCalledTimes(1)
    expect(result.active.value).toBe(false)
  })

  it('si el propio release() falla, tampoco revienta', async () => {
    const sentinel = fakeSentinel()
    sentinel.release.mockRejectedValue(new Error('ya liberado'))
    let resolveRequest: (value: typeof sentinel) => void = () => undefined
    installWakeLock(
      vi.fn(
        () =>
          new Promise<typeof sentinel>((resolve) => {
            resolveRequest = resolve
          }),
      ),
    )

    const { result, wrapper } = withSetup(() => useWakeLock())
    const pending = result.request()
    wrapper.unmount()
    resolveRequest(sentinel)

    await expect(pending).resolves.toBeUndefined()
    expect(result.active.value).toBe(false)
  })
})

describe('arranque diferido del bloqueo de pantalla (V21-2-KI-1)', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    localStorage.clear()
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('Failed to fetch')
      }),
    )
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: {
        getUserMedia: vi.fn(async () => {
          throw new DOMException('denegada', 'NotAllowedError')
        }),
      },
    })
  })

  afterEach(async () => {
    await disposeOfflineQueue()
    vi.useRealTimers()
    vi.unstubAllGlobals()
    Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true })
    resetPinSealingStatus()
  })

  it('no se pide en el montaje: solo tras el primer pintado', async () => {
    const request = vi.fn(async () => fakeSentinel())
    installWakeLock(request)

    const wrapper = mount(ScanView, {
      global: {
        plugins: [createAppI18n('es'), createRouter({ history: createMemoryHistory(), routes })],
      },
    })
    expect(request).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(FIRST_PAINT_FALLBACK_MS + 1)

    expect(request).toHaveBeenCalledTimes(1)
    expect(request).toHaveBeenCalledWith('screen')
    wrapper.unmount()
  })

  it('desmontada antes del primer pintado, no se pide nunca', async () => {
    const request = vi.fn(async () => fakeSentinel())
    installWakeLock(request)

    const wrapper = mount(ScanView, {
      global: {
        plugins: [createAppI18n('es'), createRouter({ history: createMemoryHistory(), routes })],
      },
    })
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(FIRST_PAINT_FALLBACK_MS * 4)

    expect(request).not.toHaveBeenCalled()
  })

  it('desmontada con la peticion en vuelo, el bloqueo recibido se suelta', async () => {
    const sentinel = fakeSentinel()
    let resolveRequest: (value: typeof sentinel) => void = () => undefined
    const request = vi.fn(
      () =>
        new Promise<typeof sentinel>((resolve) => {
          resolveRequest = resolve
        }),
    )
    installWakeLock(request)

    const wrapper = mount(ScanView, {
      global: {
        plugins: [createAppI18n('es'), createRouter({ history: createMemoryHistory(), routes })],
      },
    })
    await vi.advanceTimersByTimeAsync(FIRST_PAINT_FALLBACK_MS + 1)
    expect(request).toHaveBeenCalledTimes(1)

    wrapper.unmount()
    resolveRequest(sentinel)
    await vi.advanceTimersByTimeAsync(0)

    expect(sentinel.release).toHaveBeenCalledTimes(1)
  })
})
