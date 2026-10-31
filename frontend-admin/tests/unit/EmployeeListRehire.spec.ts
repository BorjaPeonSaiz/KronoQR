import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { useSessionStore } from '@/features/auth/session.store'
import EmployeeListView from '@/features/employees/EmployeeListView.vue'
import es from '@/shared/i18n/locales/es.json'
import type { Employee } from '@/shared/api/types'
import {
  SITE,
  employee,
  employeeCollection,
  employmentContract,
  managementUser,
  rehired,
} from './support/fixtures'
import {
  buttonWith,
  createTestPinia,
  createTestRouter,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

// «Dar de alta de nuevo» en Plantilla con el filtro «De baja» (RN-27, ADR-062 §6,
// pedido del propietario del 18-10-2026): un boton por fila, seleccion multiple con
// fecha comun, todo o nada y lote de tarjetas.

const A = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b01'
const B = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b02'
const C = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b03'

function former(uuid: string, first: string, overrides: Partial<Employee> = {}): Employee {
  return employee({
    uuid,
    first_name: first,
    last_name: 'Prueba',
    employee_code: `COD${first}`,
    status: 'terminated',
    hired_at: '2026-03-16',
    terminated_at: '2026-10-15',
    offboarding: { state: 'effective', terminated_at: '2026-10-15' },
    ...overrides,
  })
}

interface Calls {
  rehire: Array<{ url: string; body: Record<string, unknown> }>
  lists: string[]
}

function stubApi(
  rows: Employee[],
  extra?: (url: string, init: RequestInit | undefined) => Response | null,
): Calls {
  const calls: Calls = { rehire: [], lists: [] }

  stubFetch((url, init) => {
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

    if (init?.method === 'POST' && url.includes('/rehire')) {
      calls.rehire.push({ url, body: JSON.parse(String(init.body)) })

      const uuids: string[] =
        url === '/api/v1/employees/rehire'
          ? (JSON.parse(String(init.body)) as { employee_uuids: string[] }).employee_uuids
          : [url.split('/')[4] ?? '']
      const results = uuids.map((uuid) => {
        const person = rows.find((row) => row.uuid === uuid)

        return rehired({
          employee: employee({ uuid, first_name: person?.first_name ?? '', last_name: 'Prueba' }),
        })
      })

      return jsonResponse(url === '/api/v1/employees/rehire' ? { rehired: results } : results[0])
    }

    calls.lists.push(url)

    return jsonResponse(employeeCollection(rows))
  })

  return calls
}

async function mountList(status: string | null = 'terminated', attachTo?: HTMLElement) {
  const router = createTestRouter()
  const pinia = createTestPinia()
  const session = useSessionStore()

  session.user = managementUser({ abilities: ['employees:*', 'credentials:*'] })
  session.token = 'un-token'
  session.status = 'authenticated'

  await router.push(status === null ? '/employees' : `/employees?status=${status}`)

  const wrapper = await mountView(EmployeeListView, { router, pinia, attachTo })

  await settle()

  return wrapper
}

const confirm = (wrapper: Awaited<ReturnType<typeof mountList>>) =>
  wrapper.find('[role="dialog"] [data-test="confirm-dialog-confirm"]')

beforeEach(() => {
  clearAnnouncement()
  window.sessionStorage.clear()
  vi.useFakeTimers({ toFake: ['Date'], now: new Date('2027-03-29T10:00:00Z') })
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('Plantilla con el filtro «De baja» (RN-27)', () => {
  it('cada fila lleva «Dar de alta de nuevo», con el nombre de la persona para quien usa lector de pantalla', async () => {
    stubApi([former(A, 'Ana'), former(B, 'Beto')])

    const wrapper = await mountList()
    const buttons = wrapper.findAll('[data-test="rehire-row"]')

    expect(buttons).toHaveLength(2)
    expect(buttons[0]?.text()).toContain(es.employees.actions.rehire)
    expect(buttons[0]?.find('.sr-only').text()).toBe('a Ana Prueba')
  })

  it('sin el filtro no hay botones ni casillas, y la tabla conserva sus 6 columnas', async () => {
    stubApi([employee()])

    const wrapper = await mountList(null)

    expect(wrapper.find('[data-test="rehire-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="select-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="rehire-toolbar"]').exists()).toBe(false)
    expect(wrapper.findAll('thead th[scope="col"]')).toHaveLength(6)
  })

  it('una ficha minimizada no se ofrece: no se puede dar de alta de nuevo', async () => {
    stubApi([
      former(A, 'Ana'),
      former(B, '', {
        first_name: '',
        last_name: '',
        anonymized_at: '2026-10-01T08:30:00.000000Z',
      }),
    ])

    const wrapper = await mountList()

    expect(wrapper.findAll('[data-test="rehire-row"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test="select-row"]')).toHaveLength(1)
  })

  it('el boton de una fila abre el dialogo con la fecha de hoy y sin pedir mas datos', async () => {
    const calls = stubApi([former(A, 'Ana')])

    const wrapper = await mountList()

    await wrapper.find('[data-test="rehire-row"]').trigger('click')
    await settle()

    expect(wrapper.find('[role="dialog"] input[type="date"]').element).toHaveProperty(
      'value',
      '2027-03-29',
    )

    await confirm(wrapper).trigger('click')
    await settle()

    expect(calls.rehire).toEqual([
      {
        url: `/api/v1/employees/${A}/rehire`,
        body: { hired_at: '2027-03-29', reset_pin: false },
      },
    ])
    // Y ofrece la tarjeta nueva en el acto.
    expect(wrapper.find('[role="dialog"]').text()).toContain(es.employees.rehire.card.heading)
    expect(announcement.value).toBe(es.employees.announce.rehired)
  })

  it('«Dar de alta de nuevo a la selección» no se muestra sin marcar a nadie: aparece con la primera casilla', async () => {
    stubApi([former(A, 'Ana')])

    const wrapper = await mountList()

    expect(wrapper.find('[data-test="rehire-selected"]').exists()).toBe(false)

    await wrapper.find('[data-test="select-row"]').setValue(true)
    await settle(1)

    expect(wrapper.find('[data-test="rehire-selected"]').exists()).toBe(true)
  })

  it('marca varias personas, lo anuncia y envia una sola peticion con fecha comun', async () => {
    const calls = stubApi([former(A, 'Ana'), former(B, 'Beto'), former(C, 'Carla')])

    const wrapper = await mountList()
    const rows = wrapper.findAll('[data-test="select-row"]')

    await rows[0]?.setValue(true)
    await rows[2]?.setValue(true)
    await settle(1)

    expect(wrapper.find('[data-test="selection-summary"]').text()).toBe('2 personas seleccionadas')
    expect(announcement.value).toBe('2 personas seleccionadas.')
    expect(rows[0]?.attributes('aria-label')).toBe('Seleccionar a Ana Prueba')

    await wrapper.find('[data-test="rehire-selected"]').trigger('click')
    await settle()

    expect(wrapper.find('[role="dialog"]').text()).toContain(es.employees.rehireBatch.heading)

    await confirm(wrapper).trigger('click')
    await settle()

    expect(calls.rehire).toEqual([
      {
        url: '/api/v1/employees/rehire',
        body: { employee_uuids: [A, C], hired_at: '2027-03-29', reset_pin: false },
      },
    ])
    // Lote de tarjetas para imprimir, y la seleccion ya no existe.
    expect(wrapper.find('[data-test="rehire-card-list"]').text()).toContain('Ana Prueba')
    expect(announcement.value).toBe('2 personas vuelven a estar de alta.')
  })

  it('la casilla de la cabecera marca y desmarca a todas las de la pagina, con estado intermedio', async () => {
    stubApi([former(A, 'Ana'), former(B, 'Beto')])

    const wrapper = await mountList()
    const all = wrapper.find<HTMLInputElement>('[data-test="select-all"]')

    expect(all.attributes('aria-label')).toBe(es.employees.table.selectAll)

    await all.setValue(true)
    await settle(1)

    expect(
      wrapper
        .findAll<HTMLInputElement>('[data-test="select-row"]')
        .every((row) => row.element.checked),
    ).toBe(true)
    expect(wrapper.find('[data-test="selection-summary"]').text()).toBe('2 personas seleccionadas')

    await wrapper.findAll('[data-test="select-row"]')[0]?.setValue(false)
    await settle(1)

    expect(wrapper.find<HTMLInputElement>('[data-test="select-all"]').element.indeterminate).toBe(
      true,
    )

    // Con la mitad marcada, marcar la cabecera marca a todas...
    await wrapper.find<HTMLInputElement>('[data-test="select-all"]').setValue(true)
    await settle(1)

    expect(wrapper.find('[data-test="selection-summary"]').text()).toBe('2 personas seleccionadas')

    // ...y desmarcarla las quita a todas.
    await wrapper.find<HTMLInputElement>('[data-test="select-all"]').setValue(false)
    await settle(1)

    expect(wrapper.find('[data-test="selection-summary"]').exists()).toBe(false)
  })

  it('«Quitar la selección» la vacia', async () => {
    stubApi([former(A, 'Ana')])

    const wrapper = await mountList('terminated', document.body)

    await wrapper.find('[data-test="select-row"]').setValue(true)
    await settle(1)
    await buttonWith(wrapper, es.employees.selection.clear).trigger('click')
    await settle(1)

    expect(wrapper.find('[data-test="selection-summary"]').exists()).toBe(false)
    // El boton que se pulso desaparece: se anuncia y el foco no se pierde en <body>.
    expect(announcement.value).toBe('0 personas seleccionadas.')
    expect(document.activeElement).toBe(wrapper.find('[data-test="select-all"]').element)

    wrapper.unmount()
  })

  it('todo o nada: el error por posicion dice a quien, no escribe nada y conserva la seleccion', async () => {
    const calls = stubApi([former(A, 'Ana'), former(B, 'Beto')], (url, init) =>
      url === '/api/v1/employees/rehire' && init?.method === 'POST'
        ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: { 'employee_uuids.1': ['Esta persona no esta de baja.'] },
          })
        : null,
    )

    const wrapper = await mountList()

    await wrapper.find<HTMLInputElement>('[data-test="select-all"]').setValue(true)
    await settle(1)
    await wrapper.find('[data-test="rehire-selected"]').trigger('click')
    await settle()
    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.find('[data-test="rehire-batch-error"]').text()).toBe(
      'Beto Prueba: Esta persona no esta de baja.',
    )
    expect(calls.rehire).toHaveLength(0)

    // Cierra el dialogo: la seleccion sigue ahi para corregir y repetir.
    await wrapper.find('[role="dialog"] [data-test="confirm-dialog-cancel"]').trigger('click')
    await settle(1)

    expect(wrapper.find('[data-test="selection-summary"]').text()).toBe('2 personas seleccionadas')
  })

  it('con PIN nuevo en el lote los enseña todos una vez', async () => {
    const withPin = (uuid: string, pin: string) =>
      rehired({
        pin: {
          employee_uuid: uuid,
          pin,
          issued_at: '2027-03-29T10:00:00.000000Z',
          pin_status: 'issued',
        },
      })

    stubApi([former(A, 'Ana'), former(B, 'Beto')], (url, init) =>
      url === '/api/v1/employees/rehire' && init?.method === 'POST'
        ? jsonResponse({ rehired: [withPin(A, '111111'), withPin(B, '222222')] })
        : null,
    )

    const wrapper = await mountList()

    await wrapper.find<HTMLInputElement>('[data-test="select-all"]').setValue(true)
    await wrapper.find('[data-test="rehire-selected"]').trigger('click')
    await settle()
    await wrapper.find('[role="dialog"] [data-test="rehire-reset-pin"]').setValue(true)
    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.findAll('[data-test="batch-pin-value"]').map((cell) => cell.text())).toEqual([
      '111111',
      '222222',
    ])
  })

  it('al salir del filtro «De baja» la seleccion se descarta', async () => {
    stubApi([former(A, 'Ana')])

    const wrapper = await mountList()

    await wrapper.find('[data-test="select-row"]').setValue(true)
    await settle(1)

    await wrapper.find('#employees-status-filter').setValue('')
    await settle()
    await wrapper.find('#employees-status-filter').setValue('terminated')
    await settle()

    expect(wrapper.find('[data-test="selection-summary"]').exists()).toBe(false)
  })

  it('una baja programada sale como «Baja programada» en la columna de situacion', async () => {
    stubApi([
      employee({
        terminated_at: '2026-11-15',
        offboarding: { state: 'scheduled', terminated_at: '2026-11-15' },
      }),
    ])

    const wrapper = await mountList(null)

    expect(wrapper.find('[data-test="employee-row"]').text()).toContain(
      es.employees.status.scheduled,
    )
  })

  it('si la persona ya no esta de baja (409) relee la lista', async () => {
    const calls = stubApi([former(A, 'Ana')], (url, init) =>
      url.endsWith('/rehire') && init?.method === 'POST'
        ? problemResponse(409, 'urn:kronoqr:problem:employee-not-terminated')
        : null,
    )

    const wrapper = await mountList()
    const readsBefore = calls.lists.length

    await wrapper.find('[data-test="rehire-row"]').trigger('click')
    await settle()
    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(calls.lists.length).toBeGreaterThan(readsBefore)
    // La fila desaparece de «De baja»: se dice por que, y es una alerta.
    const notice = wrapper.find('[data-test="rehire-stale"]')

    expect(notice.attributes('role')).toBe('alert')
    expect(notice.text()).toContain(es.employees.rehire.stale.title)

    // Abrir otra readmision descarta el aviso de la anterior.
    await wrapper.find('[data-test="rehire-row"]').trigger('click')
    await settle(1)

    expect(wrapper.find('[data-test="rehire-stale"]').exists()).toBe(false)
  })
})
