import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CredentialRowActions from '@/features/credentials/CredentialRowActions.vue'
import EmployeeDetailView from '@/features/employees/EmployeeDetailView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import type { Employee } from '@/shared/api/types'
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import {
  CREDENTIAL_UUID,
  EMPLOYEE_UUID,
  SITE,
  board,
  boardRow,
  credential,
  employee,
  managementUser,
} from './support/fixtures'
import {
  buttonWith,
  createTestPinia,
  jsonResponse,
  mountView,
  settle,
  stubFetch,
} from './support/harness'

const DEPARTMENTS = {
  data: [
    { id: 3, site_id: 1, name: 'Recepcion' },
    { id: 4, site_id: 1, name: 'Cocina' },
  ],
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

/**
 * Salvo que una prueba diga lo contrario, la fila de credencial de la ficha
 * esta «pendiente de entregar»: es el estado que ejercita mas ramas (boton de
 * entrega, dialogo de confirmacion) sin ser el caso especial de `no_credential`.
 */
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

    if (handled !== null) {
      return handled
    }

    if (url.startsWith('/api/v1/credentials/status')) {
      // El doble se comporta como el servidor real: solo devuelve la fila de
      // esta persona cuando la peticion la acota por `employee_uuid` (y por
      // `site_id`, ADR-037). Si el cliente dejara de mandar esos parametros,
      // aqui volveria un tablero vacio y la prueba de mas abajo lo notaria.
      const requestUrl = new URL(url, 'http://localhost')
      const matchesEmployee = requestUrl.searchParams.get('employee_uuid') === record.uuid
      const matchesSite = requestUrl.searchParams.get('site_id') === String(record.site_id)

      return jsonResponse(
        matchesEmployee && matchesSite
          ? board([boardRow({ employee_uuid: record.uuid, status: 'pending_delivery' })])
          : board([]),
      )
    }

    return jsonResponse(record)
  }
}

