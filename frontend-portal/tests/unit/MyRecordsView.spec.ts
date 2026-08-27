// Mi registro de jornada (RF-ID-05, RF-ID-06, RF-ID-07, RL-05).
//
// Lo que se comprueba aqui es tanto lo que enseña como lo que NO puede llegar a
// pedir: ningun identificador de empleado sale nunca en la peticion, porque no
// hay ningun campo de este componente que lo acepte. Es la comprobacion, del
// lado del cliente, de que una URL manipulada no puede llevar a datos de otra
// persona (regla dura 18, RF-ID-07): aqui no hay nada que manipular.
import type { DOMWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MyRecordsView from '@/features/my-records/MyRecordsView.vue'
import { useSessionStore } from '@/features/login/session.store'
import es from '@/shared/i18n/locales/es.json'
import type { EmployeeWorkDays } from '@/shared/api/types'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import {
  EMPLOYEE_UUID,
  employeeWorkDays,
  portalEmployee,
  shiftEntry,
  workDay,
} from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubFetch } from './support/harness'

type Wrapper = Awaited<ReturnType<typeof mountView>>

let requestedUrls: string[] = []

async function mountMyRecords(workdays: EmployeeWorkDays = employeeWorkDays()): Promise<Wrapper> {
  const pinia = createTestPinia()

  useSessionStore(pinia).employee = portalEmployee()

  stubFetch((url) => {
    requestedUrls.push(url)

    return jsonResponse(workdays)
  })

  const wrapper = await mountView(MyRecordsView, { pinia })

  await settle()

  return wrapper
}

function fieldFor(wrapper: Wrapper, input: DOMWrapper<Element>): DOMWrapper<Element> | undefined {
  const id = input.attributes('id')

  return wrapper.findAll('label').find((label) => label.attributes('for') === id)
}

