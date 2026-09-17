// Enchufe de Vue sobre `application/breakIntent.ts`. La logica de armar,
// desarmar por uso y desarmar por tiempo ya esta probada en
// `breakIntent.spec.ts` sobre el controlador puro; aqui solo lo que anade
// Vue: reactividad, varias pantallas suscritas al MISMO estado mientras estan
// montadas a la vez, y el DESARME AL DESMONTAR -cambio de pantalla, revision
// de la segunda vuelta: la intencion es de la tablet, no de la persona, y no
// sobrevive a la navegacion-.

import { afterEach, describe, expect, it, vi } from 'vitest'
import { resetBreakIntentController } from '@/features/scan/application/breakIntent'
import { useBreakIntent } from '@/features/scan/composables/useBreakIntent'
import { withSetup } from './support/withSetup'

afterEach(() => {
  resetBreakIntentController()
  vi.useRealTimers()
})

describe('useBreakIntent', () => {
  it('empieza desarmado y reacciona al armar', () => {
    const { result, wrapper } = withSetup(() => useBreakIntent())

    expect(result.armed.value).toBe(false)
    result.arm()
    expect(result.armed.value).toBe(true)

    wrapper.unmount()
  })

  it('consumeIntent() encola pausa y desarma la pantalla', () => {
    const { result, wrapper } = withSetup(() => useBreakIntent())

    result.arm()
    expect(result.consumeIntent()).toBe('break_start')
    expect(result.armed.value).toBe(false)

    wrapper.unmount()
  })

  it('se desarma solo a los 10 s (reloj falso)', () => {
    vi.useFakeTimers()
    const { result, wrapper } = withSetup(() => useBreakIntent())

    result.arm()
    vi.advanceTimersByTime(10_000)

    expect(result.armed.value).toBe(false)
    wrapper.unmount()
  })

  it('dos pantallas comparten el MISMO armado: es una tablet, un boton', () => {
    const scan = withSetup(() => useBreakIntent())
    const pin = withSetup(() => useBreakIntent())

    scan.result.arm()

    expect(pin.result.armed.value).toBe(true)

    scan.wrapper.unmount()
    pin.wrapper.unmount()
  })

  it('se desarma al desmontar la pantalla que arma (cambio de pantalla, revision de seguridad)', () => {
    const { result, wrapper } = withSetup(() => useBreakIntent())

    result.arm()
    expect(result.armed.value).toBe(true)

    wrapper.unmount()

    // La instancia desmontada ya no escucha (se desengancho ANTES de
    // desarmar: la pantalla que se va no tiene nada que anunciar), pero el
    // controlador que comparte con la SIGUIENTE pantalla si quedo desarmado:
    // es lo que de verdad importa para la seguridad (ADR-024).
    const next = withSetup(() => useBreakIntent())
    expect(next.result.armed.value).toBe(false)
    next.wrapper.unmount()
  })

  it('desmontar una segunda pantalla suscrita TAMBIEN desarma: no sobrevive a ningun cambio', () => {
    const scan = withSetup(() => useBreakIntent())
    const pin = withSetup(() => useBreakIntent())

    scan.result.arm()
    expect(pin.result.armed.value).toBe(true)

    // Se navega de `ScanView` a `PinView`: la primera se desmonta.
    scan.wrapper.unmount()

    expect(pin.result.armed.value).toBe(false)
    pin.wrapper.unmount()
  })

  it('desmontar SIN haber armado nada no revienta (idempotente)', () => {
    const { wrapper } = withSetup(() => useBreakIntent())
    expect(() => wrapper.unmount()).not.toThrow()
  })
})
