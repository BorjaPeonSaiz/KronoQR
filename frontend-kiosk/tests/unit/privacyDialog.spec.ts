// @vitest-environment jsdom
// El dialogo del QR de la politica de privacidad (RF-KI-09, V7-KI-02): se cierra
// solo, cede ante cualquier confirmacion de fichaje y es un dialogo modal de
// verdad (foco, Escape, resto inerte). Sin depender de transiciones.

import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createAppI18n } from '@/shared/i18n'
import PrivacyNoticePanel from '@/shared/ui/PrivacyNoticePanel.vue'
import {
  PRIVACY_DIALOG_IDLE_CLOSE_MS,
  PRIVACY_DIALOG_MAX_OPEN_MS,
  PRIVACY_DIALOG_WARNING_LEAD_MS,
} from '@/shared/ui/privacyDialogTiming'

const NOTICE = {
  controllerName: 'Hotel Marina S.L.',
  policyUrl: 'https://marina.example/privacidad',
}

let wrappers: VueWrapper[] = []

function mountPanel(confirmation: object | null = null): VueWrapper {
  // Dentro de un <main> con un hermano, para comprobar el `inert` del resto.
  const host = document.createElement('main')
  const other = document.createElement('button')
  other.setAttribute('data-testid', 'outside')
  host.appendChild(other)
  const slot = document.createElement('div')
  host.appendChild(slot)
  document.body.appendChild(host)
  const wrapper = mount(PrivacyNoticePanel, {
    props: { notice: NOTICE, confirmation },
    attachTo: slot,
    global: { plugins: [createAppI18n('es')] },
  })
  wrappers.push(wrapper)
  return wrapper
}

async function openDialog(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('[data-testid="privacy-show-qr"]').trigger('click')
  await wrapper.vm.$nextTick()
  await vi.advanceTimersByTimeAsync(0)
}

const dialog = (wrapper: VueWrapper) => wrapper.find('[data-testid="privacy-qr-dialog"]')

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  for (const wrapper of wrappers) wrapper.unmount()
  wrappers = []
  document.body.innerHTML = ''
  vi.useRealTimers()
})

describe('RF-KI-09 / V7-KI-02: cierre automatico del dialogo del QR', () => {
  it('se cierra solo a los 30 s sin tocar nada', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)
    expect(dialog(wrapper).exists()).toBe(true)

    await vi.advanceTimersByTimeAsync(PRIVACY_DIALOG_IDLE_CLOSE_MS - 1)
    expect(dialog(wrapper).exists()).toBe(true)
    await vi.advanceTimersByTimeAsync(1)
    expect(dialog(wrapper).exists()).toBe(false)
  })

  it('cualquier toque o tecla reinicia la cuenta atras de 30 s', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)

    await vi.advanceTimersByTimeAsync(PRIVACY_DIALOG_IDLE_CLOSE_MS - 1_000)
    await dialog(wrapper).trigger('pointerdown')
    await vi.advanceTimersByTimeAsync(PRIVACY_DIALOG_IDLE_CLOSE_MS - 1_000)
    expect(dialog(wrapper).exists()).toBe(true)
    await dialog(wrapper).trigger('keydown', { key: 'a' })
    await vi.advanceTimersByTimeAsync(PRIVACY_DIALOG_IDLE_CLOSE_MS - 1_000)
    expect(dialog(wrapper).exists()).toBe(true)

    await vi.advanceTimersByTimeAsync(1_000)
    expect(dialog(wrapper).exists()).toBe(false)
  })

  it('tope absoluto de 120 s aunque se siga tocando', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)

    for (let elapsed = 0; elapsed < PRIVACY_DIALOG_MAX_OPEN_MS - 10_000; elapsed += 10_000) {
      await vi.advanceTimersByTimeAsync(10_000)
      await dialog(wrapper).trigger('pointerdown')
    }
    expect(dialog(wrapper).exists()).toBe(true)

    await vi.advanceTimersByTimeAsync(10_000)
    expect(dialog(wrapper).exists()).toBe(false)
  })

  it('avisa UNA vez en los ultimos 20 s (WCAG 2.2.1), en un role=status que ya estaba en el DOM', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)
    const status = wrapper.get('[data-testid="privacy-closing-soon"]')
    expect(status.attributes('role')).toBe('status')
    expect(status.text()).toBe('')

    await vi.advanceTimersByTimeAsync(PRIVACY_DIALOG_IDLE_CLOSE_MS - PRIVACY_DIALOG_WARNING_LEAD_MS)
    expect(status.text()).toBe('Se cerrará en breve. Toca para seguir viendo')
    expect(wrapper.findAll('[data-testid="privacy-closing-soon"]')).toHaveLength(1)
  })

  it('tocar retira el aviso y reinicia la cuenta atras', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)

    await vi.advanceTimersByTimeAsync(PRIVACY_DIALOG_IDLE_CLOSE_MS - 2_000)
    await dialog(wrapper).trigger('pointerdown')

    expect(wrapper.get('[data-testid="privacy-closing-soon"]').text()).toBe('')
    await vi.advanceTimersByTimeAsync(5_000)
    expect(dialog(wrapper).exists()).toBe(true)
  })

  it('el aviso del tope absoluto no se retira al tocar', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)

    for (let elapsed = 0; elapsed < PRIVACY_DIALOG_MAX_OPEN_MS - 10_000; elapsed += 10_000) {
      await vi.advanceTimersByTimeAsync(10_000)
      await dialog(wrapper).trigger('pointerdown')
    }
    await vi.advanceTimersByTimeAsync(1)
    await dialog(wrapper).trigger('pointerdown')

    // El tope no se puede ampliar: el texto NO invita a tocar para seguir viendo.
    expect(wrapper.get('[data-testid="privacy-closing-soon"]').text()).toBe('Se cerrará en breve.')
  })

  it('al desmontar no queda ningun temporizador vivo ni el resto inerte', async () => {
    const wrapper = mountPanel()
    const baselineTimers = vi.getTimerCount()
    await openDialog(wrapper)
    expect(document.querySelector('[data-testid="outside"]')?.hasAttribute('inert')).toBe(true)

    wrapper.unmount()
    wrappers = []

    expect(vi.getTimerCount()).toBe(baselineTimers)
    expect(document.querySelector('[data-testid="outside"]')?.hasAttribute('inert')).toBe(false)
  })
})

