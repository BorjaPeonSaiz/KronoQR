// @vitest-environment jsdom
// KP1: el arranque prescindible espera al primer pintado y se cancela si la
// pantalla se desmonta antes (nada de camara ni de libsodium huerfanos).

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { disposeOfflineQueue } from '@/features/offline/useOfflineQueue'
import {
  resetPinSealingStatus,
  usePinSealingStatus,
} from '@/features/pin/composables/usePinSealingStatus'
import ScanView from '@/features/scan/ui/ScanView.vue'
import { FIRST_PAINT_FALLBACK_MS } from '@/shared/ui/afterFirstPaint'
import { createAppI18n } from '@/shared/i18n'
import { createTestRouter } from './support/testRouter'

describe('arranque diferido tras el primer pintado', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    localStorage.clear()
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('Failed to fetch')
      }),
    )
  })

  afterEach(async () => {
    await disposeOfflineQueue()
    vi.useRealTimers()
    vi.unstubAllGlobals()
    Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true })
    resetPinSealingStatus()
  })

  it('ScanView desmontado antes del primer pintado no abre la camara', async () => {
    const getUserMedia = vi.fn(async () => {
      throw new DOMException('no deberia llamarse', 'NotAllowedError')
    })
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: { getUserMedia },
    })

    const wrapper = mount(ScanView, {
      global: { plugins: [createAppI18n('es'), createTestRouter()] },
    })
    expect(getUserMedia).not.toHaveBeenCalled()
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(FIRST_PAINT_FALLBACK_MS * 4)

    expect(getUserMedia).not.toHaveBeenCalled()
  })

  it('ScanView abre la camara una vez pasado el primer pintado', async () => {
    const getUserMedia = vi.fn(async () => {
      throw new DOMException('denegada', 'NotAllowedError')
    })
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: { getUserMedia },
    })

    const wrapper = mount(ScanView, {
      global: { plugins: [createAppI18n('es'), createTestRouter()] },
    })
    expect(getUserMedia).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(FIRST_PAINT_FALLBACK_MS + 1)

    expect(getUserMedia).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  function host(warmUp: () => Promise<'ready' | 'unavailable'>) {
    return defineComponent({
      setup() {
        usePinSealingStatus({ deferFirstCheck: true, warmUp, retryMs: 1_000_000 })
        return () => null
      },
    })
  }

  it('deferFirstCheck desmontado antes del pintado no calienta libsodium', async () => {
    const warmUp = vi.fn(async () => 'ready' as const)

    const wrapper = mount(host(warmUp))
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(10_000)

    expect(warmUp).not.toHaveBeenCalled()
  })

  it('deferFirstCheck calienta libsodium despues del pintado, no en el montaje', async () => {
    const warmUp = vi.fn(async () => 'ready' as const)

    const wrapper = mount(host(warmUp))
    expect(warmUp).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(FIRST_PAINT_FALLBACK_MS + 10)

    expect(warmUp).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
})
