// La tabla de tramos de una jornada propia (RF-ID-05, RL-05, RN-05, regla dura 4).
//
// Dos cosas que acaban comparandose con una nomina y que no tenian ninguna
// prueba de renderizado: que cuando la suma de los tramos y el total declarado
// no coinciden la pantalla ensena los dos y avisa (PT1), y que un turno que
// cruza la medianoche es UN solo tramo atribuido a la jornada en que empezo,
// con la salida marcada como del dia siguiente (PT2).
import { describe, expect, it } from 'vitest'
import ShiftEntryTable from '@/features/my-records/ShiftEntryTable.vue'
import es from '@/shared/i18n/locales/es.json'
import type { WorkDayShiftEntry } from '@/shared/api/types'
import { shiftEntry } from './support/fixtures'
import { mountView } from './support/harness'

async function mountTable(
  entries: WorkDayShiftEntry[],
  totalMinutes: number,
  workDate = '2026-03-14',
): ReturnType<typeof mountView> {
  return mountView(ShiftEntryTable, {
    props: { entries, totalMinutes, timeZone: 'Europe/Madrid', workDate },
  })
}

/** Un tramo abierto: aporta cero a la suma. */
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

/** Turno 22:00 -> 06:00 (hora de Madrid, UTC+1): 480 min, la salida cae el dia siguiente. */
function nightShift(): WorkDayShiftEntry {
  return shiftEntry({
    clocked_in_at: '2026-03-14T21:00:00.000000Z',
    clocked_in_at_local: '2026-03-14T22:00:00.000000+01:00',
    clocked_in_recorded_at: '2026-03-14T21:00:00.000000Z',
    clocked_out_at: '2026-03-15T05:00:00.000000Z',
    clocked_out_at_local: '2026-03-15T06:00:00.000000+01:00',
    clocked_out_recorded_at: '2026-03-15T05:00:00.000000Z',
    duration_minutes: 480,
  })
}

describe('ShiftEntryTable, los tramos no cuadran con el total (RF-ID-05, RL-05, RN-06)', () => {
  it('con tramos que suman 8 h 05 min y un total declarado de 8 h 00 min, ensena los dos y avisa', async () => {
    const wrapper = await mountTable([shiftEntry()], 480)

    expect(wrapper.find('[data-test="summed-total"]').text()).toBe('8 h 05 min')
    expect(wrapper.find('[data-test="declared-total"]').text()).toBe('8 h 00 min')

    const warning = wrapper.find('[data-test="totals-mismatch"]')

    expect(warning.exists()).toBe(true)
    expect(warning.text()).toBe(es.myRecords.entries.mismatch)
  })

  it('no elige uno de los dos numeros en silencio: ninguno desaparece', async () => {
    const wrapper = await mountTable([shiftEntry()], 486)

    expect(wrapper.findAll('[data-test="summed-total"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test="declared-total"]')).toHaveLength(1)
  })

  it('con el total que cuadra, no hay total declarado aparte ni aviso', async () => {
    const wrapper = await mountTable([shiftEntry()], 485)

    expect(wrapper.find('[data-test="summed-total"]').text()).toBe('8 h 05 min')
    expect(wrapper.find('[data-test="declared-total"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="totals-mismatch"]').exists()).toBe(false)
  })

  it('un tramo abierto aporta cero a la suma: con total 0 cuadra, con otro total avisa', async () => {
    const agreeing = await mountTable([openShift()], 0)

    expect(agreeing.find('[data-test="totals-mismatch"]').exists()).toBe(false)

    const disagreeing = await mountTable([openShift()], 30)

    expect(disagreeing.find('[data-test="summed-total"]').text()).toBe('0 h 00 min')
    expect(disagreeing.find('[data-test="declared-total"]').text()).toBe('0 h 30 min')
    expect(disagreeing.find('[data-test="totals-mismatch"]').exists()).toBe(true)
  })
})

describe('ShiftEntryTable, el turno que cruza la medianoche (RF-ID-05, RN-05, regla dura 4)', () => {
  it('es un unico tramo, no dos, con su duracion entera', async () => {
    const wrapper = await mountTable([nightShift()], 480)

    expect(wrapper.findAll('[data-test="entry-duration"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="entry-duration"]').text()).toBe('8 h 00 min')
    expect(wrapper.find('[data-test="summed-total"]').text()).toBe('8 h 00 min')
    expect(wrapper.find('[data-test="totals-mismatch"]').exists()).toBe(false)
  })

  it('deja la entrada en la jornada de inicio y marca la salida como del dia siguiente', async () => {
    const wrapper = await mountTable([nightShift()], 480, '2026-03-14')
    const cells = wrapper.findAll('tbody tr:first-child > *')

    expect(cells[0]?.text()).toContain('22:00')
    expect(cells[0]?.text()).not.toContain(es.myRecords.entries.nextDay)
    expect(cells[1]?.text()).toContain('06:00')
    expect(cells[1]?.text()).toContain(es.myRecords.entries.nextDay)
  })

  it('un turno que empieza y acaba el mismo dia no lleva la marca de dia siguiente', async () => {
    const wrapper = await mountTable([shiftEntry()], 485)

    expect(wrapper.text()).not.toContain(es.myRecords.entries.nextDay)
  })

  it('la hora que se lee es la local del centro ya resuelta: el navegador no la reconvierte', async () => {
    // 21:00Z son las 22:00 en Madrid (UTC+1) antes del cambio de hora, y esa es
    // la que llega resuelta; la zona del dispositivo no interviene.
    const wrapper = await mountTable([nightShift()], 480)

    expect(wrapper.text()).toContain('22:00')
    expect(wrapper.text()).not.toContain('21:00')
  })
})