describe('RF-KI-09 / V7-KI-02: un fichaje manda sobre el aviso', () => {
  it.each([
    'accepted',
    'pending',
    'verifying',
    'rejected',
    'unreadable',
    'unavailable',
    'debounced',
  ])('una confirmacion «%s» cierra el dialogo en el acto', async (kind) => {
    const wrapper = mountPanel()
    const baselineTimers = vi.getTimerCount()
    await openDialog(wrapper)
    expect(dialog(wrapper).exists()).toBe(true)

    await wrapper.setProps({ confirmation: { kind, scanId: 's1' } })

    expect(dialog(wrapper).exists()).toBe(false)
    expect(vi.getTimerCount()).toBe(baselineTimers)
    expect(document.querySelector('[data-testid="outside"]')?.hasAttribute('inert')).toBe(false)
  })

  it('un repintado de la misma confirmacion (pendiente a aceptado) tambien lo cierra', async () => {
    const wrapper = mountPanel({ kind: 'pending', scanId: 's1' })
    await openDialog(wrapper)
    expect(dialog(wrapper).exists()).toBe(true)

    await wrapper.setProps({ confirmation: { kind: 'accepted', scanId: 's1' } })

    expect(dialog(wrapper).exists()).toBe(false)
  })

  it('el cierre por confirmacion no mueve el foco ni anuncia nada', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)
    expect(document.activeElement?.getAttribute('data-testid')).toBe('privacy-qr-close')

    await wrapper.setProps({ confirmation: { kind: 'accepted', scanId: 's1' } })
    await wrapper.vm.$nextTick()

    expect(document.activeElement?.getAttribute('data-testid')).not.toBe('privacy-show-qr')
    expect(wrapper.find('[data-testid="privacy-closing-soon"]').exists()).toBe(false)
  })

  it('quitar la confirmacion (null) no cierra ni abre nada', async () => {
    const wrapper = mountPanel({ kind: 'accepted', scanId: 's1' })
    await openDialog(wrapper)

    await wrapper.setProps({ confirmation: null })

    expect(dialog(wrapper).exists()).toBe(true)
  })
})

describe('RF-KI-09 / V7-KI-02: dialogo modal accesible', () => {
  it('al abrir, el foco entra en el dialogo; role=dialog y aria-modal', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)

    expect(dialog(wrapper).attributes('role')).toBe('dialog')
    expect(dialog(wrapper).attributes('aria-modal')).toBe('true')
    expect(document.activeElement).toBe(wrapper.get('[data-testid="privacy-qr-close"]').element)
  })

  it('el resto de la pagina queda inerte mientras esta abierto', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)

    expect(document.querySelector('[data-testid="outside"]')?.hasAttribute('inert')).toBe(true)
    expect(wrapper.get('[data-testid="privacy-notice"]').element.hasAttribute('inert')).toBe(true)
    expect(dialog(wrapper).element.hasAttribute('inert')).toBe(false)
  })

  it('Escape cierra y devuelve el foco a «Mostrar QR»', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)

    await dialog(wrapper).trigger('keydown', { key: 'Escape' })
    await wrapper.vm.$nextTick()

    expect(dialog(wrapper).exists()).toBe(false)
    expect(document.activeElement).toBe(wrapper.get('[data-testid="privacy-show-qr"]').element)
    expect(document.querySelector('[data-testid="outside"]')?.hasAttribute('inert')).toBe(false)
  })

  it('el cierre por inactividad tambien devuelve el foco a «Mostrar QR»', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)

    await vi.advanceTimersByTimeAsync(PRIVACY_DIALOG_IDLE_CLOSE_MS)
    await wrapper.vm.$nextTick()

    expect(document.activeElement).toBe(wrapper.get('[data-testid="privacy-show-qr"]').element)
  })

  it('el boton «Cerrar» cierra y Tab no deja escapar el foco', async () => {
    const wrapper = mountPanel()
    await openDialog(wrapper)

    await dialog(wrapper).trigger('keydown', { key: 'Tab' })
    expect(document.activeElement).toBe(wrapper.get('[data-testid="privacy-qr-close"]').element)

    await wrapper.get('[data-testid="privacy-qr-close"]').trigger('click')
    expect(dialog(wrapper).exists()).toBe(false)
  })

  it('no marca como inert algo que ya lo estaba, ni lo libera al cerrar', async () => {
    const wrapper = mountPanel()
    const outside = document.querySelector('[data-testid="outside"]') as HTMLElement
    outside.setAttribute('inert', '')
    await openDialog(wrapper)

    await wrapper.get('[data-testid="privacy-qr-close"]').trigger('click')

    expect(outside.hasAttribute('inert')).toBe(true)
  })
})