/** Los parametros de consulta de la ultima peticion al tablero de credenciales. */
function lastCredentialStatusQuery(spy: ReturnType<typeof stubFetch>): URLSearchParams {
  const call = [...spy.mock.calls]
    .reverse()
    .find((call) => String(call[0]).startsWith('/api/v1/credentials/status'))

  if (call === undefined) {
    throw new Error('No se pidio el tablero de credenciales')
  }

  return new URL(String(call[0]), 'http://localhost').searchParams
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

  // --- Tarjeta QR (RF-QR-04, RF-QR-06, RF-QR-08) ------------------------------
  //
  // La misma fila y las mismas acciones que en el tablero de credenciales
  // (`CredentialBoardView`), pero de esta persona sola: no se pide el tablero
  // entero para filtrarlo en cliente.
  //
  // Los botones se buscan DENTRO de `CredentialRowActions`, no en toda la
  // ficha: «Registrar la entrega del PIN» (seccion PIN) y «Registrar la
  // entrega» (seccion Tarjeta QR) comparten texto, y `find()` se quedaria con
  // el primero que aparece en el documento.
  describe('tarjeta QR', () => {
    it('pinta la fila de esta persona con su estado de tarjeta y el boton que toca', async () => {
      const wrapper = await mountDetail(employee())
      const actions = wrapper.findComponent(CredentialRowActions)

      expect(wrapper.text()).toContain(es.credentials.status.pending_delivery)
      expect(wrapper.text()).toContain('Hotel Marina')
      expect(actions.text()).toContain(es.credentials.actions.deliver)
    })

    it('pide la fila acotada a esta persona y a su centro, no el tablero entero (ADR-037)', async () => {
      const spy = stubFetch(routes(employee()))

      await mountView(EmployeeDetailView, { props: { uuid: EMPLOYEE_UUID } })
      await settle()

      const query = lastCredentialStatusQuery(spy)

      expect(query.get('employee_uuid')).toBe(EMPLOYEE_UUID)
      expect(query.get('site_id')).toBe('1')
    })

    it('elige la fila de esta persona por employee_uuid, nunca la primera del tablero', async () => {
      // Si el servidor —o un doble de prueba descuidado— devolviera mas de
      // una fila, tomar `data[0]` a ciegas pintaria la de otra persona. La
      // fila de esta persona va aqui deliberadamente en segundo lugar.
      const wrapper = await mountDetail(employee(), (url) =>
        url.startsWith('/api/v1/credentials/status')
          ? jsonResponse(
              board([
                boardRow({
                  employee_uuid: 'otro-empleado-uuid',
                  full_name: 'Otra Persona',
                  status: 'delivered',
                }),
                boardRow({ employee_uuid: EMPLOYEE_UUID, status: 'pending_print' }),
              ]),
            )
          : null,
      )
      const actions = wrapper.findComponent(CredentialRowActions)

      expect(wrapper.text()).toContain(es.credentials.status.pending_print)
      expect(actions.text()).toContain(es.credentials.actions.print)
      expect(wrapper.text()).not.toContain('Otra Persona')
    })

    it('al confirmar la entrega llama al endpoint de entrega y refresca la fila', async () => {
      const spy = stubFetch(
        routes(employee(), (url, init) =>
          url.endsWith(`/credentials/${CREDENTIAL_UUID}/deliver`) && init?.method === 'POST'
            ? jsonResponse(credential({ delivered_at: '2026-08-28T09:00:00.000000Z' }))
            : null,
        ),
      )

      const wrapper = await mountView(EmployeeDetailView, { props: { uuid: EMPLOYEE_UUID } })

      await settle()

      const actions = wrapper.findComponent(CredentialRowActions)

      await buttonWith(actions, es.credentials.actions.deliver).trigger('click')
      await settle(1)
      await buttonWith(actions, es.credentials.confirm.deliver.action).trigger('click')
      await settle()

      const calledDeliver = spy.mock.calls.some(
        (call) =>
          String(call[0]).endsWith(`/credentials/${CREDENTIAL_UUID}/deliver`) &&
          (call[1] as RequestInit | undefined)?.method === 'POST',
      )

      expect(calledDeliver).toBe(true)
    })

    it('ofrece «Emitir» cuando la persona no tiene ninguna credencial', async () => {
      const wrapper = await mountDetail(employee(), (url) =>
        url.startsWith('/api/v1/credentials/status')
          ? jsonResponse(board([boardRow({ status: 'no_credential', credential: null })]))
          : null,
      )
      const actions = wrapper.findComponent(CredentialRowActions)

      expect(wrapper.text()).toContain(es.credentials.status.no_credential)
      expect(actions.text()).toContain(es.credentials.actions.issue)
      expect(actions.findAll('button')).toHaveLength(1)
    })

    it('cuenta que ha pasado si la tarjeta no se puede cargar', async () => {
      const wrapper = await mountDetail(employee(), (url) => {
        if (url.startsWith('/api/v1/credentials/status')) {
          throw new TypeError('Failed to fetch')
        }

        return null
      })

      const alerts = wrapper.findAll('[role="alert"]')

      expect(alerts.some((alert) => alert.text().includes(es.errors.network.title))).toBe(true)
    })

    it('a quien no esta de alta no le ofrece la tarjeta: se gestiona desde el tablero del centro', async () => {
      // El servidor solo devuelve fila para empleados de alta (activos). Sin
      // esto, la ficha de alguien de baja o suspendido esperaria para
      // siempre una fila que nunca llega: «vuelve a intentarlo en unos
      // minutos» que no termina nunca.
      const spy = stubFetch(routes(employee({ status: 'terminated', terminated_at: '2026-08-01' })))

      const wrapper = await mountView(EmployeeDetailView, { props: { uuid: EMPLOYEE_UUID } })

      await settle()

      expect(wrapper.text()).toContain(es.employees.detail.credentialInactive.title)
      expect(wrapper.text()).toContain(es.employees.detail.credentialInactive.description)
      expect(wrapper.text()).not.toContain(es.employees.detail.credentialEmpty.title)

      // Ni siquiera se pide: no hay fila que esperar, asi que no hay
      // peticion pendiente que deje el panel cargando para siempre.
      const askedCredentialStatus = spy.mock.calls.some((call) =>
        String(call[0]).startsWith('/api/v1/credentials/status'),
      )

      expect(askedCredentialStatus).toBe(false)

      const link = wrapper
        .findAll('a')
        .find((anchor) => anchor.text() === es.employees.detail.credentialInactive.link)

      expect(link).toBeDefined()
      expect(link?.attributes('href')).toContain('/credentials')
    })

    it('a quien esta suspendido tampoco le ofrece la tarjeta desde la ficha', async () => {
      const wrapper = await mountDetail(employee({ status: 'suspended' }))

      expect(wrapper.text()).toContain(es.employees.detail.credentialInactive.title)
    })
  })
})
