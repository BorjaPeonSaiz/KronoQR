import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PinRevealDialog from '@/features/employees/PinRevealDialog.vue'
import es from '@/shared/i18n/locales/es.json'
import type { IssuedPin } from '@/shared/api/types'
import { EMPLOYEE_UUID } from './support/fixtures'
import { jsonResponse, mountView, problemResponse, settle, stubFetch } from './support/harness'

const PIN: IssuedPin = {
  employee_uuid: EMPLOYEE_UUID,
  pin: '483920',
  issued_at: '2026-08-20T09:14:03.512Z',
  pin_status: 'issued',
}

function props() {
  return { pin: PIN, employeeName: 'Youssef Amrani', employeeCode: 'E7QK2MXPR' }
}

beforeEach(() => {
  window.sessionStorage.clear()
  window.localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('PinRevealDialog', () => {
  it('enseña el PIN una vez y avisa de que no se podra volver a consultar', async () => {
    const wrapper = await mountView(PinRevealDialog, { props: props() })

    expect(wrapper.find('[data-test="pin-value"]').text()).toBe('483920')
    expect(wrapper.text()).toContain(es.pin.reveal.onlyOnce)
  })

  it('no escribe el PIN en ningun almacenamiento del navegador', async () => {
    await mountView(PinRevealDialog, { props: props() })

    const stored = [
      ...Object.values(window.sessionStorage),
      ...Object.values(window.localStorage),
    ].join(' ')

    expect(stored).not.toContain('483920')
    expect(window.sessionStorage.length).toBe(0)
    expect(window.localStorage.length).toBe(0)
  })

  it('no se cierra con Escape ni pulsando fuera: solo con una accion explicita', async () => {
    const wrapper = await mountView(PinRevealDialog, { props: props() })

    await wrapper.find('[role="dialog"]').trigger('keydown', { key: 'Escape' })
    await wrapper.find('[aria-hidden="true"]').trigger('click')

    expect(wrapper.emitted('acknowledged')).toBeUndefined()

    await wrapper.findAll('button')[0]?.trigger('click')

    expect(wrapper.emitted('acknowledged')).toHaveLength(1)
  })

  it('no deja registrar la entrega sin confirmar que se ha hecho en mano', async () => {
    const wrapper = await mountView(PinRevealDialog, { props: props() })

    const deliver = wrapper.findAll('button')[1]

    expect(deliver?.attributes('disabled')).toBeDefined()

    await wrapper.find('input[type="checkbox"]').setValue(true)

    expect(wrapper.findAll('button')[1]?.attributes('disabled')).toBeUndefined()
  })

  it('registra la entrega en el endpoint del contrato y avisa al padre', async () => {
    const spy = stubFetch(() =>
      jsonResponse({
        employee_uuid: EMPLOYEE_UUID,
        delivered_at: '2026-08-20T09:20:41.004Z',
        delivered_by: '0199f0aa-1111-7000-8000-0123456789ab',
        pin_status: 'delivered',
      }),
    )

    const wrapper = await mountView(PinRevealDialog, { props: props() })

    await wrapper.find('input[type="checkbox"]').setValue(true)
    await wrapper.findAll('button')[1]?.trigger('click')
    await settle()

    expect(spy.mock.calls[0]?.[0]).toBe(`/api/v1/employees/${EMPLOYEE_UUID}/pin/deliver`)
    expect(wrapper.emitted('delivered')).toHaveLength(1)
  })

  it('explica un conflicto sin cerrar el dialogo, para no perder el PIN de vista', async () => {
    stubFetch(() => problemResponse(409, 'urn:kronoqr:problem:conflict'))

    const wrapper = await mountView(PinRevealDialog, { props: props() })

    await wrapper.find('input[type="checkbox"]').setValue(true)
    await wrapper.findAll('button')[1]?.trigger('click')
    await settle()

    expect(wrapper.emitted('delivered')).toBeUndefined()
    expect(wrapper.text()).toContain(es.errors.conflict.title)
    expect(wrapper.find('[data-test="pin-value"]').text()).toBe('483920')
  })

  it('se anuncia como dialogo modal con su titulo asociado', async () => {
    const wrapper = await mountView(PinRevealDialog, { props: props() })

    const dialog = wrapper.find('[role="dialog"]')

    expect(dialog.attributes('aria-modal')).toBe('true')
    expect(dialog.attributes('aria-labelledby')).toBeDefined()
  })
})
