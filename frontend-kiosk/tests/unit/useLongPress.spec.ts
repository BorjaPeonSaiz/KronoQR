import { describe, expect, it, vi } from 'vitest'
import { useLongPress } from '@/features/diagnostics/composables/useLongPress'

describe('pulsacion larga (RF-KI-08, tarea 3.3)', () => {
  it('dispara tras mantener pulsado el tiempo completo', () => {
    vi.useFakeTimers()
    const onLongPress = vi.fn()
    const handlers = useLongPress({ onLongPress, durationMs: 3_000 })

    handlers.onPointerDown()
    vi.advanceTimersByTime(2_999)
    expect(onLongPress).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(onLongPress).toHaveBeenCalledTimes(1)
    vi.useRealTimers()
  })

  it('una pulsacion corta no dispara nada', () => {
    vi.useFakeTimers()
    const onLongPress = vi.fn()
    const handlers = useLongPress({ onLongPress, durationMs: 3_000 })

    handlers.onPointerDown()
    vi.advanceTimersByTime(500)
    handlers.onPointerUp()
    vi.advanceTimersByTime(10_000)

    expect(onLongPress).not.toHaveBeenCalled()
    vi.useRealTimers()
  })

  it('pointercancel cancela la cuenta igual que pointerup', () => {
    vi.useFakeTimers()
    const onLongPress = vi.fn()
    const handlers = useLongPress({ onLongPress, durationMs: 3_000 })

    handlers.onPointerDown()
    vi.advanceTimersByTime(1_000)
    handlers.onPointerCancel()
    vi.advanceTimersByTime(10_000)

    expect(onLongPress).not.toHaveBeenCalled()
    vi.useRealTimers()
  })

  it('pointerleave cancela la cuenta: un dedo que se va del boton no dispara mas tarde', () => {
    vi.useFakeTimers()
    const onLongPress = vi.fn()
    const handlers = useLongPress({ onLongPress, durationMs: 3_000 })

    handlers.onPointerDown()
    vi.advanceTimersByTime(1_000)
    handlers.onPointerLeave()
    vi.advanceTimersByTime(10_000)

    expect(onLongPress).not.toHaveBeenCalled()
    vi.useRealTimers()
  })

  it('una pulsacion nueva reinicia la cuenta, no la acumula', () => {
    vi.useFakeTimers()
    const onLongPress = vi.fn()
    const handlers = useLongPress({ onLongPress, durationMs: 3_000 })

    handlers.onPointerDown()
    vi.advanceTimersByTime(2_000)
    handlers.onPointerUp()

    handlers.onPointerDown()
    vi.advanceTimersByTime(2_000)
    expect(onLongPress).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1_000)
    expect(onLongPress).toHaveBeenCalledTimes(1)
    vi.useRealTimers()
  })

  it('usa 3000 ms por defecto', () => {
    vi.useFakeTimers()
    const onLongPress = vi.fn()
    const handlers = useLongPress({ onLongPress })

    handlers.onPointerDown()
    vi.advanceTimersByTime(2_999)
    expect(onLongPress).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1)
    expect(onLongPress).toHaveBeenCalledTimes(1)
    vi.useRealTimers()
  })

  it('admite programacion inyectada, para pruebas sin temporizadores globales', () => {
    const scheduled: Array<{ callback: () => void; ms: number }> = []
    const handlers = useLongPress({
      onLongPress: vi.fn(),
      durationMs: 3_000,
      schedule: (callback, ms) => {
        scheduled.push({ callback, ms })
        return 1 as unknown as ReturnType<typeof setTimeout>
      },
      cancel: vi.fn(),
    })

    handlers.onPointerDown()
    expect(scheduled).toHaveLength(1)
    expect(scheduled[0]?.ms).toBe(3_000)
  })
})
