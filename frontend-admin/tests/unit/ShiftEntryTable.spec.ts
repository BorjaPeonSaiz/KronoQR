// Tabla de tramos y total de la jornada (RF-PA-03).
//
// Lo que se comprueba aqui no es que la tabla se pinte: es que las partes sumen
// el total (RN-06), que un tramo abierto no invente minutos, que la hora que se
// enseña sea la que resolvio el servidor y no una convertida en el navegador
// (regla dura 3), que se vean las DOS marcas de cada fichaje (regla dura 9) y
// que un turno de noche siga siendo un solo tramo (regla dura 4).
import { describe, expect, it } from 'vitest'
import ShiftEntryTable from '@/features/workdays/ShiftEntryTable.vue'
import es from '@/shared/i18n/locales/es.json'
import type { WorkDayShiftEntry } from '@/shared/api/types'
import { shiftEntry } from './support/fixtures'
import { mountView } from './support/harness'

function mountTable(
  entries: WorkDayShiftEntry[],
  totalMinutes: number,
  workDate = '2026-03-14',
): ReturnType<typeof mountView> {
  return mountView(ShiftEntryTable, {
    props: { entries, totalMinutes, timeZone: 'Europe/Madrid', workDate },
  })
}

const openEntry = (overrides: Partial<WorkDayShiftEntry> = {}): WorkDayShiftEntry =>
  shiftEntry({
    status: 'open',
    clocked_out_at: null,
    clocked_out_at_local: null,
    clocked_out_recorded_at: null,
    clock_out_source: null,
    duration_minutes: null,
    ...overrides,
  })

describe('ShiftEntryTable', () => {
  it('suma los tramos y el pie da exactamente el total del dia', async () => {
    const wrapper = await mountTable(
      [
        shiftEntry({ uuid: 'a', duration_minutes: 245 }),
        shiftEntry({ uuid: 'b', duration_minutes: 240 }),
      ],
      485,
    )

    expect(wrapper.find('[data-test="summed-total"]').text()).toBe('8 h 05 min')
    expect(wrapper.find('[data-test="totals-mismatch"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="declared-total"]').exists()).toBe(false)
  })

  it('nunca escribe una duracion en decimal', async () => {
    const wrapper = await mountTable([shiftEntry({ duration_minutes: 485 })], 485)

    expect(wrapper.text()).not.toContain('8,08')
    expect(wrapper.text()).not.toContain('8.08')
  })

  it('un tramo abierto aporta cero al total y se dice que sigue en curso', async () => {
    const wrapper = await mountTable(
      [shiftEntry({ uuid: 'a', duration_minutes: 245 }), openEntry({ uuid: 'b' })],
      245,
    )

    expect(wrapper.find('[data-test="summed-total"]').text()).toBe('4 h 05 min')
    expect(wrapper.text()).toContain(es.workdays.entries.openDuration)
    expect(wrapper.find('[data-test="totals-mismatch"]').exists()).toBe(false)
  })

  it('cuando el total del dia no cuadra con la suma, enseña los dos y avisa', async () => {
    const wrapper = await mountTable([shiftEntry({ duration_minutes: 485 })], 500)

    expect(wrapper.find('[data-test="summed-total"]').text()).toBe('8 h 05 min')
    expect(wrapper.find('[data-test="declared-total"]').text()).toBe('8 h 20 min')
    expect(wrapper.find('[data-test="totals-mismatch"]').text()).toBe(es.workdays.entries.mismatch)
  })

  it('enseña la hora local que resolvio el servidor, no una convertida aqui', async () => {
    const wrapper = await mountTable([shiftEntry()], 485)
    const text = wrapper.text()

    expect(text).toContain('06:00')
    expect(text).toContain('14:05')
    // Y ademas la marca en UTC, que es como esta almacenada (regla dura 9).
    expect(text).toContain('UTC 05:00')
    expect(text).toContain('UTC 13:05')
  })

  it('dice cuando el servidor recibio el fichaje, no solo cuando se ficho', async () => {
    const wrapper = await mountTable([shiftEntry()], 485)

    // Entrada: llego en el acto, y aun asi se enseña la marca de recepcion.
    expect(wrapper.text()).toContain('Recibido en el servidor')
    // Salida: la escribio una persona, no un escaneo, y se dice.
    expect(wrapper.text()).toContain(es.workdays.entries.notRecorded)
  })

  it('explica el fichaje que llego desde la cola del quiosco, con su retraso', async () => {
    const wrapper = await mountTable(
      [
        shiftEntry({
          clocked_in_at: '2026-03-14T05:00:00.000000Z',
          clocked_in_recorded_at: '2026-03-14T07:13:00.000000Z',
        }),
      ],
      485,
    )

    expect(wrapper.text()).toContain('Llegó desde la cola del quiosco')
    expect(wrapper.text()).toContain('2 h 13 min')
  })

  it('un turno de noche es UN tramo, con la salida marcada como del dia siguiente', async () => {
    const wrapper = await mountTable(
      [
        shiftEntry({
          clocked_in_at: '2026-03-14T21:00:00.000000Z',
          clocked_in_at_local: '2026-03-14T22:00:00.000000+01:00',
          clocked_out_at: '2026-03-15T05:00:00.000000Z',
          clocked_out_at_local: '2026-03-15T06:00:00.000000+01:00',
          duration_minutes: 480,
        }),
      ],
      480,
    )

    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('22:00')
    expect(wrapper.text()).toContain('06:00')
    expect(wrapper.text()).toContain(es.workdays.entries.nextDay)
  })

  it('señala el tramo fichado en un centro con otra zona horaria', async () => {
    const wrapper = await mountTable([shiftEntry({ time_zone: 'Atlantic/Canary' })], 485)

    expect(wrapper.text()).toContain('Atlantic/Canary')
  })

  it('un dia sin tramos vigentes se explica, en vez de dejar una tabla vacia', async () => {
    const wrapper = await mountTable([], 0)

    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.find('[data-test="entries-empty"]').text()).toBe(es.workdays.entries.empty)
  })

  it('cada fila lleva su encabezado asociado, para que se lea como una fila', async () => {
    const wrapper = await mountTable([shiftEntry()], 485)

    expect(wrapper.find('caption').exists()).toBe(true)
    expect(wrapper.findAll('thead th').every((th) => th.attributes('scope') === 'col')).toBe(true)
    expect(wrapper.findAll('tbody th').every((th) => th.attributes('scope') === 'row')).toBe(true)
    expect(wrapper.findAll('tfoot th').every((th) => th.attributes('scope') === 'row')).toBe(true)
  })
})
