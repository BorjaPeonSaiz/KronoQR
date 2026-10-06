import type { DOMWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CredentialRowActions from '@/features/credentials/CredentialRowActions.vue'
import EmployeeDetailView from '@/features/employees/EmployeeDetailView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import type { Employee } from '@/shared/api/types'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
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
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

const DEPARTMENTS = {
  data: [
    { id: 3, name: 'Recepcion' },
    { id: 4, name: 'Cocina' },
  ],
}

const TERMINATED_TYPE = 'urn:kronoqr:problem:employee-terminated'

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
    if (url.startsWith('/api/v1/site')) {
      return jsonResponse(SITE)
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
      // esta persona cuando la peticion la acota por `employee_uuid` (ADR-037).
      // Si el cliente dejara de mandar ese parametro, aqui volveria un tablero
      // vacio y la prueba de mas abajo lo notaria.
      const requestUrl = new URL(url, 'http://localhost')
      const matchesEmployee = requestUrl.searchParams.get('employee_uuid') === record.uuid

      return jsonResponse(
        matchesEmployee
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
    const wrapper = await mountDetail(employee({ pin_status: 'issued' }), (url, init) =>
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
    expect(announcement.value).toBe(es.pin.announce.reset)
  })

  it('sin PIN rotula la accion como emision y no como restablecimiento', async () => {
    const pending = await mountDetail(employee({ pin_status: 'pending' }), (url, init) =>
      url.endsWith('/pin/reset') && init?.method === 'POST'
        ? jsonResponse({
            employee_uuid: EMPLOYEE_UUID,
            pin: '483920',
            issued_at: '2026-08-20T09:14:03.512Z',
            pin_status: 'issued',
          })
        : null,
    )

    expect(pending.text()).toContain(es.pin.statusHint.pending)
    expect(pending.text()).toContain(es.pin.actions.issue)
    expect(pending.text()).not.toContain(es.pin.actions.reset)

    await buttonWith(pending, es.pin.actions.issue).trigger('click')
    await settle()

    const dialog = pending.find('[role="dialog"]')

    expect(dialog.text()).toContain(es.pin.issue.explanation)
    expect(dialog.text()).toContain(es.pin.issue.warning)
    expect(dialog.text()).not.toContain(es.pin.reset.explanation)
    expect(buttonWith(pending, es.pin.issue.action).exists()).toBe(true)

    await buttonWith(pending, es.pin.issue.action).trigger('click')
    await settle()

    expect(announcement.value).toBe(es.pin.announce.issued)

    const issued = await mountDetail(employee({ pin_status: 'issued' }))

    expect(issued.text()).toContain(es.pin.actions.reset)
    expect(issued.text()).not.toContain(es.pin.actions.issue)
  })

  it('si el PIN se emitio mientras tanto, el dialogo usa los textos de restablecimiento', async () => {
    let employeeReads = 0

    const wrapper = await mountDetail(employee({ pin_status: 'pending' }), (url, init) => {
      if (url === `/api/v1/employees/${EMPLOYEE_UUID}` && (init?.method ?? 'GET') === 'GET') {
        employeeReads += 1

        return employeeReads > 1 ? jsonResponse(employee({ pin_status: 'issued' })) : null
      }

      return null
    })

    await buttonWith(wrapper, es.pin.actions.issue).trigger('click')
    await settle()

    const dialog = wrapper.find('[role="dialog"]')

    expect(dialog.text()).toContain(es.pin.reset.explanation)
    expect(dialog.text()).toContain(es.pin.reset.warning)
    expect(dialog.text()).not.toContain(es.pin.issue.explanation)
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
      expect(actions.text()).toContain(es.credentials.actions.deliver)
    })

    it('pide la fila acotada a esta persona y a su centro, no el tablero entero (ADR-037)', async () => {
      const spy = stubFetch(routes(employee()))

      await mountView(EmployeeDetailView, { props: { uuid: EMPLOYEE_UUID } })
      await settle()

      const query = lastCredentialStatusQuery(spy)

      expect(query.get('employee_uuid')).toBe(EMPLOYEE_UUID)
      // ADR-040: no hay centro que elegir, y el servidor rechazaria el parametro.
      expect(query.has('site_id')).toBe(false)
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

  it('enseña el teletrabajo en la ficha con texto (RF-GP-01)', async () => {
    const wrapper = await mountDetail(employee({ teleworking: true }))

    expect(wrapper.find('[data-test="teleworking-badge"]').text()).toBe(
      es.employees.teleworking.yes,
    )
  })

  it('al editar solo el teletrabajo, la vista previa lo enseña y el PATCH lleva solo ese campo (RF-GP-01)', async () => {
    let patchBody: unknown = null
    const wrapper = await mountDetail(employee({ teleworking: false }), (_url, init) => {
      if (init?.method === 'PATCH') {
        patchBody = JSON.parse(String(init.body))

        return jsonResponse(employee({ teleworking: true }))
      }

      return null
    })

    await buttonWith(wrapper, es.common.edit).trigger('click')
    await settle(1)

    expect(
      buttonWith(wrapper, es.employees.detail.reviewChanges).attributes('disabled'),
    ).toBeDefined()

    await wrapper.find('[data-test="teleworking-checkbox"]').setValue(true)
    await wrapper.find('#employee-edit-form').trigger('submit')
    await settle(1)

    const dialog = wrapper.find('[role="dialog"]')

    expect(dialog.text()).toContain(es.employees.fields.teleworking)
    expect(dialog.text()).toContain(es.employees.teleworking.no)
    expect(dialog.text()).toContain(es.employees.teleworking.yes)

    await buttonWith(wrapper, es.employees.detail.confirmAction).trigger('click')
    await settle(1)

    expect(patchBody).toEqual({ teleworking: true })
  })
  // --- Baja: fecha de cese (RF-GP-03, RN-14) ----------------------------------
  describe('baja con fecha de cese', () => {
    afterEach(() => {
      vi.useRealTimers()
    })

    function dateInput(wrapper: Wrapper): DOMWrapper<HTMLInputElement> {
      return wrapper.find<HTMLInputElement>('[role="dialog"] input[type="date"]')
    }

    it('el max del campo es hoy en la zona del centro, no la fecha UTC ni la del navegador', async () => {
      // 22:30 UTC del 2 de octubre: en Madrid (CEST) ya es el 3 a las 00:30.
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-02T22:30:00Z') })

      const wrapper = await mountDetail(employee())

      await buttonWith(wrapper, es.employees.offboard.action).trigger('click')
      await settle(1)

      expect(dateInput(wrapper).attributes('max')).toBe('2026-10-03')
      expect(dateInput(wrapper).element.value).toBe('2026-10-03')
    })

    it('no deja confirmar una fecha posterior a hoy y lo dice en el campo', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-02T10:00:00Z') })

      const wrapper = await mountDetail(employee())

      await buttonWith(wrapper, es.employees.offboard.action).trigger('click')
      await settle(1)
      await wrapper.find('[role="dialog"] select').setValue('endOfContract')
      await dateInput(wrapper).setValue('2026-10-31')
      await settle(1)

      expect(
        buttonWith(wrapper, es.employees.offboard.confirmAction).attributes('disabled'),
      ).toBeDefined()
      expect(dateInput(wrapper).attributes('aria-invalid')).toBe('true')
    })

    it('el texto de la baja dice que es efectiva al confirmarla, también hoy', async () => {
      const wrapper = await mountDetail(employee())

      await buttonWith(wrapper, es.employees.offboard.action).trigger('click')
      await settle(1)

      const text = wrapper.find('[role="dialog"]').text()

      expect(text).toContain('efectiva al confirmarla')
      expect(text).not.toContain('A partir de la fecha de cese')
    })

    it('una alta futura fija la fecha de cese a la del alta y lo explica', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-02T10:00:00Z') })

      const wrapper = await mountDetail(employee({ hired_at: '2026-10-15' }))

      await buttonWith(wrapper, es.employees.offboard.action).trigger('click')
      await settle(1)

      const input = dateInput(wrapper)

      expect(input.attributes('min')).toBe('2026-10-15')
      expect(input.attributes('max')).toBe('2026-10-15')
      expect(input.element.value).toBe('2026-10-15')
      expect(wrapper.find('[data-test="offboard-future-hire"]').text()).toBe(
        es.employees.offboard.futureHire,
      )

      await wrapper.find('[role="dialog"] select').setValue('endOfContract')
      await input.setValue('2026-10-10')
      await settle(1)

      expect(
        buttonWith(wrapper, es.employees.offboard.confirmAction).attributes('disabled'),
      ).toBeDefined()
    })

    it('avisa del turno abierto con un texto fijo y dice que los dias se completan despues', async () => {
      const wrapper = await mountDetail(employee())

      await buttonWith(wrapper, es.employees.offboard.action).trigger('click')
      await settle(1)

      const text = wrapper.find('[role="dialog"]').text()

      expect(text).toContain(es.employees.offboard.consequenceOpenShift)
      expect(text).toContain('se pueden completar después')
    })

    it('una alta que ya ha llegado no se limita a la fecha de alta', async () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-02T10:00:00Z') })

      const wrapper = await mountDetail(employee({ hired_at: '2026-08-14' }))

      await buttonWith(wrapper, es.employees.offboard.action).trigger('click')
      await settle(1)

      expect(wrapper.find('[data-test="offboard-future-hire"]').exists()).toBe(false)
      expect(dateInput(wrapper).attributes('max')).toBe('2026-10-02')
    })

    it('pinta el 422 del servidor en el campo de fecha, accesible', async () => {
      const message = 'La fecha de cese (2026-10-31) es posterior a hoy (2026-10-02).'
      const wrapper = await mountDetail(employee(), (url, init) =>
        url.endsWith('/offboard') && init?.method === 'POST'
          ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
              errors: { terminated_at: [message] },
            })
          : null,
      )

      await buttonWith(wrapper, es.employees.offboard.action).trigger('click')
      await settle(1)
      await wrapper.find('[role="dialog"] select').setValue('endOfContract')
      await buttonWith(wrapper, es.employees.offboard.confirmAction).trigger('click')
      await settle()

      const input = dateInput(wrapper)
      const describedBy = (input.attributes('aria-describedby') ?? '').split(' ')
      const errorParagraph = describedBy
        .map((id) => wrapper.find(`[id="${id}"]`))
        .find((candidate) => candidate.exists() && candidate.text() === message)

      expect(input.attributes('aria-invalid')).toBe('true')
      expect(errorParagraph).toBeDefined()
      // El dialogo sigue abierto para corregir la fecha.
      expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    })

    it('un 409 al dar de baja cierra el dialogo, lo explica y recarga la ficha', async () => {
      let reads = 0
      const wrapper = await mountDetail(employee(), (url, init) => {
        if (url.endsWith('/offboard') && init?.method === 'POST') {
          return problemResponse(409, TERMINATED_TYPE)
        }

        if (url === `/api/v1/employees/${EMPLOYEE_UUID}`) {
          reads += 1
        }

        return null
      })
      const readsBefore = reads

      await buttonWith(wrapper, es.employees.offboard.action).trigger('click')
      await settle(1)
      await wrapper.find('[role="dialog"] select').setValue('endOfContract')
      await buttonWith(wrapper, es.employees.offboard.confirmAction).trigger('click')
      await settle()

      expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="employee-conflict"]').text()).toBe(
        es.employees.conflict.terminated,
      )
      expect(reads).toBeGreaterThan(readsBefore)
    })

    it('un 409 al modificar una ficha dada de baja lo explica y recarga la ficha', async () => {
      let reads = 0
      const wrapper = await mountDetail(employee(), (url, init) => {
        if (init?.method === 'PATCH') {
          return problemResponse(409, TERMINATED_TYPE)
        }

        if (url === `/api/v1/employees/${EMPLOYEE_UUID}`) {
          reads += 1
        }

        return null
      })
      const readsBefore = reads

      await buttonWith(wrapper, es.common.edit).trigger('click')
      await settle(1)
      await wrapper.find('[data-test="teleworking-checkbox"]').setValue(true)
      await wrapper.find('#employee-edit-form').trigger('submit')
      await settle(1)
      await buttonWith(wrapper, es.employees.detail.confirmAction).trigger('click')
      await settle()

      expect(wrapper.find('[data-test="employee-conflict"]').text()).toBe(
        es.employees.conflict.terminated,
      )
      expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
      expect(reads).toBeGreaterThan(readsBefore)
    })

    it('un 409 de correo duplicado en la modificacion conserva la edicion y lo escrito', async () => {
      const wrapper = await mountDetail(employee(), (_url, init) =>
        init?.method === 'PATCH' ? problemResponse(409, 'urn:kronoqr:problem:conflict') : null,
      )

      await buttonWith(wrapper, es.common.edit).trigger('click')
      await settle(1)
      await wrapper.find('#employee-edit-form input[type="email"]').setValue('otra@hotel.example')
      await wrapper.find('#employee-edit-form').trigger('submit')
      await settle(1)
      await buttonWith(wrapper, es.employees.detail.confirmAction).trigger('click')
      await settle()

      expect(wrapper.find('[data-test="employee-conflict"]').exists()).toBe(false)
      expect(wrapper.find('[role="dialog"]').text()).toContain(es.errors.conflict.title)
      expect(wrapper.find('[role="dialog"]').text()).toContain('otra@hotel.example')
      expect(wrapper.find('#employee-edit-form').exists()).toBe(true)
      expect(
        wrapper.find<HTMLInputElement>('#employee-edit-form input[type="email"]').element.value,
      ).toBe('otra@hotel.example')
    })

    it('un 409 que no es de baja al dar de baja deja el dialogo abierto', async () => {
      const wrapper = await mountDetail(employee(), (url, init) =>
        url.endsWith('/offboard') && init?.method === 'POST'
          ? problemResponse(409, 'urn:kronoqr:problem:conflict')
          : null,
      )

      await buttonWith(wrapper, es.employees.offboard.action).trigger('click')
      await settle(1)
      await wrapper.find('[role="dialog"] select').setValue('endOfContract')
      await buttonWith(wrapper, es.employees.offboard.confirmAction).trigger('click')
      await settle()

      expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="employee-conflict"]').exists()).toBe(false)
    })

    it('restablecer el PIN o registrar su entrega de una persona de baja avisa y recarga', async () => {
      for (const [open, confirm, suffix] of [
        [es.pin.actions.reset, es.pin.reset.action, '/pin/reset'],
        [es.pin.actions.registerDelivery, es.pin.delivery.action, '/pin/deliver'],
      ] as const) {
        const wrapper = await mountDetail(employee({ pin_status: 'issued' }), (url, init) =>
          url.includes(suffix) && init?.method === 'POST'
            ? problemResponse(409, TERMINATED_TYPE)
            : null,
        )

        await buttonWith(wrapper, open).trigger('click')
        await settle(1)
        await buttonWith(wrapper.find('[role="dialog"]'), confirm).trigger('click')
        await settle()

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="employee-conflict"]').text()).toBe(
          es.employees.conflict.terminated,
        )
      }
    })

    it('el aviso de baja se lee una sola vez: es el rol alert, sin anuncio aparte', async () => {
      const wrapper = await mountDetail(employee(), (_url, init) =>
        init?.method === 'PATCH' ? problemResponse(409, TERMINATED_TYPE) : null,
      )

      await buttonWith(wrapper, es.common.edit).trigger('click')
      await settle(1)
      await wrapper.find('[data-test="teleworking-checkbox"]').setValue(true)
      await wrapper.find('#employee-edit-form').trigger('submit')
      await settle(1)
      await buttonWith(wrapper, es.employees.detail.confirmAction).trigger('click')
      await settle()

      expect(announcement.value).toBe('')
      expect(
        wrapper
          .findAll('[role="alert"]')
          .filter((node) => node.text() === es.employees.conflict.terminated),
      ).toHaveLength(1)
    })
  })
})
