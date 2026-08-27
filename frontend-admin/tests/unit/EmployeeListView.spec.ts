import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EmployeeListView from '@/features/employees/EmployeeListView.vue'
import es from '@/shared/i18n/locales/es.json'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { EMPLOYEE_UUID, SITE, employee, employeeCollection } from './support/fixtures'
import { jsonResponse, mountView, settle, stubFetch } from './support/harness'

const DEPARTMENTS = { data: [{ id: 3, site_id: 1, name: 'Recepcion' }] }

function routes(employees: unknown) {
  return (url: string) => {
    if (url.startsWith('/api/v1/sites')) {
      return jsonResponse({ data: [SITE] })
    }

    if (url.startsWith('/api/v1/departments')) {
      return jsonResponse(DEPARTMENTS)
    }

    return jsonResponse(employees)
  }
}

beforeEach(() => {
  window.sessionStorage.clear()
  clearAnnouncement()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('EmployeeListView', () => {
  it('anuncia que esta cargando antes de tener datos', async () => {
    stubFetch(() => new Promise<Response>(() => {}))

    const wrapper = await mountView(EmployeeListView)

    expect(wrapper.find('[role="status"]').text()).toContain(es.employees.loading)
  })

  it('pinta la plantilla con encabezados de columna y de fila asociados', async () => {
    stubFetch(routes(employeeCollection([employee()])))

    const wrapper = await mountView(EmployeeListView)

    await settle()

    expect(wrapper.find('caption').exists()).toBe(true)
    expect(wrapper.findAll('thead th[scope="col"]')).toHaveLength(6)
    expect(wrapper.find('tbody th[scope="row"]').text()).toContain('Youssef Amrani')
    expect(wrapper.text()).toContain('E7QK2MXPR')
    expect(wrapper.text()).toContain('Hotel Marina')
    expect(wrapper.text()).toContain('Recepcion')
  })

  it('enseña el estado del PIN, nunca el PIN', async () => {
    stubFetch(routes(employeeCollection([employee({ pin_status: 'delivered' })])))

    const wrapper = await mountView(EmployeeListView)

    await settle()

    expect(wrapper.text()).toContain(es.employees.table.pinStatus)
    expect(wrapper.text()).toContain(es.pin.status.delivered)
    expect(wrapper.text()).not.toMatch(/\b\d{6}\b/)
  })

  it('enlaza cada fila con su ficha por el UUID publico', async () => {
    stubFetch(routes(employeeCollection([employee()])))

    const wrapper = await mountView(EmployeeListView)

    await settle()

    expect(wrapper.find('tbody a').attributes('href')).toBe(`/employees/${EMPLOYEE_UUID}`)
  })

  it('anuncia cuantos resultados hay en la region viva', async () => {
    stubFetch(routes(employeeCollection([employee()], 37)))

    await mountView(EmployeeListView)
    await settle()

    expect(announcement.value).toContain('37')
  })

  it('explica un vacio con filtros de forma distinta a un vacio de verdad', async () => {
    stubFetch(routes(employeeCollection([])))

    const wrapper = await mountView(EmployeeListView)

    await settle()
    expect(wrapper.text()).toContain(es.employees.empty.description)

    await wrapper.find('#employees-status-filter').setValue('terminated')
    await settle()

    expect(wrapper.text()).toContain(es.employees.empty.filtered)
  })

  it('pide al servidor el filtro elegido, en lugar de filtrar en el navegador', async () => {
    const spy = stubFetch(routes(employeeCollection([employee()])))

    const wrapper = await mountView(EmployeeListView)

    await settle()
    await wrapper.find('#employees-status-filter').setValue('terminated')
    await settle()

    const urls = spy.mock.calls.map((call) => String(call[0]))

    expect(urls.some((url) => url.includes('status=terminated'))).toBe(true)
  })

  it('pagina en el servidor y no trae la plantilla entera', async () => {
    const spy = stubFetch(routes(employeeCollection([employee()], 120)))

    const wrapper = await mountView(EmployeeListView)

    await settle()

    expect(spy.mock.calls.some((call) => String(call[0]).includes('per_page=25'))).toBe(true)
    expect(wrapper.text()).toContain('1–25 de 120')

    await wrapper.findAll('nav button')[1]?.trigger('click')
    await settle()

    expect(spy.mock.calls.some((call) => String(call[0]).includes('page=2'))).toBe(true)
  })

  it('cuenta que ha pasado y que hacer cuando el servidor no responde', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const wrapper = await mountView(EmployeeListView)

    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.advice)
  })

  it('tras el alta enseña el PIN una sola vez, en su dialogo', async () => {
    stubFetch((url, init) => {
      if (url.startsWith('/api/v1/sites')) {
        return jsonResponse({ data: [SITE] })
      }

      if (url.startsWith('/api/v1/departments')) {
        return jsonResponse(DEPARTMENTS)
      }

      if (init?.method === 'POST') {
        return jsonResponse(
          {
            employee: employee({ pin_status: 'issued' }),
            pin: {
              employee_uuid: EMPLOYEE_UUID,
              pin: '483920',
              issued_at: '2026-08-14T08:02:11.907Z',
              pin_status: 'issued',
            },
          },
          201,
        )
      }

      return jsonResponse(employeeCollection([employee()]))
    })

    const wrapper = await mountView(EmployeeListView)

    await settle()
    await wrapper.find('button').trigger('click')
    await settle()

    expect(wrapper.text()).toContain(es.employees.create.heading)

    await wrapper.find('#employee-create-form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="pin-value"]').text()).toBe('483920')
    expect(wrapper.text()).toContain(es.pin.reveal.onlyOnce)
  })
})
