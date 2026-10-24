// @vitest-environment jsdom
// V21-5-KI-1 / R5-KI-01: el primer escaneo tras abrir el navegador no paga la
// construccion del AudioContext (~520 ms en Chromium de escritorio). Se prepara
// en el arranque diferido de ScanView, no en el primer `play()`.

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { routes } from '@/router'
import { disposeOfflineQueue } from '@/features/offline/useOfflineQueue'
import { resetPinSealingStatus } from '@/features/pin/composables/usePinSealingStatus'
import { createScanSound } from '@/features/scan/composables/useScanSound'
import ScanView from '@/features/scan/ui/ScanView.vue'
import { createAppI18n } from '@/shared/i18n'
import { FIRST_PAINT_FALLBACK_MS } from '@/shared/ui/afterFirstPaint'

function fakeContext(): { context: AudioContext; oscillators: number[] } {
  const oscillators: number[] = []
  const node = (): Record<string, unknown> => ({
    connect: vi.fn(),
    disconnect: vi.fn(),
    start: vi.fn(),
    stop: vi.fn(),
    frequency: { setValueAtTime: vi.fn() },
    gain: { setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() },
  })
  const context = {
    state: 'suspended',
    currentTime: 0,
    destination: {},
    resume: vi.fn(async () => undefined),
    close: vi.fn(async () => undefined),
    createOscillator: () => {
      oscillators.push(1)
      return node()
    },
    createGain: node,
  }
  return { context: context as unknown as AudioContext, oscillators }
}

describe('el AudioContext no se construye en el camino del primer fichaje', () => {
  it('`unlock()` lo crea en reposo y el primer `play()` ya lo encuentra hecho', () => {
    const factory = vi.fn(() => fakeContext().context)
    const sound = createScanSound({ audioContextFactory: factory })

    sound.unlock()
    expect(factory).toHaveBeenCalledTimes(1)

    sound.play('entry')
    sound.play('exit')
    expect(factory).toHaveBeenCalledTimes(1)
  })
})

describe('ScanView prepara el audio en el arranque diferido', () => {
  const constructed = vi.fn()

  beforeEach(() => {
    vi.useFakeTimers()
    localStorage.clear()
    constructed.mockClear()
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('Failed to fetch')
      }),
    )
    // Un constructor real de la clase: cuenta cada `new AudioContext()`.
    vi.stubGlobal('AudioContext', function FakeAudioContext() {
      constructed()
      return fakeContext().context
    })
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

  it('no lo construye al montar, lo construye tras el primer pintado, sin ningun gesto', async () => {
    const wrapper = mount(ScanView, {
      global: {
        plugins: [createAppI18n('es'), createRouter({ history: createMemoryHistory(), routes })],
      },
    })
    expect(constructed).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(FIRST_PAINT_FALLBACK_MS + 1)

    expect(constructed).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('desmontada antes del primer pintado, no se construye nada', async () => {
    const wrapper = mount(ScanView, {
      global: {
        plugins: [createAppI18n('es'), createRouter({ history: createMemoryHistory(), routes })],
      },
    })
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(FIRST_PAINT_FALLBACK_MS * 4)

    expect(constructed).not.toHaveBeenCalled()
  })
})
