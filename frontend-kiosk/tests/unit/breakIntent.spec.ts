// Estado del boton «Pausa» (ADR-024, decision 5 de la tarea 3.5). Logica pura,
// sin Vue: se prueba con `vi.useFakeTimers()`, igual que `useScanSession.ts`.

import { afterEach, describe, expect, it, vi } from 'vitest'
import { createBreakIntentController } from '@/features/scan/application/breakIntent'

describe('controlador del boton «Pausa»', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('empieza desarmado y la intencion por defecto es `auto`', () => {
    const controller = createBreakIntentController()

    expect(controller.isArmed()).toBe(false)
    expect(controller.consumeIntent()).toBe('auto')
  })

  it('un toque lo arma', () => {
    const controller = createBreakIntentController()

    controller.arm()

    expect(controller.isArmed()).toBe(true)
  })

  it('consumeIntent devuelve `break_start` armado, y desarma como efecto', () => {
    const controller = createBreakIntentController()
    controller.arm()

    expect(controller.consumeIntent()).toBe('break_start')
    expect(controller.isArmed()).toBe(false)
    // El siguiente fichaje, sin volver a armar, es `auto`.
    expect(controller.consumeIntent()).toBe('auto')
  })

  it('se desarma solo a los 10 s sin fichar (reloj falso)', () => {
    vi.useFakeTimers()
    const controller = createBreakIntentController()

    controller.arm()
    expect(controller.isArmed()).toBe(true)

    vi.advanceTimersByTime(9_999)
    expect(controller.isArmed()).toBe(true)

    vi.advanceTimersByTime(1)
    expect(controller.isArmed()).toBe(false)
  })

  it('el plazo es configurable, para pruebas mas rapidas', () => {
    vi.useFakeTimers()
    const controller = createBreakIntentController({ armedForMs: 100 })

    controller.arm()
    vi.advanceTimersByTime(100)

    expect(controller.isArmed()).toBe(false)
  })

  it('disarm() cancela el desarme por tiempo: no dispara dos veces', () => {
    vi.useFakeTimers()
    const controller = createBreakIntentController()
    const changes: boolean[] = []
    controller.onChange((armed) => changes.push(armed))

    controller.arm()
    controller.disarm()
    vi.advanceTimersByTime(20_000)

    // Un `true` al armar y un `false` al desarmar a mano; el temporizador
    // cancelado no anade un `false` de mas.
    expect(changes).toEqual([true, false])
  })

  it('armar de nuevo reinicia el plazo de 10 s', () => {
    vi.useFakeTimers()
    const controller = createBreakIntentController()

    controller.arm()
    vi.advanceTimersByTime(8_000)
    controller.arm() // vuelve a tocar el boton: el plazo empieza de cero.
    vi.advanceTimersByTime(8_000)

    expect(controller.isArmed()).toBe(true)
    vi.advanceTimersByTime(2_000)
    expect(controller.isArmed()).toBe(false)
  })

  it('notifica a los oyentes suscritos, cada uno el suyo (varias pantallas)', () => {
    const controller = createBreakIntentController()
    const seenByScan: boolean[] = []
    const seenByPin: boolean[] = []
    controller.onChange((armed) => seenByScan.push(armed))
    controller.onChange((armed) => seenByPin.push(armed))

    controller.arm()

    expect(seenByScan).toEqual([true])
    expect(seenByPin).toEqual([true])
  })

  it('un oyente que se desengancha deja de recibir cambios', () => {
    const controller = createBreakIntentController()
    const seen: boolean[] = []
    const unsubscribe = controller.onChange((armed) => seen.push(armed))

    unsubscribe()
    controller.arm()

    expect(seen).toEqual([])
  })

  it('dispose() limpia el temporizador y los oyentes', () => {
    vi.useFakeTimers()
    const controller = createBreakIntentController()
    const seen: boolean[] = []
    controller.onChange((armed) => seen.push(armed))
    controller.arm()

    controller.dispose()
    vi.advanceTimersByTime(20_000)

    // Sin el `false` del desarme automatico: el temporizador se limpio.
    expect(seen).toEqual([true])
  })
})
