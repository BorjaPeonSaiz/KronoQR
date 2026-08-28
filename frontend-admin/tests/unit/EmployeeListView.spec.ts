import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EmployeeListView from '@/features/employees/EmployeeListView.vue'
import { EMPLOYEE_LIST_PER_PAGE as PER_PAGE } from '@/features/employees/employees.api'
import es from '@/shared/i18n/locales/es.json'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { EMPLOYEE_UUID, SITE, employee, employeeCollection } from './support/fixtures'
import { createTestRouter, jsonResponse, mountView, settle, stubFetch } from './support/harness'

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

    expect(spy.mock.calls.some((call) => String(call[0]).includes(`per_page=${PER_PAGE}`))).toBe(
      true,
    )
    expect(wrapper.text()).toContain(`1–${PER_PAGE} de 120`)

    await wrapper.findAll('nav button')[1]?.trigger('click')
    await settle()

    expect(spy.mock.calls.some((call) => String(call[0]).includes('page=2'))).toBe(true)
  })

  it('el buscador manda `q` al servidor tras el debounce y reinicia la pagina (RF-GP-01)', async () => {
    const spy = stubFetch(routes(employeeCollection([employee()], 120)))

    const wrapper = await mountView(EmployeeListView)

    await settle()
    await wrapper.findAll('nav button')[1]?.trigger('click')
    await settle()

    expect(spy.mock.calls.some((call) => String(call[0]).includes('page=2'))).toBe(true)

    await wrapper.find('#employees-search-filter').setValue('maria')
    // El debounce es de 300 ms: no debe mandar una peticion por cada tecla.
    await new Promise((resolve) => setTimeout(resolve, 320))
    await settle()

    const urls = spy.mock.calls.map((call) => String(call[0]))

    expect(urls.some((url) => url.includes('q=maria') && url.includes('page=1'))).toBe(true)
  })

  it('borrar la busqueda con el boton la vacia y vuelve a pedir sin `q` (RF-GP-01)', async () => {
    const spy = stubFetch(routes(employeeCollection([employee()])))

    const wrapper = await mountView(EmployeeListView)

    await settle()
    await wrapper.find('#employees-search-filter').setValue('maria')
    await new Promise((resolve) => setTimeout(resolve, 320))
    await settle()

    expect(wrapper.find('#employees-search-filter').element).toHaveProperty('value', 'maria')

    await wrapper
      .findAll('button')
      .find((button) => button.text().includes(es.common.filters.clearSearch))
      ?.trigger('click')
    await settle()

    expect(wrapper.find('#employees-search-filter').element).toHaveProperty('value', '')

    const urls = spy.mock.calls.map((call) => String(call[0]))

    expect(urls.at(-1)).not.toContain('q=')
  })

  it('el select de departamento manda `department_id` al servidor (RF-GP-01)', async () => {
    const spy = stubFetch(routes(employeeCollection([employee()])))

    const wrapper = await mountView(EmployeeListView)

    await settle()
    await wrapper.find('#employees-department-filter').setValue('3')
    await settle()

    const urls = spy.mock.calls.map((call) => String(call[0]))

    expect(urls.some((url) => url.includes('department_id=3'))).toBe(true)
  })

  it('el estado del PIN se envia al servidor con `pin_status`, y reinicia la pagina (RF-GP-01)', async () => {
    const spy = stubFetch(routes(employeeCollection([employee({ pin_status: 'delivered' })], 120)))

    const wrapper = await mountView(EmployeeListView)

    await settle()
    await wrapper.findAll('nav button')[1]?.trigger('click')
    await settle()

    expect(spy.mock.calls.some((call) => String(call[0]).includes('page=2'))).toBe(true)

    await wrapper.find('#employees-pin-status-filter').setValue('delivered')
    await settle()

    const urls = spy.mock.calls.map((call) => String(call[0]))

    expect(urls.some((url) => url.includes('pin_status=delivered') && url.includes('page=1'))).toBe(
      true,
    )
  })

  it('la barra de paginacion sigue visible aunque el filtro de PIN no tenga resultados (RF-GP-01)', async () => {
    const spy = stubFetch((url: string) => {
      if (url.startsWith('/api/v1/sites')) {
        return jsonResponse({ data: [SITE] })
      }

      if (url.startsWith('/api/v1/departments')) {
        return jsonResponse(DEPARTMENTS)
      }

      // El servidor, no el cliente, decide que ninguna fila encaja con el
      // filtro de estado del PIN elegido.
      if (url.includes('pin_status=delivered')) {
        return jsonResponse({ data: [], meta: { page: 1, per_page: 30, total: 0, total_pages: 0 } })
      }

      return jsonResponse(employeeCollection([employee({ pin_status: 'pending' })], 30))
    })

    const wrapper = await mountView(EmployeeListView)

    await settle()
    await wrapper.find('#employees-pin-status-filter').setValue('delivered')
    await settle()

    expect(spy.mock.calls.some((call) => String(call[0]).includes('pin_status=delivered'))).toBe(
      true,
    )
    expect(wrapper.text()).toContain(es.employees.empty.filtered)
    expect(wrapper.find('nav').exists()).toBe(true)
  })

  it('acota la pagina si un favorito guardado apunta mas alla del total real (RF-GP-01)', async () => {
    const rows = [employee()]
    const spy = stubFetch((url: string) => {
      if (url.startsWith('/api/v1/sites')) {
        return jsonResponse({ data: [SITE] })
      }

      if (url.startsWith('/api/v1/departments')) {
        return jsonResponse(DEPARTMENTS)
      }

      const requestedPage = Number(new URL(url, 'http://localhost').searchParams.get('page') ?? '1')

      // La plantilla real solo tiene una pagina. Un `?page=4` guardado como
      // favorito antes de una baja masiva queda fuera de rango.
      return jsonResponse({
        data: requestedPage === 1 ? rows : [],
        meta: { page: requestedPage, per_page: PER_PAGE, total: rows.length, total_pages: 1 },
      })
    })

    const router = createTestRouter()
    await router.push({ name: 'employees', query: { page: '4' } })

    const wrapper = await mountView(EmployeeListView, { router })

    await settle(8)

    const urls = spy.mock.calls.map((call) => String(call[0]))

    // Se pide la pagina del favorito al aterrizar...
    expect(urls.some((url) => url.includes('page=4'))).toBe(true)
    // ...pero, sabiendo que solo hay una pagina, se acota y se vuelve a pedir.
    expect(urls.some((url) => url.includes('page=1'))).toBe(true)
    expect(wrapper.text()).toContain('Youssef Amrani')
    expect(wrapper.text()).not.toContain(es.employees.empty.description)
    expect(router.currentRoute.value.query['page']).toBeUndefined()
  })

  it('refleja los filtros en la query de la ruta para reproducir la vista (RF-GP-01)', async () => {
    stubFetch(routes(employeeCollection([employee()])))

    const router = createTestRouter()
    const wrapper = await mountView(EmployeeListView, { router })

    await settle()
    await wrapper.find('#employees-status-filter').setValue('terminated')
    await settle()

    expect(router.currentRoute.value.query['status']).toBe('terminated')
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
