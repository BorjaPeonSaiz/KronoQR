// Dialogo modal accesible (PA7-002 · WCAG 2.1.2, 2.4.3, 4.1.2).
//
// El foco atrapado, el ciclo de Tab y la devolucion del foco estaban correctos
// pero sin ninguna prueba: un cambio inocente en `BaseDialog` los habria roto sin
// que nada fallara. Se monta pegado al documento porque `focus()` y
// `document.activeElement` solo funcionan sobre nodos conectados.
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import BaseDialog from '@/shared/ui/BaseDialog.vue'

afterEach(() => {
  document.body.innerHTML = ''
})

const Host = defineComponent({
  setup() {
    const open = ref(false)

    return () =>
      h('div', [
        h('button', { id: 'opener', type: 'button', onClick: () => (open.value = true) }, 'Abrir'),
        open.value
          ? h(
              BaseDialog,
              { title: 'Titulo', onClose: () => (open.value = false) },
              {
                default: () => [
                  h('input', { id: 'first', type: 'text' }),
                  h('input', { id: 'second', type: 'text' }),
                ],
                actions: () => h('button', { id: 'last', type: 'button' }, 'Aceptar'),
              },
            )
          : null,
      ])
  },
})

async function openDialog(): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(Host, { attachTo: document.body })
  const opener = document.getElementById('opener') as HTMLButtonElement

  opener.focus()
  opener.click()
  await nextTick()

  return wrapper
}

function tab(shiftKey = false): void {
  const dialog = document.querySelector('[role="dialog"]') as HTMLElement

  dialog.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Tab', shiftKey, bubbles: true, cancelable: true }),
  )
}

describe('BaseDialog: foco (PA7-002)', () => {
  it('es un dialogo modal con nombre y manda el foco a su primer control', async () => {
    const wrapper = await openDialog()
    const dialog = document.querySelector('[role="dialog"]') as HTMLElement

    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(document.getElementById(dialog.getAttribute('aria-labelledby') ?? '')?.textContent).toBe(
      'Titulo',
    )
    expect(document.activeElement?.id).toBe('first')

    wrapper.unmount()
  })

  it('Tab desde el ultimo control vuelve al primero y Shift+Tab desde el primero va al ultimo', async () => {
    const wrapper = await openDialog()

    ;(document.getElementById('last') as HTMLElement).focus()
    tab()
    expect(document.activeElement?.id).toBe('first')

    tab(true)
    expect(document.activeElement?.id).toBe('last')

    wrapper.unmount()
  })

  it('no deja salir el foco del dialogo en un ciclo completo de Tab', async () => {
    const wrapper = await openDialog()
    const seen: string[] = []

    for (let step = 0; step < 6; step += 1) {
      seen.push(document.activeElement?.id ?? '')
      const order = ['first', 'second', 'last']
      const next = order[(order.indexOf(document.activeElement?.id ?? '') + 1) % order.length]

      // El navegador mueve el foco entre controles; el dialogo solo interviene
      // en los extremos. Se simula el movimiento natural y se deja actuar al dialogo.
      if (document.activeElement?.id === 'last') {
        tab()
      } else {
        ;(document.getElementById(next ?? 'first') as HTMLElement).focus()
      }
    }

    expect(seen.every((id) => ['first', 'second', 'last'].includes(id))).toBe(true)

    wrapper.unmount()
  })

  it('al cerrarse con Escape devuelve el foco al boton que lo abrio', async () => {
    const wrapper = await openDialog()
    const dialog = document.querySelector('[role="dialog"]') as HTMLElement

    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()

    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement?.id).toBe('opener')

    wrapper.unmount()
  })
})
