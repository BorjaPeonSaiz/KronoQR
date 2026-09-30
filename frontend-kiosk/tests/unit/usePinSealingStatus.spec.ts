// PIN-03: el estado del sellado que decide si se ofrece el boton del PIN.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  resetPinSealingStatus,
  usePinSealingStatus,
} from '@/features/pin/composables/usePinSealingStatus'
import type { SealingStatus } from '@/features/pin/infrastructure/pinSealing'
import { withSetup } from './support/withSetup'

beforeEach(() => {
  vi.useFakeTimers()
  resetPinSealingStatus()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('estado del sellado del PIN (PIN-03)', () => {
  it('empieza en `checking` y pasa a `ready` sin reportar nada', async () => {
    const onUnavailable = vi.fn()
    const { result, wrapper } = withSetup(() =>
      usePinSealingStatus({ warmUp: async () => 'ready', onUnavailable }),
    )
    expect(result.status.value).toBe('checking')

    await vi.advanceTimersByTimeAsync(0)

    expect(result.status.value).toBe('ready')
    expect(onUnavailable).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('pasa a `unavailable`, lo reporta UNA vez y reintenta en vez de quedarse para siempre', async () => {
    const answers: SealingStatus[] = ['unavailable', 'unavailable', 'ready']
    const warmUp = vi.fn(async () => answers.shift() ?? 'ready')
    const onUnavailable = vi.fn()
    const { result, wrapper } = withSetup(() =>
      usePinSealingStatus({ warmUp, onUnavailable, retryMs: 1_000 }),
    )

    await vi.advanceTimersByTimeAsync(0)
    expect(result.status.value).toBe('unavailable')

    await vi.advanceTimersByTimeAsync(1_000)
    expect(result.status.value).toBe('unavailable')
    // Dos fallos seguidos son UN episodio: un reporte, no uno por reintento.
    expect(onUnavailable).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(1_000)
    expect(result.status.value).toBe('ready')
    expect(warmUp).toHaveBeenCalledTimes(3)
    wrapper.unmount()
  })

  it('al desmontar deja de reintentar (ni temporizadores colgados en un turno de 8 h)', async () => {
    const warmUp = vi.fn(async (): Promise<SealingStatus> => 'unavailable')
    const { wrapper } = withSetup(() => usePinSealingStatus({ warmUp, retryMs: 1_000 }))

    await vi.advanceTimersByTimeAsync(0)
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(10_000)

    expect(warmUp).toHaveBeenCalledTimes(1)
  })
})
