import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { useSessionStore } from '@/features/auth/session.store'
import EmployeeDetailView from '@/features/employees/EmployeeDetailView.vue'
import es from '@/shared/i18n/locales/es.json'
import type { Employee } from '@/shared/api/types'
import {
  EMPLOYEE_UUID,
  SITE,
  board,
  employee,
  employmentContract,
  employmentPeriodRecord,
  managementUser,
  rehired,
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

// Baja programada, su anulacion, la readmision desde la ficha y la historia de alta
// y baja (RF-GP-03, RN-14, RN-27, ADR-062 §3, §6 y §7).

type Wrapper = Awaited<ReturnType<typeof mountView>>

const TODAY_ISO = '2026-10-31T10:00:00Z'

/** Una persona en alta con la baja programada para el 15 de noviembre. */
function scheduled(overrides: Partial<Employee> = {}): Employee {
  return employee({
    terminated_at: '2026-11-15',
    offboarding: { state: 'scheduled', terminated_at: '2026-11-15', offboarded_at: null },
    employment_periods: [
      employmentPeriodRecord({
        hired_at: '2026-08-14',
        terminated_at: '2026-11-15',
        state: 'scheduled',
      }),
    ],
    ...overrides,
  })
}

/** Una persona de baja desde el 31 de agosto. */
function terminated(overrides: Partial<Employee> = {}): Employee {
  return employee({
    status: 'terminated',
    hired_at: '2026-03-16',
    terminated_at: '2026-08-31',
    offboarding: {
      state: 'effective',
      terminated_at: '2026-08-31',
      offboarded_at: '2026-08-31T22:00:04Z',
    },
    employment_periods: [employmentPeriodRecord()],
    ...overrides,
  })
}

interface Calls {
  methods: string[]
  bodies: Record<string, unknown>[]
}

function stubDetail(
  record: Employee,
  extra?: (url: string, init: RequestInit | undefined) => Response | null,
): Calls {
  const calls: Calls = { methods: [], bodies: [] }

  stubFetch((url, init) => {
    const method = init?.method ?? 'GET'

    if (method !== 'GET') {
      calls.methods.push(`${method} ${url}`)
      calls.bodies.push(init?.body === undefined ? {} : JSON.parse(String(init.body)))
    }

    const handled = extra?.(url, init) ?? null

    if (handled !== null) {
      return handled
    }

    if (url.startsWith('/api/v1/site')) {
      return jsonResponse(SITE)
    }

    if (url.startsWith('/api/v1/departments')) {
      return jsonResponse({ data: [{ id: 3, name: 'Recepcion' }] })
    }

    if (url.endsWith('/contracts')) {
      return jsonResponse({ data: [employmentContract()] })
    }

    if (url.startsWith('/api/v1/credentials/status')) {
      return jsonResponse(board([]))
    }

    return jsonResponse(record)
  })

  return calls
}

async function mountDetail(record: Employee, extra?: Parameters<typeof stubDetail>[1]) {
  const calls = stubDetail(record, extra)
  const pinia = createTestPinia()
  const session = useSessionStore()

  session.user = managementUser({ abilities: ['employees:*', 'credentials:*'] })
  session.token = 'un-token'
  session.status = 'authenticated'

  const wrapper = await mountView(EmployeeDetailView, { props: { uuid: EMPLOYEE_UUID }, pinia })

  await settle()

  return { wrapper, calls }
}

const dialog = (wrapper: Wrapper) => wrapper.find('[role="dialog"]')
const confirm = (wrapper: Wrapper) => dialog(wrapper).find('[data-test="confirm-dialog-confirm"]')

beforeEach(() => {
  clearAnnouncement()
  window.sessionStorage.clear()
  vi.useFakeTimers({ toFake: ['Date'], now: new Date(TODAY_ISO) })
})

afterEach(() => {
  Reflect.deleteProperty(HTMLElement.prototype, 'scrollIntoView')
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('baja programada en la ficha (RN-14, RF-GP-03)', () => {
  it('se ve como «baja programada» con su fecha, no como una baja ni como alta sin mas', async () => {
    const { wrapper } = await mountDetail(scheduled())

    expect(wrapper.find('[data-test="employee-status"]').text()).toBe(es.employees.status.scheduled)
    expect(wrapper.find('[data-test="offboard-scheduled"]').text()).toContain('15 nov 2026')
    // Sigue en alta: se le puede cambiar la baja o anularla, no darla de baja otra vez.
    expect(wrapper.find('[data-test="offboard-open"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="rehire-open"]').exists()).toBe(false)
    expect(wrapper.text()).toContain(es.employees.fields.terminatedAtScheduled)
  })

  it('anular enseña desde que estado y hacia cual, y no cambia tarjeta ni PIN', async () => {
    const { wrapper } = await mountDetail(scheduled())

    await wrapper.find('[data-test="offboard-cancel"]').trigger('click')
    await settle(1)

    const preview = dialog(wrapper).find('table').text()

    expect(preview).toContain(es.employees.status.scheduled)
    expect(preview).toContain(es.employees.status.active)
    expect(preview).toContain('15 nov 2026')
    expect(dialog(wrapper).text()).toContain(es.employees.offboard.cancelNothingChanged)
  })

  it('confirmar la anulacion llama a offboard/cancel sin cuerpo, relee la ficha y lo anuncia', async () => {
    const { wrapper, calls } = await mountDetail(scheduled(), (url, init) =>
      url.endsWith('/offboard/cancel') && init?.method === 'POST' ? jsonResponse(employee()) : null,
    )

    await wrapper.find('[data-test="offboard-cancel"]').trigger('click')
    await settle(1)
    await confirm(wrapper).trigger('click')
    await settle()

    expect(calls.methods).toEqual([`POST /api/v1/employees/${EMPLOYEE_UUID}/offboard/cancel`])
    expect(calls.bodies).toEqual([{}])
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(announcement.value).toBe(es.employees.announce.offboardCancelled)
  })

  it('si la fecha de cese ya paso, explica que la baja rige y que hacer en lugar de anular', async () => {
    const { wrapper } = await mountDetail(scheduled(), (url, init) =>
      url.endsWith('/offboard/cancel') && init?.method === 'POST'
        ? problemResponse(409, 'urn:kronoqr:problem:offboarding-due')
        : null,
    )

    await wrapper.find('[data-test="offboard-cancel"]').trigger('click')
    await settle(1)
    await confirm(wrapper).trigger('click')
    await settle()

    const notice = dialog(wrapper).find('[data-test="employment-problem"]')

    expect(notice.text()).toContain(es.employees.problems.offboardingDue.title)
    expect(notice.text()).toContain('dala de alta de nuevo')
    // La fecha ya paso: releer no arregla nada, el dialogo se queda para leerlo.
    expect(wrapper.find('[data-test="employee-conflict"]').exists()).toBe(false)
  })

  it('si otra persona ya la anulo, cierra, lo explica en la ficha y la recarga', async () => {
    // El aviso esta arriba y la accion se hizo abajo: se trae a la vista.
    const reveal = vi.fn()

    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
      configurable: true,
      value: reveal,
    })

    const { wrapper } = await mountDetail(scheduled(), (url, init) =>
      url.endsWith('/offboard/cancel') && init?.method === 'POST'
        ? problemResponse(409, 'urn:kronoqr:problem:offboarding-not-scheduled')
        : null,
    )

    await wrapper.find('[data-test="offboard-cancel"]').trigger('click')
    await settle(1)
    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="employee-conflict"]').text()).toContain(
      es.employees.problems.offboardingNotScheduled.title,
    )
    expect(wrapper.find('[data-test="employee-conflict"]').attributes('role')).toBe('alert')
    expect(reveal).toHaveBeenCalledWith({ block: 'nearest', behavior: 'auto' })
  })

  it('cambiar la fecha parte de la programada y no deja confirmar si no cambia nada', async () => {
    const { wrapper } = await mountDetail(scheduled())

    await wrapper.find('[data-test="offboard-reschedule"]').trigger('click')
    await settle(1)

    expect(dialog(wrapper).find('h2').text()).toBe(es.employees.offboard.rescheduleHeading)
    expect(dialog(wrapper).find<HTMLInputElement>('input[type="date"]').element.value).toBe(
      '2026-11-15',
    )
    expect(dialog(wrapper).find('[data-test="offboard-rescheduling"]').text()).toContain(
      '15 nov 2026',
    )

    await dialog(wrapper).find('select').setValue('endOfContract')
    await settle(1)

    expect(confirm(wrapper).attributes('aria-disabled')).toBeDefined()

    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-11-20')
    await settle(1)

    expect(confirm(wrapper).attributes('aria-disabled')).toBeUndefined()
    expect(confirm(wrapper).text()).toBe(es.employees.offboard.confirmReschedule)
    // Mover una fecha se puede volver a mover o anular: no va en rojo. Y el resultado es informativo.
    expect(confirm(wrapper).classes()).toContain('bg-kq-primary-strong')
    expect(confirm(wrapper).classes()).not.toContain('bg-kq-danger')
    expect(dialog(wrapper).find('[data-test="offboard-outcome-scheduled"]').classes()).toContain(
      'bg-kq-surface-alt',
    )
    // «Antes -> despues» de la fecha de cese.
    expect(dialog(wrapper).find('table').text()).toContain('15 nov 2026')
    expect(dialog(wrapper).find('table').text()).toContain('20 nov 2026')
  })

  it('la baja programada se aplica ya con «Aplicar ya» y la fecha de hoy', async () => {
    const { wrapper, calls } = await mountDetail(scheduled(), (url, init) =>
      url.endsWith('/offboard') && init?.method === 'POST'
        ? jsonResponse(terminated({ terminated_at: '2026-10-31' }))
        : null,
    )

    await wrapper.find('[data-test="offboard-reschedule"]').trigger('click')
    await settle(1)
    await dialog(wrapper).find('select').setValue('dismissal')
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-10-31')
    await settle(1)
    await dialog(wrapper).find('[data-test="apply-now"] input').setValue(true)
    await settle(1)
    // Aplicarla ya si es un acto sin vuelta: vuelve el tono de peligro.
    expect(confirm(wrapper).classes()).toContain('bg-kq-danger')
    await confirm(wrapper).trigger('click')
    await settle()

    expect(calls.bodies[0]).toEqual({
      terminated_at: '2026-10-31',
      reason: 'Despido',
      apply_now: true,
    })
    expect(announcement.value).toBe(es.employees.announce.offboarded)
  })

  it('una fecha de cese ya pasada que el servidor no deja cambiar se explica con su «que hacer»', async () => {
    const { wrapper } = await mountDetail(scheduled(), (url, init) =>
      url.endsWith('/offboard') && init?.method === 'POST'
        ? problemResponse(409, 'urn:kronoqr:problem:offboarding-due')
        : null,
    )

    await wrapper.find('[data-test="offboard-reschedule"]').trigger('click')
    await settle(1)
    await dialog(wrapper).find('select').setValue('dismissal')
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-11-20')
    await settle(1)
    await confirm(wrapper).trigger('click')
    await settle()

    expect(dialog(wrapper).find('[data-test="employment-problem"]').text()).toContain(
      es.employees.problems.offboardingDue.advice,
    )
  })
})

