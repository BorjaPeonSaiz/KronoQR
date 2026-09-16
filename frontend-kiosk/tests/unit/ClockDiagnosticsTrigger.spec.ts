// Reloj de pared y disparador del diagnostico (RF-KI-08, tarea 3.3, decision 8).
//
// `useLongPress.spec.ts` ya prueba la maquina de tiempos en aislamiento; esto
// prueba el CABLEADO del componente real: que el teclado (Enter/Espacio) hace
// lo mismo que un dedo mantenido 3 s (WCAG 2.1.1 — revisión ui-ux), que la
// repeticion de `keydown` del sistema operativo mientras la tecla sigue
// abajo NO reinicia la cuenta, y que una pulsacion corta (con dedo o con
// teclado) no navega a ningun sitio.

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createRouter, createWebHistory } from 'vue-router'
import ClockDiagnosticsTrigger from '@/features/diagnostics/ui/ClockDiagnosticsTrigger.vue'
import { routes } from '@/router'
import { createAppI18n } from '@/shared/i18n'

async function render() {
  const router = createRouter({ history: createWebHistory(), routes })
  await router.push('/')
  await router.isReady()

  const wrapper = mount(ClockDiagnosticsTrigger, {
    global: { plugins: [createAppI18n('es'), router] },
  })
  return { wrapper, router }
}

afterEach(() => {
  vi.useRealTimers()
})

describe('ClockDiagnosticsTrigger — teclado (RF-KI-08)', () => {
  it('Enter mantenido 3 s abre el diagnostico, igual que el dedo', async () => {
    vi.useFakeTimers()
    const { wrapper, router } = await render()
    const push = vi.spyOn(router, 'push')
    const button = wrapper.get('[data-testid="diagnostics-trigger"]')

    await button.trigger('keydown', { key: 'Enter' })
    vi.advanceTimersByTime(2_999)
    expect(push).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(push).toHaveBeenCalledWith({ name: 'diagnostics' })
  })

  it('la repeticion de `keydown` del sistema operativo no reinicia la cuenta', async () => {
    vi.useFakeTimers()
    const { wrapper, router } = await render()
    const push = vi.spyOn(router, 'push')
    const button = wrapper.get('[data-testid="diagnostics-trigger"]')

    await button.trigger('keydown', { key: 'Enter' })
    // La tecla sigue fisicamente abajo: el sistema operativo repite `keydown`
    // varias veces antes de los 3 s. Si cada repeticion reiniciara la cuenta
    // (como haria un `onPointerDown` real), esto nunca llegaria a disparar.
    for (let elapsed = 0; elapsed < 2_900; elapsed += 100) {
      vi.advanceTimersByTime(100)
      await button.trigger('keydown', { key: 'Enter', repeat: true })
    }
    expect(push).not.toHaveBeenCalled()

    vi.advanceTimersByTime(200)
    expect(push).toHaveBeenCalledWith({ name: 'diagnostics' })
  })

  it('soltar la tecla antes de los 3 s no dispara nada', async () => {
    vi.useFakeTimers()
    const { wrapper, router } = await render()
    const push = vi.spyOn(router, 'push')
    const button = wrapper.get('[data-testid="diagnostics-trigger"]')

    await button.trigger('keydown', { key: 'Enter' })
    vi.advanceTimersByTime(1_000)
    await button.trigger('keyup', { key: 'Enter' })
    vi.advanceTimersByTime(10_000)

    expect(push).not.toHaveBeenCalled()
  })

  it('perder el foco a medio camino cancela la cuenta (equivalente a `pointercancel`)', async () => {
    vi.useFakeTimers()
    const { wrapper, router } = await render()
    const push = vi.spyOn(router, 'push')
    const button = wrapper.get('[data-testid="diagnostics-trigger"]')

    await button.trigger('keydown', { key: 'Enter' })
    vi.advanceTimersByTime(1_000)
    await button.trigger('blur')
    vi.advanceTimersByTime(10_000)

    expect(push).not.toHaveBeenCalled()
  })
})
