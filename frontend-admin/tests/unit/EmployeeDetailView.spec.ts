import type { DOMWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EmployeeDetailView from '@/features/employees/EmployeeDetailView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import type { Employee } from '@/shared/api/types'
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { EMPLOYEE_UUID, SITE, employee, managementUser } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubFetch } from './support/harness'

const DEPARTMENTS = {
  data: [
    { id: 3, site_id: 1, name: 'Recepcion' },
    { id: 4, site_id: 1, name: 'Cocina' },
  ],
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

function buttonWith(wrapper: Wrapper, label: string): DOMWrapper<Element> {
  const found = wrapper.findAll('button').find((button) => button.text().includes(label))

  if (found === undefined) {
    throw new Error(`No hay ningun boton con el texto «${label}»`)
  }

  return found
}

function routes(
  record: Employee,
  extra?: (url: string, init: RequestInit | undefined) => Response | null,
) {
  return (url: string, init: RequestInit | undefined) => {
    if (url.startsWith('/api/v1/sites')) {
      return jsonResponse({ data: [SITE] })
    }

    if (url.startsWith('/api/v1/departments')) {
      return jsonResponse(DEPARTMENTS)
    }

    const handled = extra?.(url, init) ?? null

    return handled ?? jsonResponse(record)
  }
}

async function mountDetail(
  record: Employee,
  extra?: Parameters<typeof routes>[1],
): Promise<Wrapper> {
  stubFetch(routes(record, extra))

  const wrapper = await mountView(EmployeeDetailView, { props: { uuid: EMPLOYEE_UUID } })

  await settle()

  return wrapper
}

beforeEach(() => {
  window.sessionStorage.clear()
  clearAnnouncement()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('EmployeeDetailView', () => {
  it('muestra la ficha sin el documento de identidad, que no se almacena', async () => {
    const wrapper = await mountDetail(employee({ email: null }))

    expect(wrapper.text()).toContain('Youssef Amrani')
    expect(wrapper.text()).toContain('E7QK2MXPR')
    expect(wrapper.text()).toContain(es.employees.fields.emailAbsent)
    expect(wrapper.text()).not.toContain(es.employees.fields.nationalId)
  })

  it('enseña el estado del PIN y explica que significa', async () => {
    const wrapper = await mountDetail(employee({ pin_status: 'issued' }))

    expect(wrapper.text()).toContain(es.pin.status.issued)
    expect(wrapper.text()).toContain(es.pin.statusHint.issued)
  })

  it('antes de restablecer el PIN dice desde que estado y hacia cual', async () => {
    const wrapper = await mountDetail(employee({ pin_status: 'delivered' }))

    await buttonWith(wrapper, es.pin.actions.reset).trigger('click')
    await settle(1)

    const dialog = wrapper.find('[role="dialog"]')

    expect(dialog.text()).toContain(es.pin.status.delivered)
    expect(dialog.text()).toContain(es.pin.status.issued)
    expect(dialog.text()).toContain(es.pin.reset.warning)
  })

  it('restablece el PIN y lo enseña una sola vez', async () => {
    const wrapper = await mountDetail(employee(), (url, init) =>
      url.endsWith('/pin/reset') && init?.method === 'POST'
        ? jsonResponse({
            employee_uuid: EMPLOYEE_UUID,
            pin: '483920',
            issued_at: '2026-08-20T09:14:03.512Z',
            pin_status: 'issued',
          })
        : null,
    )

    await buttonWith(wrapper, es.pin.actions.reset).trigger('click')
    await settle(1)
    await buttonWith(wrapper, es.pin.reset.action).trigger('click')
    await settle()

    expect(wrapper.find('[data-test="pin-value"]').text()).toBe('483920')
  })

  it('solo ofrece registrar la entrega del PIN cuando esta emitido y sin entregar', async () => {
    const issued = await mountDetail(employee({ pin_status: 'issued' }))

    expect(issued.text()).toContain(es.pin.actions.registerDelivery)

    const delivered = await mountDetail(employee({ pin_status: 'delivered' }))

    expect(delivered.text()).not.toContain(es.pin.actions.registerDelivery)
  })

  it('confirma la entrega del PIN diciendo que queda en auditoria', async () => {
    const wrapper = await mountDetail(employee({ pin_status: 'issued' }))

    await buttonWith(wrapper, es.pin.actions.registerDelivery).trigger('click')
    await settle(1)

    const dialog = wrapper.find('[role="dialog"]')

    expect(dialog.text()).toContain(es.pin.delivery.auditNotice)
    expect(dialog.text()).toContain(es.pin.status.delivered)
  })

  it('no deja confirmar una baja sin motivo del catalogo', async () => {
    const wrapper = await mountDetail(employee())

    await buttonWith(wrapper, es.employees.offboard.action).trigger('click')
    await settle(1)

    const confirm = buttonWith(wrapper, es.employees.offboard.confirmAction)

    expect(confirm.attributes('disabled')).toBeDefined()

    await wrapper.find('[role="dialog"] select').setValue('endOfContract')
    await settle(1)

    expect(
      buttonWith(wrapper, es.employees.offboard.confirmAction).attributes('disabled'),
    ).toBeUndefined()
  })

  it('la baja avisa de sus consecuencias y de que no se borra nada', async () => {
    const wrapper = await mountDetail(employee())

    await buttonWith(wrapper, es.employees.offboard.action).trigger('click')
    await settle(1)

    const dialog = wrapper.find('[role="dialog"]')

    expect(dialog.text()).toContain(es.employees.offboard.consequenceCredential)
    expect(dialog.text()).toContain(es.employees.offboard.consequenceScan)
    expect(dialog.text()).toContain(es.employees.offboard.consequenceHistory)
    expect(dialog.text()).toContain(es.employees.status.terminated)
  })

  it('a quien ya esta de baja no le ofrece darle de baja otra vez', async () => {
    const wrapper = await mountDetail(
      employee({ status: 'terminated', terminated_at: '2026-08-31' }),
    )

    expect(wrapper.text()).not.toContain(es.employees.offboard.action)
    expect(wrapper.text()).toContain('2026')
  })

  it('antes de guardar una correccion enseña el valor de partida y el nuevo', async () => {
    const wrapper = await mountDetail(employee())

    await buttonWith(wrapper, es.common.edit).trigger('click')
    await settle(1)

    const inputs = wrapper.findAll('#employee-edit-form input')

    await inputs[0]?.setValue('Yusuf')
    await wrapper.find('#employee-edit-form').trigger('submit')
    await settle(1)

    const dialog = wrapper.find('[role="dialog"]')

    expect(dialog.text()).toContain(es.common.change.from)
    expect(dialog.text()).toContain('Youssef')
    expect(dialog.text()).toContain('Yusuf')
  })

  it('no deja pedir una revision cuando no se ha cambiado nada', async () => {
    const wrapper = await mountDetail(employee())

    await buttonWith(wrapper, es.common.edit).trigger('click')
    await settle(1)

    expect(
      buttonWith(wrapper, es.employees.detail.reviewChanges).attributes('disabled'),
    ).toBeDefined()
  })

  it('ofrece el registro horario a quien puede leerlo', async () => {
    const pinia = createTestPinia()
    const session = useSessionStore()

    session.user = managementUser({ abilities: ['employees:*', 'attendance:read'] })
    session.token = 'un-token'
    session.status = 'authenticated'

    stubFetch(routes(employee()))

    const wrapper = await mountView(EmployeeDetailView, { props: { uuid: EMPLOYEE_UUID }, pinia })

    await settle()

    expect(wrapper.text()).toContain(es.workdays.linkFromEmployee)
    expect(
      wrapper.findAll('a').some((link) => link.attributes('href')?.endsWith('/workdays') === true),
    ).toBe(true)
  })

  it('no ofrece el registro horario a quien no puede leerlo', async () => {
    // Ocultarlo no protege el dato —eso lo hace la policy del servidor, regla
    // dura 18—, pero un enlace que solo lleva a «sin permiso» no ayuda a nadie.
    const pinia = createTestPinia()
    const session = useSessionStore()

    session.user = managementUser({ abilities: ['employees:*'] })
    session.token = 'un-token'
    session.status = 'authenticated'

    stubFetch(routes(employee()))

    const wrapper = await mountView(EmployeeDetailView, { props: { uuid: EMPLOYEE_UUID }, pinia })

    await settle()

    expect(wrapper.text()).not.toContain(es.workdays.linkFromEmployee)
  })

  it('cuenta que ha pasado si la ficha no se puede cargar', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const wrapper = await mountView(EmployeeDetailView, { props: { uuid: EMPLOYEE_UUID } })

    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
  })
})