describe('dar de alta de nuevo desde la ficha (RN-27)', () => {
  it('una persona de baja ofrece «Dar de alta de nuevo»; una en alta, no', async () => {
    const gone = await mountDetail(terminated())

    expect(gone.wrapper.find('[data-test="rehire-open"]').text()).toBe(es.employees.rehire.action)
    expect(gone.wrapper.find('[data-test="offboard-open"]').exists()).toBe(false)

    const active = await mountDetail(employee())

    expect(active.wrapper.find('[data-test="rehire-open"]').exists()).toBe(false)
  })

  it('una ficha minimizada no se puede dar de alta de nuevo y no se ofrece', async () => {
    const { wrapper } = await mountDetail(
      terminated({
        first_name: '',
        last_name: '',
        anonymized_at: '2026-10-01T08:30:00.000000Z',
      }),
    )

    expect(wrapper.find('[data-test="rehire-open"]').exists()).toBe(false)
  })

  it('confirma con un solo dato, relee la ficha y ofrece la tarjeta nueva en el acto', async () => {
    const { wrapper, calls } = await mountDetail(terminated(), (url, init) =>
      url.endsWith('/rehire') && init?.method === 'POST' ? jsonResponse(rehired()) : null,
    )

    await wrapper.find('[data-test="rehire-open"]').trigger('click')
    await settle(1)
    await confirm(wrapper).trigger('click')
    await settle()

    expect(calls.methods).toEqual([`POST /api/v1/employees/${EMPLOYEE_UUID}/rehire`])
    expect(calls.bodies[0]).toEqual({ hired_at: '2026-10-31', reset_pin: false })
    expect(announcement.value).toBe(es.employees.announce.rehired)
    expect(wrapper.find('[role="dialog"]').text()).toContain(es.employees.rehire.card.heading)
    expect(wrapper.find('[data-test="rehire-card-print"]').exists()).toBe(true)
  })

  it('con PIN nuevo lo enseña una vez y despues deja de existir en la pagina', async () => {
    const { wrapper } = await mountDetail(terminated(), (url, init) =>
      url.endsWith('/rehire') && init?.method === 'POST'
        ? jsonResponse(
            rehired({
              pin: {
                employee_uuid: EMPLOYEE_UUID,
                pin: '483920',
                issued_at: '2026-10-31T10:00:00.000000Z',
                pin_status: 'issued',
              },
            }),
          )
        : null,
    )

    await wrapper.find('[data-test="rehire-open"]').trigger('click')
    await settle(1)
    await dialog(wrapper).find('[data-test="rehire-reset-pin"]').setValue(true)
    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.find('[data-test="pin-value"]').text()).toBe('483920')

    await buttonWith(wrapper, es.pin.reveal.acknowledge).trigger('click')
    await settle(1)
    await wrapper.find('[data-test="rehire-card-close"]').trigger('click')
    await settle(1)

    expect(wrapper.text()).not.toContain('483920')
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('si alguien la dio de alta antes, lo explica en la ficha y la recarga', async () => {
    const { wrapper } = await mountDetail(terminated(), (url, init) =>
      url.endsWith('/rehire') && init?.method === 'POST'
        ? problemResponse(409, 'urn:kronoqr:problem:employee-not-terminated')
        : null,
    )

    await wrapper.find('[data-test="rehire-open"]').trigger('click')
    await settle(1)
    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="employee-conflict"]').text()).toContain(
      es.employees.problems.employeeNotTerminated.advice,
    )
  })

  /** Una baja de cese `terminatedAt` que se hizo efectiva en `offboardedAt` (UTC). */
  function offboardedAt(terminatedAt: string, instant: string): Employee {
    return terminated({
      terminated_at: terminatedAt,
      offboarding: {
        state: 'effective',
        terminated_at: terminatedAt,
        offboarded_at: instant,
      },
      employment_periods: [
        employmentPeriodRecord({
          terminated_at: terminatedAt,
          offboarded_at: instant,
        }),
      ],
    })
  }

  async function openRehireWith(record: Employee, hiredAt: string) {
    const { wrapper } = await mountDetail(record)

    await wrapper.find('[data-test="rehire-open"]').trigger('click')
    await settle(1)
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue(hiredAt)
    await settle(1)

    return wrapper
  }

  it('si la baja se hizo efectiva hoy avisa de esperar a los quioscos antes de que fiche', async () => {
    // Cese de hace una semana, registrado esta mañana (08:00 UTC del 31 es hoy en Madrid).
    const wrapper = await openRehireWith(
      offboardedAt('2026-10-24', '2026-10-31T08:00:00Z'),
      '2026-11-01',
    )

    expect(dialog(wrapper).find('[data-test="rehire-recent-warning"]').text()).toContain(
      'espera a que los quioscos estén sincronizados',
    )
  })

  it('una baja de cese de ayer pero efectiva ayer no avisa: lo manda offboarded_at, no el cese', async () => {
    const wrapper = await openRehireWith(
      offboardedAt('2026-10-30', '2026-10-30T22:00:04Z'),
      '2026-11-01',
    )

    expect(dialog(wrapper).find('[data-test="rehire-recent-warning"]').exists()).toBe(false)
  })
})

describe('historia de alta y baja en la ficha (RN-27)', () => {
  it('la ficha de RRHH lista los periodos y deja corregir la fecha de cese de una baja efectiva', async () => {
    const { wrapper } = await mountDetail(terminated())

    expect(wrapper.find('[data-test="employment-periods"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="employment-period"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="correct-termination"]').exists()).toBe(true)
  })

  it('una ficha sin periodos en la respuesta no pinta la seccion', async () => {
    const { wrapper } = await mountDetail(employee())

    expect(wrapper.find('[data-test="employment-periods"]').exists()).toBe(false)
  })
})
