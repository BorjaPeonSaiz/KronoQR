// BaseButton y NoticeWithActions de web-kit (V7-UX-03, V21-7-UX-1) y la cuenta atras real
// (V21-3-UX-2). Los componentes de web-kit se ejercitan desde las SPA que los consumen.
import BaseButton from '@kronoqr/web-kit/components/BaseButton.vue'
import NoticeWithActions from '@kronoqr/web-kit/components/NoticeWithActions.vue'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import PdfBusyNotice from '@/features/reports/PdfBusyNotice.vue'
import es from '@/shared/i18n/locales/es.json'
import { mountView } from './support/harness'

afterEach(() => {
  vi.useRealTimers()
})

describe('BaseButton', () => {
  it('es un boton de tipo button por omision, con la receta primaria y tokens de la marca', () => {
    const wrapper = mount(BaseButton, { slots: { default: 'Guardar' } })
    const button = wrapper.get('button')

    expect(button.attributes('type')).toBe('button')
    expect(button.classes()).toEqual(
      expect.arrayContaining(['bg-kq-primary-strong', 'text-kq-on-primary', 'font-semibold']),
    )
    expect(button.attributes('aria-disabled')).toBeUndefined()
    expect(button.attributes('disabled')).toBeUndefined()
  })

  it('las variantes y tamaños cambian la receta; lg es el objetivo tactil de 48 px', () => {
    const danger = mount(BaseButton, { props: { variant: 'danger', size: 'lg' } }).get('button')

    expect(danger.classes()).toEqual(
      expect.arrayContaining(['bg-kq-danger', 'text-kq-on-danger', 'min-h-12']),
    )
    expect(
      mount(BaseButton, { props: { variant: 'secondary' } })
        .get('button')
        .classes(),
    ).toContain('bg-kq-surface-raised')
  })

  it('en vuelo usa aria-disabled + aria-busy (sin atributo disabled) y bloquea el clic', async () => {
    const onClick = vi.fn()
    const wrapper = mount(BaseButton, {
      props: { busy: true, onClick },
      attachTo: document.body,
    })
    const button = wrapper.get('button')

    expect(button.attributes('aria-disabled')).toBe('true')
    expect(button.attributes('aria-busy')).toBe('true')
    expect(button.attributes('disabled')).toBeUndefined()

    ;(button.element as HTMLButtonElement).focus()
    await button.trigger('click')

    expect(onClick).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(button.element)

    await wrapper.setProps({ busy: false })
    await button.trigger('click')

    expect(onClick).toHaveBeenCalledTimes(1)
    expect(button.attributes('aria-disabled')).toBeUndefined()
    expect(button.attributes('aria-busy')).toBeUndefined()
    wrapper.unmount()
  })

  it('disabled bloquea sin marcar aria-busy', () => {
    const button = mount(BaseButton, { props: { disabled: true } }).get('button')

    expect(button.attributes('aria-disabled')).toBe('true')
    expect(button.attributes('aria-busy')).toBeUndefined()
  })
})

describe('NoticeWithActions', () => {
  it('es un grupo cuyo nombre es el mensaje, sin role=alert, con detalle y acciones', () => {
    const wrapper = mount(NoticeWithActions, {
      props: { message: 'Mucho trafico' },
      slots: { default: '<p>Detalle</p>', actions: '<button>Salir</button>' },
    })
    const root = wrapper.get('[role="group"]')
    const nameId = root.attributes('aria-labelledby') ?? ''

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.get('#' + CSS.escape(nameId)).text()).toBe('Mucho trafico')
    expect(wrapper.text()).toContain('Detalle')
    expect(wrapper.get('button').text()).toBe('Salir')
  })

  it('con focusOnMount recibe el foco; sin el, no', () => {
    const focused = mount(NoticeWithActions, {
      props: { message: 'Aviso', focusOnMount: true },
      attachTo: document.body,
    })
    expect(document.activeElement).toBe(focused.element)
    focused.unmount()

    const quiet = mount(NoticeWithActions, { props: { message: 'Aviso' }, attachTo: document.body })
    expect(document.activeElement).not.toBe(quiet.element)
    quiet.unmount()
  })

  it('sin acciones no pinta el contenedor de acciones', () => {
    const wrapper = mount(NoticeWithActions, { props: { message: 'Aviso' } })

    expect(wrapper.find('.mt-3').exists()).toBe(false)
  })
})

describe('PdfBusyNotice, cuenta atras real (V21-3-UX-2)', () => {
  it('baja cada segundo y al llegar a 0 dice que ya se puede reintentar', async () => {
    vi.useFakeTimers()
    const wrapper = await mountView(PdfBusyNotice, {
      props: { retryAfterSeconds: 3, formats: ['csv'], disabled: false },
    })
    const text = (): string => wrapper.get('[data-test="pdf-busy"]').text()

    expect(text()).toContain(es.reports.pdfBusy.message.replace('{seconds}', '3'))

    await vi.advanceTimersByTimeAsync(1000)
    expect(text()).toContain(es.reports.pdfBusy.message.replace('{seconds}', '2'))

    await vi.advanceTimersByTimeAsync(2000)
    expect(text()).toContain(es.reports.pdfBusy.messageReady)
    expect(text()).not.toContain('segundos')

    wrapper.unmount()
  })

  it('no deja temporizadores vivos al desmontar', async () => {
    vi.useFakeTimers()
    const wrapper = await mountView(PdfBusyNotice, {
      props: { retryAfterSeconds: 30, formats: ['csv'], disabled: false },
    })

    expect(vi.getTimerCount()).toBeGreaterThan(0)
    wrapper.unmount()
    expect(vi.getTimerCount()).toBe(0)
  })
})
