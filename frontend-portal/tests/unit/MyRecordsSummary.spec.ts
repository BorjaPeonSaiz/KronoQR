// El resumen de arriba de «Mi registro» (RF-ID-05, RL-05, PO7-01).
//
// Total del periodo = suma de los `total_minutes` del servidor, en horas y
// minutos y nunca en decimal; la jornada de hoy fijada arriba con el «hoy» que
// da el servidor (regla dura 3); y un aviso explicito cuando el numero todavia
// puede cambiar.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MyRecordsView from '@/features/my-records/MyRecordsView.vue'
import PeriodSummary from '@/features/my-records/PeriodSummary.vue'
import { useSessionStore } from '@/features/login/session.store'
import es from '@/shared/i18n/locales/es.json'
import type { EmployeeWorkDays, WorkDayDetail, WorkDayShiftEntry } from '@/shared/api/types'
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { employeeWorkDays, portalEmployee, shiftEntry, workDay } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubFetch } from './support/harness'

const TODAY = '2026-03-16'
const PAST = { from: '2026-02-14', to: TODAY }

function entryOfMinutes(minutes: number, date: string): WorkDayShiftEntry {
  return shiftEntry({
    uuid: `0199f2c1-8a10-7b40-9c50-6d7e8f9a${date.slice(-2)}00`,
    clocked_in_at: `${date}T06:00:00.000000Z`,
    clocked_in_at_local: `${date}T07:00:00.000000+01:00`,
    clocked_out_at: `${date}T12:00:00.000000Z`,
    clocked_out_at_local: `${date}T13:00:00.000000+01:00`,
    duration_minutes: minutes,
  })
}

function dayOfMinutes(minutes: number, date: string): WorkDayDetail {
  return workDay({ work_date: date, shift_entries: [entryOfMinutes(minutes, date)] })
}

function openShift(): WorkDayShiftEntry {
  return shiftEntry({
    status: 'open',
    clocked_out_at: null,
    clocked_out_at_local: null,
    clocked_out_recorded_at: null,
    clock_out_source: null,
    duration_minutes: null,
  })
}

async function mountRecords(workdays: EmployeeWorkDays): Promise<ReturnType<typeof mountView>> {
  const pinia = createTestPinia()

  useSessionStore(pinia).employee = portalEmployee()
  stubFetch(() => jsonResponse(workdays))

  const wrapper = await mountView(MyRecordsView, { pinia })

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

describe('MyRecordsView, resumen de arriba (RF-ID-05, RL-05)', () => {
  it('suma los totales del periodo en horas y minutos, sin decimales', async () => {
    const wrapper = await mountRecords(
      employeeWorkDays([dayOfMinutes(485, '2026-03-14'), dayOfMinutes(455, '2026-03-15')]),
    )

    // 485 + 455 = 940 min = 15 h 40 min; nunca «15,67 h».
    expect(wrapper.find('[data-test="period-total"]').text()).toBe('15 h 40 min')
    expect(wrapper.find('[data-test="period-total"]').text()).not.toMatch(/[.,]\d/)
    expect(wrapper.find('[data-test="period-days"]').text()).toContain('2')
  })

  it('el resumen va antes que las jornadas y estas siguen en el orden del contrato', async () => {
    const wrapper = await mountRecords(
      employeeWorkDays([dayOfMinutes(485, '2026-03-14'), dayOfMinutes(455, '2026-03-15')]),
    )
    const summary = wrapper.find('[data-test="period-summary"]').element
    const cards = wrapper.findAll('[data-test="workday"]')

    expect(cards).toHaveLength(2)
    expect(
      summary.compareDocumentPosition(cards[0]?.element as Element) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    // Ascendente, tal cual lo da el servidor.
    expect(cards[0]?.text()).toContain('14')
    expect(cards[1]?.text()).toContain('15')
  })

  it('fija arriba la jornada de hoy, con el «hoy» que resuelve el servidor', async () => {
    const wrapper = await mountRecords(
      employeeWorkDays([dayOfMinutes(485, '2026-03-14'), dayOfMinutes(300, TODAY)], PAST),
    )

    expect(wrapper.find('[data-test="today-total"]').text()).toBe('5 h 00 min')
  })

  it('si hoy no hay fichajes lo dice, en vez de omitirlo', async () => {
    const wrapper = await mountRecords(employeeWorkDays([dayOfMinutes(485, '2026-03-14')], PAST))

    expect(wrapper.find('[data-test="today-total"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="today-empty"]').text()).toBe(es.myRecords.summary.todayEmpty)
  })

  it('un turno abierto hoy dice con palabras que el total va a subir', async () => {
    const wrapper = await mountRecords(
      employeeWorkDays(
        [workDay({ work_date: TODAY, shift_entries: [openShift()], total_minutes: 0 })],
        PAST,
      ),
    )

    expect(wrapper.find('[data-test="today-open"]').text()).toContain(
      es.myRecords.day.flags.openShift,
    )
    expect(wrapper.find('[data-test="period-provisional"]').exists()).toBe(true)
  })

  it('avisa de que el total es provisional con una incidencia o con un dia que no cuadra', async () => {
    const wrapper = await mountRecords(
      employeeWorkDays([
        dayOfMinutes(485, '2026-03-14'),
        workDay({
          work_date: '2026-03-15',
          shift_entries: [entryOfMinutes(485, '2026-03-15')],
          total_minutes: 480,
        }),
        workDay({
          work_date: '2026-03-16',
          shift_entries: [entryOfMinutes(60, '2026-03-16')],
          has_incident: true,
        }),
      ]),
    )

    expect(wrapper.find('[data-test="period-provisional"]').text()).toContain('2')
  })

  it('con todo cerrado y cuadrado no avisa de nada', async () => {
    const wrapper = await mountRecords(employeeWorkDays([dayOfMinutes(485, '2026-03-14')]))

    expect(wrapper.find('[data-test="period-provisional"]').exists()).toBe(false)
  })

  it('un periodo vacio enseña 0 h 00 min y no oculta el resumen', async () => {
    const wrapper = await mountRecords(employeeWorkDays([], PAST))

    expect(wrapper.find('[data-test="period-total"]').text()).toBe('0 h 00 min')
  })
})

describe('PeriodSummary, el «hoy» no se inventa (regla dura 3)', () => {
  function mountSummary(today: string | null): ReturnType<typeof mountView> {
    return mountView(PeriodSummary, {
      props: { days: [dayOfMinutes(485, '2026-03-14')], from: PAST.from, to: PAST.to, today },
    })
  }

  it('sin un «hoy» del servidor no enseña el bloque de hoy', async () => {
    const wrapper = await mountSummary(null)

    expect(wrapper.find('[data-test="today-summary"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="period-total"]').text()).toBe('8 h 05 min')
  })

  it('si el periodo consultado no incluye hoy, no enseña el bloque de hoy', async () => {
    const wrapper = await mountView(PeriodSummary, {
      props: { days: [], from: '2026-01-01', to: '2026-01-31', today: TODAY },
    })

    expect(wrapper.find('[data-test="today-summary"]').exists()).toBe(false)
  })
})