beforeEach(() => {
  window.sessionStorage.clear()
  clearAnnouncement()
  requestedUrls = []
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('MyRecordsView', () => {
  it('enseña las jornadas del periodo con su total en horas y minutos', async () => {
    const wrapper = await mountMyRecords()

    expect(wrapper.findAll('[data-test="workday"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="day-total"]').text()).toBe('8 h 05 min')
  })

  it('saluda a quien ha entrado con su propio nombre y codigo', async () => {
    const wrapper = await mountMyRecords()

    expect(wrapper.text()).toContain('Lucía Gómez Ruiz')
    expect(wrapper.text()).toContain('E7K2M9XQ4')
  })

  it('pide su registro sin ningun identificador de empleado: no hay nada que manipular', async () => {
    await mountMyRecords()

    const workdaysUrl = requestedUrls.find((url) => url.includes('/me/workdays')) ?? ''

    expect(workdaysUrl).toBe('/api/v1/me/workdays')
    expect(workdaysUrl).not.toContain(EMPLOYEE_UUID)
  })

  it('la suma de los tramos mostrados coincide exactamente con el total mostrado', async () => {
    const wrapper = await mountMyRecords()

    expect(wrapper.find('[data-test="summed-total"]').text()).toBe(
      wrapper.find('[data-test="day-total"]').text(),
    )
    expect(wrapper.find('[data-test="totals-mismatch"]').exists()).toBe(false)
  })

  it('dice que periodo se esta viendo y en que zona horaria', async () => {
    const wrapper = await mountMyRecords()

    expect(wrapper.find('[data-test="resolved-range"]').text()).toContain('2026-03-14')
    expect(wrapper.find('[data-test="resolved-range"]').text()).toContain('Europe/Madrid')
    expect(wrapper.text()).toContain(es.myRecords.zoneNotice)
  })

  it('consulta el rango que se le pide, y lo pide al servidor', async () => {
    const wrapper = await mountMyRecords()
    const inputs = wrapper.findAll('input[type="date"]')

    await inputs[0]?.setValue('2026-03-01')
    await inputs[1]?.setValue('2026-03-31')
    await wrapper.find('form').trigger('submit')
    await settle()

    const lastUrl = requestedUrls.filter((url) => url.includes('/me/workdays')).at(-1)

    expect(lastUrl).toContain('from=2026-03-01')
    expect(lastUrl).toContain('to=2026-03-31')
  })

  it('no consulta un periodo que termina antes de empezar: lo dice y no deja enviar', async () => {
    const wrapper = await mountMyRecords()
    const inputs = wrapper.findAll('input[type="date"]')
    const before = requestedUrls.length

    await inputs[0]?.setValue('2026-03-31')
    await inputs[1]?.setValue('2026-03-01')
    await settle(1)

    expect(wrapper.text()).toContain(es.myRecords.filters.inverted)
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('form').trigger('submit')
    await settle(1)

    expect(requestedUrls).toHaveLength(before)
  })

  it('avisa del techo de 366 dias antes de gastar la peticion', async () => {
    const wrapper = await mountMyRecords()
    const inputs = wrapper.findAll('input[type="date"]')

    await inputs[0]?.setValue('2024-01-01')
    await inputs[1]?.setValue('2026-12-31')
    await settle(1)

    expect(wrapper.text()).toContain('366')
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('un periodo sin jornadas se explica, en vez de parecer un fallo', async () => {
    const wrapper = await mountMyRecords(
      employeeWorkDays([], { from: '2026-04-01', to: '2026-04-30' }),
    )

    expect(wrapper.text()).toContain(es.myRecords.empty.title)
    expect(wrapper.text()).toContain(es.myRecords.empty.description)
  })

  it('un dia cuyos tramos se anularon sigue apareciendo con su historico', async () => {
    const wrapper = await mountMyRecords(
      employeeWorkDays([workDay({ shift_entries: [], total_minutes: 0, shift_count: 0 })]),
    )

    expect(wrapper.findAll('[data-test="workday"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="entries-empty"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="correction"]')).toHaveLength(1)
  })

  it('marca el turno abierto y la revision pendiente con palabras, no solo con color', async () => {
    const wrapper = await mountMyRecords(
      employeeWorkDays([
        workDay({
          shift_entries: [
            shiftEntry({
              status: 'open',
              clocked_out_at: null,
              clocked_out_at_local: null,
              clocked_out_recorded_at: null,
              clock_out_source: null,
              duration_minutes: null,
            }),
          ],
          total_minutes: 0,
          has_open_shift: true,
          has_incident: true,
        }),
      ]),
    )

    expect(wrapper.find('[data-test="flag-open-shift"]').text()).toBe(
      es.myRecords.day.flags.openShift,
    )
    expect(wrapper.find('[data-test="flag-incident"]').text()).toBe(es.myRecords.day.flags.incident)
    expect(wrapper.text()).toContain(es.myRecords.day.flags.openShiftHint)
  })

  it('anuncia cuantas jornadas se han encontrado, sin mover el foco', async () => {
    await mountMyRecords()

    expect(announcement.value).toContain('1')
    expect(announcement.value).toContain('2026-03-14')
  })

  it('cuenta que ha pasado y que hacer si el registro no se puede cargar', async () => {
    const pinia = createTestPinia()

    useSessionStore(pinia).employee = portalEmployee()
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const wrapper = await mountView(MyRecordsView, { pinia })

    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.advice)
  })
})

describe('MyRecordsView, accesibilidad (WCAG 2.2 AA)', () => {
  it('tiene un solo titulo de nivel 1 y no salta niveles de encabezado', async () => {
    const wrapper = await mountMyRecords()
    const levels = wrapper
      .findAll('h1, h2, h3, h4, h5, h6')
      .map((heading) => Number.parseInt(heading.element.tagName.slice(1), 10))

    expect(levels.filter((level) => level === 1)).toHaveLength(1)
    expect(levels[0]).toBe(1)

    for (let index = 1; index < levels.length; index += 1) {
      expect((levels[index] ?? 0) - (levels[index - 1] ?? 0)).toBeLessThanOrEqual(1)
    }
  })

  it('cada campo del formulario tiene su etiqueta asociada, no un texto suelto', async () => {
    const wrapper = await mountMyRecords()
    const inputs = wrapper.findAll('input, select, textarea')

    expect(inputs.length).toBeGreaterThan(0)

    for (const input of inputs) {
      expect(fieldFor(wrapper, input)).toBeDefined()
    }
  })

  it('las tablas llevan titulo y encabezados asociados a filas y columnas', async () => {
    const wrapper = await mountMyRecords()
    const tables = wrapper.findAll('table')

    expect(tables.length).toBeGreaterThan(0)

    for (const table of tables) {
      expect(table.find('caption').exists()).toBe(true)
      expect(table.findAll('th').every((th) => th.attributes('scope') !== undefined)).toBe(true)
    }
  })

  it('los objetivos tactiles son de al menos 48 px (min-h-12 = 3rem)', async () => {
    const wrapper = await mountMyRecords()

    expect(wrapper.find('button[type="submit"]').classes()).toContain('min-h-12')
  })

  it('el grupo de fechas se presenta como tal, con su leyenda', async () => {
    const wrapper = await mountMyRecords()

    expect(wrapper.find('fieldset legend').text()).toBe(es.myRecords.filters.legend)
  })

  it('no repite ningun identificador, que romperia las etiquetas', async () => {
    const wrapper = await mountMyRecords(
      employeeWorkDays([workDay(), workDay({ work_date: '2026-03-15' })]),
    )

    const ids = wrapper.findAll('[id]').map((element) => element.attributes('id'))

    expect(new Set(ids).size).toBe(ids.length)
  })
})
