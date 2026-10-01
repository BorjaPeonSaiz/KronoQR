// Contratos de una persona en su ficha (RF-GP-02, RF-IN-03).
//
// Lo que se comprueba: el listado respeta el orden del servidor y marca el
// vigente, el estado vacio explica el efecto en el informe, un fallo de red dice
// que hacer y se puede reintentar, el alta enseña desde que valor se cambia, y un
// 409 relee la serie sin perder lo escrito. No hay editar ni borrar.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/features/auth/session.store'
import EmployeeContractsSection from '@/features/employees/EmployeeContractsSection.vue'
import es from '@/shared/i18n/locales/es.json'
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { EMPLOYEE_UUID, SITE, employmentContract, managementUser } from './support/fixtures'
import {
  buttonWith,
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

type Wrapper = Awaited<ReturnType<typeof mountView>>

const CLOSED = employmentContract({
  id: 41,
  weekly_hours: 20,
  annual_hours: 1040,
  valid_from: '2026-01-01',
  valid_to: '2026-03-15',
  is_current: false,
})
const CURRENT = employmentContract()

function signIn(abilities: string[] = ['employees:*']) {
  const pinia = createTestPinia()
  const session = useSessionStore()

  session.user = managementUser({ abilities })
  session.token = 'un-token'
  session.status = 'authenticated'

  return pinia
}

async function mountSection(
  handler: (url: string, init: RequestInit | undefined) => Response,
  props: { canRegister?: boolean; abilities?: string[] } = {},
): Promise<{ wrapper: Wrapper; fetchSpy: ReturnType<typeof stubFetch> }> {
  const fetchSpy = stubFetch((url, init) =>
    url.startsWith('/api/v1/site') ? jsonResponse(SITE) : handler(url, init),
  )
  const wrapper = await mountView(EmployeeContractsSection, {
    props: { employeeUuid: EMPLOYEE_UUID, canRegister: props.canRegister ?? true },
    pinia: signIn(props.abilities),
  })

  await settle()

  return { wrapper, fetchSpy }
}

function contractCalls(spy: ReturnType<typeof stubFetch>, method: string): number {
  return spy.mock.calls.filter(
    (call) =>
      String(call[0]).endsWith('/contracts') &&
      ((call[1] as RequestInit | undefined)?.method ?? 'GET') === method,
  ).length
}

beforeEach(() => {
  window.sessionStorage.clear()
  clearAnnouncement()
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('EmployeeContractsSection (RF-GP-02)', () => {
  it('lista la serie en el orden del servidor y marca el contrato vigente', async () => {
    const { wrapper } = await mountSection(() => jsonResponse({ data: [CLOSED, CURRENT] }))

    const rows = wrapper.findAll('[data-test="contract-row"]')

    expect(rows).toHaveLength(2)
    expect(rows[0]?.attributes('data-current')).toBe('false')
    expect(rows[0]?.text()).toContain('20')
    expect(rows[1]?.attributes('data-current')).toBe('true')
    expect(rows[1]?.text()).toContain(es.contracts.current)
    // Cabeceras asociadas: cada fila tiene su encabezado de fila.
    expect(wrapper.findAll('thead th[scope="col"]')).toHaveLength(5)
    expect(wrapper.findAll('tbody th[scope="row"]')).toHaveLength(2)
  })

  it('no ofrece editar ni borrar un contrato: el contrato de la API no lo permite', async () => {
    const { wrapper } = await mountSection(() => jsonResponse({ data: [CLOSED, CURRENT] }))
    const labels = wrapper.findAll('button').map((button) => button.text())

    expect(labels).toEqual([es.contracts.actions.register])
  })

  it('sin contratos explica que el informe contara esos dias como «sin contrato»', async () => {
    const { wrapper } = await mountSection(() => jsonResponse({ data: [] }))

    expect(wrapper.text()).toContain(es.contracts.empty.title)
    expect(wrapper.text()).toContain(es.contracts.empty.description)
    expect(wrapper.find('[data-test="contracts-table"]').exists()).toBe(false)
  })

  it('con un fallo de red dice que ha pasado y permite reintentar', async () => {
    let calls = 0
    const { wrapper } = await mountSection(() => {
      calls += 1

      if (calls === 1) {
        throw new TypeError('Failed to fetch')
      }

      return jsonResponse({ data: [CURRENT] })
    })

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)

    await wrapper.find('[data-test="contracts-retry"]').trigger('click')
    await settle()

    expect(wrapper.findAll('[data-test="contract-row"]')).toHaveLength(1)
  })

  it('oculta el alta a quien no tiene employees:* y a una persona de baja', async () => {
    const readOnly = await mountSection(() => jsonResponse({ data: [CURRENT] }), {
      abilities: ['employees:read'],
    })

    expect(readOnly.wrapper.find('[data-test="contract-register-open"]').exists()).toBe(false)
    expect(readOnly.wrapper.find('[data-test="contracts-table"]').exists()).toBe(true)

    const terminated = await mountSection(() => jsonResponse({ data: [CURRENT] }), {
      canRegister: false,
    })

    expect(terminated.wrapper.find('[data-test="contract-register-open"]').exists()).toBe(false)
  })

  it('el alta enseña el valor vigente y el nuevo, y cierra el anterior el dia antes', async () => {
    const { wrapper } = await mountSection(() => jsonResponse({ data: [CURRENT] }))

    await wrapper.find('[data-test="contract-register-open"]').trigger('click')
    await settle(1)

    await wrapper.find('[data-test="contract-weekly-hours"]').setValue('37,5')
    await wrapper.find('[data-test="contract-schedule-type"]').setValue('continua')

    const dialog = wrapper.find('[role="dialog"]')
    const preview = dialog.find('table').text()

    // Desde 40 h hacia 37,5 h, nada de decimales con punto en la presentacion.
    expect(preview).toContain('40')
    expect(preview).toContain('37,5')
    expect(dialog.find('[data-test="closes-note"]').text()).toBe(
      es.contracts.register.closesPrevious,
    )
  })

  it('registra el contrato con el cuerpo del contrato de la API y relee la serie', async () => {
    let registered = false
    const { wrapper, fetchSpy } = await mountSection((_url, init) => {
      if (init?.method === 'POST') {
        registered = true

        return jsonResponse(employmentContract({ id: 59, weekly_hours: 37.5 }), 201)
      }

      return jsonResponse({ data: registered ? [CLOSED, CURRENT] : [CLOSED] })
    })

    await wrapper.find('[data-test="contract-register-open"]').trigger('click')
    await settle(1)

    expect(wrapper.find('[data-test="contract-submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="contract-weekly-hours"]').setValue('37,5')
    await wrapper.find('[data-test="contract-annual-hours"]').setValue('1780')
    await wrapper.find('[data-test="contract-schedule-type"]').setValue('turnos')
    await wrapper.find('[data-test="contract-valid-from"]').setValue('2026-04-01')
    await wrapper.find('#contract-register-form').trigger('submit')
    await settle()

    const post = fetchSpy.mock.calls.find(
      (call) => (call[1] as RequestInit | undefined)?.method === 'POST',
    )

    expect(JSON.parse(String((post?.[1] as RequestInit).body))).toEqual({
      weekly_hours: 37.5,
      annual_hours: 1780,
      schedule_type: 'turnos',
      valid_from: '2026-04-01',
    })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="contract-row"]')).toHaveLength(2)
  })

  it('ante un 409 relee los contratos, avisa y deja el dialogo abierto con lo escrito', async () => {
    let conflictSeen = false
    const { wrapper, fetchSpy } = await mountSection((_url, init) => {
      if (init?.method === 'POST') {
        conflictSeen = true

        return problemResponse(409, 'urn:kronoqr:problem:conflict')
      }

      return jsonResponse({ data: conflictSeen ? [CLOSED, CURRENT] : [CLOSED] })
    })

    await wrapper.find('[data-test="contract-register-open"]').trigger('click')
    await settle(1)
    await wrapper.find('[data-test="contract-weekly-hours"]').setValue('30')
    await wrapper.find('[data-test="contract-schedule-type"]').setValue('partida')
    await wrapper.find('#contract-register-form').trigger('submit')
    await settle()

    expect(contractCalls(fetchSpy, 'GET')).toBe(2)
    expect(wrapper.find('[data-test="contract-conflict"]').text()).toBe(
      es.contracts.register.conflict,
    )
    expect(
      (wrapper.find('[data-test="contract-weekly-hours"]').element as HTMLInputElement).value,
    ).toBe('30')
    // La vista previa ya parte del contrato vigente releido.
    expect(wrapper.find('[role="dialog"] table').text()).toContain('40')
    expect(buttonWith(wrapper, es.contracts.register.submit).attributes('disabled')).toBeUndefined()
  })

  it('un 422 nombra el campo en el formulario y no relee', async () => {
    const { wrapper, fetchSpy } = await mountSection((_url, init) =>
      init?.method === 'POST'
        ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: { valid_from: ['La fecha debe ser posterior al inicio del contrato vigente.'] },
          })
        : jsonResponse({ data: [CURRENT] }),
    )

    await wrapper.find('[data-test="contract-register-open"]').trigger('click')
    await settle(1)
    await wrapper.find('[data-test="contract-weekly-hours"]').setValue('30')
    await wrapper.find('[data-test="contract-schedule-type"]').setValue('partida')
    await wrapper.find('#contract-register-form').trigger('submit')
    await settle()

    expect(wrapper.find('[role="dialog"]').text()).toContain('posterior al inicio')
    expect(contractCalls(fetchSpy, 'GET')).toBe(1)
  })

  it('rechaza horas fuera de rango sin enviar nada', async () => {
    const { wrapper } = await mountSection(() => jsonResponse({ data: [] }))

    await wrapper.find('[data-test="contract-register-open"]').trigger('click')
    await settle(1)
    await wrapper.find('[data-test="contract-schedule-type"]').setValue('continua')
    await wrapper.find('[data-test="contract-weekly-hours"]').setValue('169')

    expect(wrapper.find('[data-test="contract-submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[role="dialog"]').text()).toContain(es.contracts.register.weeklyInvalid)
  })
})
