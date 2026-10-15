// KP1: lo prescindible espera al primer pintado, pero nunca se pierde.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { afterFirstPaint, FIRST_PAINT_FALLBACK_MS } from '@/shared/ui/afterFirstPaint'

describe('afterFirstPaint', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('no ejecuta la tarea de forma sincrona', () => {
    const task = vi.fn()
    afterFirstPaint(task)
    expect(task).not.toHaveBeenCalled()
  })

  it('la ejecuta una sola vez tras dos fotogramas', () => {
    const callbacks: FrameRequestCallback[] = []
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => callbacks.push(cb))
    const task = vi.fn()
    afterFirstPaint(task)
    callbacks.shift()?.(0)
    callbacks.shift()?.(0)
    vi.advanceTimersByTime(1)
    expect(task).toHaveBeenCalledTimes(1)
    vi.advanceTimersByTime(FIRST_PAINT_FALLBACK_MS * 2)
    expect(task).toHaveBeenCalledTimes(1)
  })

  it('se ejecuta igual si el navegador no pinta (pestana en segundo plano)', () => {
    vi.stubGlobal('requestAnimationFrame', () => 0)
    const task = vi.fn()
    afterFirstPaint(task)
    vi.advanceTimersByTime(FIRST_PAINT_FALLBACK_MS)
    expect(task).toHaveBeenCalledTimes(1)
  })

  it('no se ejecuta si se cancela antes', () => {
    const task = vi.fn()
    const cancel = afterFirstPaint(task)
    cancel()
    vi.advanceTimersByTime(FIRST_PAINT_FALLBACK_MS * 2)
    expect(task).not.toHaveBeenCalled()
  })
})
