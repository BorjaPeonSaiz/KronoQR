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

describe('BaseDialog: el foco en el propio panel y fuera (V7-PA-1, V21-7-PA-1)', () => {
  /** El panel recibe el foco como al hacer clic en su texto (`tabindex="-1"`). */
  function focusPanel(): HTMLElement {
    const dialog = document.querySelector('[role="dialog"]') as HTMLElement

    dialog.focus()
    expect(document.activeElement).toBe(dialog)

    return dialog
  }

  it('Shift+Tab con el foco en el panel va al ultimo control, no a la pagina de detras', async () => {
    const wrapper = await openDialog()

    focusPanel()
    tab(true)

    expect(document.activeElement?.id).toBe('last')

    wrapper.unmount()
  })

  it('Tab con el foco en el panel va al primer control', async () => {
    const wrapper = await openDialog()

    focusPanel()
    tab()

    expect(document.activeElement?.id).toBe('first')

    wrapper.unmount()
  })

  it('Escape cierra tambien con el foco en el panel', async () => {
    const wrapper = await openDialog()
    const dialog = focusPanel()

    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()

    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement?.id).toBe('opener')

    wrapper.unmount()
  })

  it('si algo manda el foco a la pagina de detras, vuelve al dialogo', async () => {
    const wrapper = await openDialog()
    const opener = document.getElementById('opener') as HTMLElement

    opener.focus()

    expect(document.querySelector('[role="dialog"]')?.contains(document.activeElement)).toBe(true)
    expect(document.activeElement?.id).toBe('first')

    wrapper.unmount()
  })

  it('dejar de montar el dialogo retira la escucha del documento', async () => {
    const wrapper = await openDialog()

    wrapper.unmount()
    const outside = document.createElement('button')

    document.body.append(outside)
    outside.focus()

    expect(document.activeElement).toBe(outside)
  })
})

describe('BaseDialog: controles de fecha y hora (segmentos internos)', () => {
  const DateHost = () =>
    h('div', [
      h('button', { id: 'before', type: 'button' }, 'Antes'),
      h(
        BaseDialog,
        { title: 'Fecha', onClose: () => undefined },
        {
          default: () => h('input', { id: 'when', type: 'datetime-local' }),
          actions: () => h('button', { id: 'cancel', type: 'button' }, 'Cancelar'),
        },
      ),
    ])

  it('Shift+Tab desde un segmento del primer campo NO salta a «Cancelar»: lo mueve el navegador', () => {
    const wrapper = mount(DateHost, { attachTo: document.body })
    const field = document.getElementById('when') as HTMLInputElement

    field.focus()

    const event = new KeyboardEvent('keydown', {
      key: 'Tab',
      shiftKey: true,
      bubbles: true,
      cancelable: true,
    })

    field.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(false)
    expect(document.activeElement?.id).toBe('when')

    wrapper.unmount()
  })

  it('si el navegador saca el foco hacia atras, vuelve por el ultimo control', () => {
    const wrapper = mount(DateHost, { attachTo: document.body })
    const field = document.getElementById('when') as HTMLInputElement

    field.focus()
    field.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }),
    )
    ;(document.getElementById('before') as HTMLElement).focus()

    expect(document.activeElement?.id).toBe('cancel')

    wrapper.unmount()
  })

  it('hacia delante, un foco que se escapa vuelve por el primero', () => {
    const wrapper = mount(DateHost, { attachTo: document.body })
    const field = document.getElementById('when') as HTMLInputElement

    field.focus()
    field.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }),
    )
    ;(document.getElementById('before') as HTMLElement).focus()

    expect(document.activeElement?.id).toBe('when')

    wrapper.unmount()
  })
})
