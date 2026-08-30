// Pantalla de detalle de jornada (RF-PA-03).
//
// Es la primera pantalla del panel desde la que alguien lee el registro horario
// de OTRA persona, asi que lo que se comprueba aqui es tanto lo que enseña como
// lo que NO enseña: el rango lo resuelve el servidor (nunca el reloj del
// navegador), la zona se dice, el acceso se declara auditado y de la ficha solo
// sale el nombre y el codigo.
import type { DOMWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EmployeeWorkDaysView from '@/features/workdays/EmployeeWorkDaysView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import type { EmployeeWorkDays } from '@/shared/api/types'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import {
  EMPLOYEE_UUID,
  employee,
  employeeWorkDays,
  managementUser,
  shiftEntry,
  workDay,
} from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

type Wrapper = Awaited<ReturnType<typeof mountView>>

interface MountOptions {
  workdays?: EmployeeWorkDays
  /** Respuesta de la ficha. `false` = la ficha no esta al alcance de este rol. */
  employeeAccessible?: boolean
  /** Ambitos del token. Por omision, ninguno: sin sesion no se enseña el enlace a la bandeja. */
  abilities?: string[]
}

let requestedUrls: string[] = []

async function mountWorkDays(options: MountOptions = {}): Promise<Wrapper> {
  const payload = options.workdays ?? employeeWorkDays()

  stubFetch((url) => {
    requestedUrls.push(url)

    if (url.includes('/workdays')) {
      return jsonResponse(payload)
    }

    return options.employeeAccessible === false
      ? problemResponse(403, 'urn:kronoqr:problem:forbidden')
      : jsonResponse(employee())
  })

  const pinia = createTestPinia()

  if (options.abilities !== undefined) {
    const session = useSessionStore(pinia)

    session.token = 'token'
    session.status = 'authenticated'
    session.user = managementUser({ abilities: options.abilities })
  }

  const wrapper = await mountView(EmployeeWorkDaysView, { props: { uuid: EMPLOYEE_UUID }, pinia })

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

describe('EmployeeWorkDaysView', () => {
  it('enseña las jornadas del periodo con su total en horas y minutos', async () => {
    const wrapper = await mountWorkDays()

    expect(wrapper.findAll('[data-test="workday"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="day-total"]').text()).toBe('8 h 05 min')
  })

  it('no pide un periodo: deja que lo resuelva el servidor, que sabe que dia es en el centro', async () => {
    await mountWorkDays()

    const workdaysUrl = requestedUrls.find((url) => url.includes('/workdays')) ?? ''

    expect(workdaysUrl).toBe(`/api/v1/employees/${EMPLOYEE_UUID}/workdays`)
  })

  it('dice que periodo se esta viendo y en que zona horaria', async () => {
    const wrapper = await mountWorkDays()

    expect(wrapper.find('[data-test="resolved-range"]').text()).toContain('2026-03-14')
    expect(wrapper.find('[data-test="resolved-range"]').text()).toContain('Europe/Madrid')
    expect(wrapper.text()).toContain(es.workdays.zoneNotice)
  })

  it('rellena el formulario con el rango que ha resuelto el servidor', async () => {
    const wrapper = await mountWorkDays()
    const inputs = wrapper.findAll('input[type="date"]')

    expect((inputs[0]?.element as HTMLInputElement).value).toBe('2026-03-14')
    expect((inputs[1]?.element as HTMLInputElement).value).toBe('2026-03-14')
  })

  it('consulta el rango que se le pide, y lo pide al servidor', async () => {
    const wrapper = await mountWorkDays()
    const inputs = wrapper.findAll('input[type="date"]')

    await inputs[0]?.setValue('2026-03-01')
    await inputs[1]?.setValue('2026-03-31')
    await wrapper.find('form').trigger('submit')
    await settle()

    const lastWorkDaysUrl = requestedUrls.filter((url) => url.includes('/workdays')).at(-1)

    expect(lastWorkDaysUrl).toContain('from=2026-03-01')
    expect(lastWorkDaysUrl).toContain('to=2026-03-31')
  })

  it('no consulta un periodo que termina antes de empezar: lo dice y no deja enviar', async () => {
    const wrapper = await mountWorkDays()
    const inputs = wrapper.findAll('input[type="date"]')
    const before = requestedUrls.length

    await inputs[0]?.setValue('2026-03-31')
    await inputs[1]?.setValue('2026-03-01')
    await settle(1)

    expect(wrapper.text()).toContain(es.workdays.filters.inverted)
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('form').trigger('submit')
    await settle(1)

    expect(requestedUrls).toHaveLength(before)
  })

  it('avisa del techo de 366 dias antes de gastar la peticion', async () => {
    const wrapper = await mountWorkDays()
    const inputs = wrapper.findAll('input[type="date"]')

    await inputs[0]?.setValue('2024-01-01')
    await inputs[1]?.setValue('2026-12-31')
    await settle(1)

    expect(wrapper.text()).toContain('366')
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('un periodo sin jornadas se explica, en vez de parecer un fallo', async () => {
    const wrapper = await mountWorkDays({
      workdays: employeeWorkDays([], { from: '2026-04-01', to: '2026-04-30' }),
    })

    expect(wrapper.text()).toContain(es.workdays.empty.title)
    expect(wrapper.text()).toContain(es.workdays.empty.description)
  })

  it('un dia cuyos tramos se anularon sigue apareciendo con su historico', async () => {
    const wrapper = await mountWorkDays({
      workdays: employeeWorkDays([
        workDay({
          shift_entries: [],
          total_minutes: 0,
          shift_count: 0,
        }),
      ]),
    })

    expect(wrapper.findAll('[data-test="workday"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="entries-empty"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="correction"]')).toHaveLength(1)
  })

  it('marca el turno abierto y la incidencia con palabras, no solo con color', async () => {
    const wrapper = await mountWorkDays({
      workdays: employeeWorkDays([
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
    })

    expect(wrapper.find('[data-test="flag-open-shift"]').text()).toBe(
      es.workdays.day.flags.openShift,
    )
    expect(wrapper.find('[data-test="flag-incident"]').text()).toBe(es.workdays.day.flags.incident)
    expect(wrapper.text()).toContain(es.workdays.day.flags.openShiftHint)
  })

  it('sin incidencias en la jornada, lo dice en vez de dejar el hueco en blanco', async () => {
    const wrapper = await mountWorkDays()

    expect(wrapper.find('[data-test="workday-incidents"]').text()).toContain(
      es.incidents.workday.none,
    )
    expect(wrapper.find('[data-test="workday-incident-badge"]').exists()).toBe(false)
  })

  it('una incidencia de la jornada se marca con su tipo y su estado (RF-PA-05)', async () => {
    const wrapper = await mountWorkDays({
      workdays: employeeWorkDays([
        workDay({
          incidents: [{ id: 412, type: 'insufficient_rest', severity: 'high', status: 'open' }],
        }),
      ]),
    })

    const badge = wrapper.find('[data-test="workday-incident-badge"]')

    expect(badge.text()).toContain(es.incidents.types.insufficient_rest)
    expect(badge.text()).toContain(es.incidents.status.open)
  })

  it('el enlace a la bandeja solo aparece con el ambito de incidencias (regla dura 18)', async () => {
    const withIncident = employeeWorkDays([
      workDay({
        incidents: [{ id: 412, type: 'insufficient_rest', severity: 'high', status: 'open' }],
      }),
    ])

    const withoutAbility = await mountWorkDays({ workdays: withIncident })

    expect(
      withoutAbility.findAll('a').some((link) => link.text() === es.incidents.workday.linkToInbox),
    ).toBe(false)

    const withAbility = await mountWorkDays({
      workdays: withIncident,
      abilities: ['incidents:*'],
    })

    const link = withAbility
      .findAll('a')
      .find((candidate) => candidate.text() === es.incidents.workday.linkToInbox)

    expect(link).toBeDefined()
    expect(link?.attributes('href')).toContain(`employee=${EMPLOYEE_UUID}`)
  })

  it('anuncia cuantas jornadas se han encontrado, sin mover el foco', async () => {
    await mountWorkDays()

    expect(announcement.value).toContain('1')
    expect(announcement.value).toContain('2026-03-14')
  })

  it('avisa de que consultar el registro de otra persona queda en la auditoria', async () => {
    const wrapper = await mountWorkDays()

    expect(wrapper.text()).toContain(es.workdays.auditNotice)
  })

  it('de la ficha solo saca el nombre y el codigo, nada mas', async () => {
    const wrapper = await mountWorkDays()
    const text = wrapper.text()

    expect(text).toContain('Youssef Amrani')
    expect(text).toContain('E7QK2MXPR')
    // Ni estado del PIN, ni fecha de alta, ni correo: para leer unas horas no
    // hace falta nada de eso.
    expect(text).not.toContain(es.pin.field)
    expect(text).not.toContain(es.employees.fields.hiredAt)
  })

  it('si la ficha no esta al alcance del rol, enseña el identificador y sigue funcionando', async () => {
    const wrapper = await mountWorkDays({ employeeAccessible: false })

    expect(wrapper.find('[data-test="person"]').text()).toContain(EMPLOYEE_UUID)
    expect(wrapper.findAll('[data-test="workday"]')).toHaveLength(1)
  })

  it('cuenta que ha pasado y que hacer si el registro no se puede cargar', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const wrapper = await mountView(EmployeeWorkDaysView, { props: { uuid: EMPLOYEE_UUID } })

    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.advice)
  })
})

describe('EmployeeWorkDaysView, accesibilidad (WCAG 2.2 AA)', () => {
  it('tiene un solo titulo de nivel 1 y no salta niveles de encabezado', async () => {
    const wrapper = await mountWorkDays()
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
    const wrapper = await mountWorkDays()
    const inputs = wrapper.findAll('input, select, textarea')

    expect(inputs.length).toBeGreaterThan(0)

    for (const input of inputs) {
      expect(fieldFor(wrapper, input)).toBeDefined()
    }
  })

  it('la pista de cada campo se anuncia con el campo, por aria-describedby', async () => {
    const wrapper = await mountWorkDays()

    for (const input of wrapper.findAll('input[type="date"]')) {
      const describedBy = input.attributes('aria-describedby')

      expect(describedBy).toBeDefined()
      expect(wrapper.find(`#${describedBy?.split(' ')[0]}`).exists()).toBe(true)
    }
  })

  it('no repite ningun identificador, que romperia las etiquetas', async () => {
    const wrapper = await mountWorkDays({
      workdays: employeeWorkDays([workDay(), workDay({ work_date: '2026-03-15' })]),
    })

    const ids = wrapper.findAll('[id]').map((element) => element.attributes('id'))

    expect(new Set(ids).size).toBe(ids.length)
  })

  it('las tablas llevan titulo y encabezados asociados a filas y columnas', async () => {
    const wrapper = await mountWorkDays()
    const tables = wrapper.findAll('table')

    expect(tables.length).toBeGreaterThan(0)

    for (const table of tables) {
      expect(table.find('caption').exists()).toBe(true)
      expect(table.findAll('th').every((th) => th.attributes('scope') !== undefined)).toBe(true)
    }
  })

  it('se navega con teclado: los controles son botones y enlaces de verdad', async () => {
    const wrapper = await mountWorkDays()

    expect(wrapper.find('button[type="submit"]').exists()).toBe(true)
    expect(wrapper.findAll('a').every((link) => link.text().trim().length > 0)).toBe(true)
    expect(wrapper.findAll('button').every((button) => button.text().trim().length > 0)).toBe(true)
  })

  it('el grupo de fechas se presenta como tal, con su leyenda', async () => {
    const wrapper = await mountWorkDays()

    expect(wrapper.find('fieldset legend').text()).toBe(es.workdays.filters.legend)
  })
})
