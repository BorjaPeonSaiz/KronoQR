// Bordes de la tabla de tramos y del historial (R2-PO-04, V21-2-PO-1, V21-2-PO-2,
// V2-PO-1): hora de recepcion, fichaje en cola, pausa doble, tramo corregido,
// cambio de hora (DST) y marcas con milisegundos.
//
// El proceso de pruebas corre en Pacific/Auckland (vitest.config.ts): lo que
// aqui sale en hora de Madrid sale de la zona que viaja en los datos, no de la
// del navegador.
import { describe, expect, it } from 'vitest'
import CorrectionHistory from '@/features/my-records/CorrectionHistory.vue'
import ShiftEntryTable from '@/features/my-records/ShiftEntryTable.vue'
import type { WorkDayShiftEntry } from '@/shared/api/types'
import es from '@/shared/i18n/locales/es.json'
import { correction, shiftEntry, shiftEntriesWithBreak } from './support/fixtures'
import { mountView } from './support/harness'

function mountTable(entries: WorkDayShiftEntry[], totalMinutes: number, workDate = '2026-03-14') {
  return mountView(ShiftEntryTable, {
    props: { entries, totalMinutes, timeZone: 'Europe/Madrid', workDate },
  })
}

describe('hora de recepcion (regla dura 9)', () => {
  it('un fichaje de la cola enseña cuando lo recibio el servidor, en la hora del centro', async () => {
    const wrapper = await mountTable(
      [
        shiftEntry({
          clocked_in_at: '2026-03-14T05:00:00.000000Z',
          clocked_in_at_local: '2026-03-14T06:00:00.000000+01:00',
          // Con fracciones de segundo: se descartan, no redondean.
          clocked_in_recorded_at: '2026-03-14T05:12:30.900000Z',
          clocked_out_recorded_at: null,
        }),
      ],
      485,
    )

    const text = wrapper.text()

    expect(text).toContain('06:00')
    expect(text).toContain('Recibida 06:12, 0 h 12 min después de fichar')
  })

  it('si la recepcion cae otro dia en el centro, lleva dia y mes', async () => {
    const wrapper = await mountTable(
      [
        shiftEntry({
          clocked_out_at: '2026-03-14T22:50:00.000000Z',
          clocked_out_at_local: '2026-03-14T23:50:00.000000+01:00',
          clocked_out_recorded_at: '2026-03-14T23:20:00.000000Z',
          duration_minutes: 1070,
        }),
      ],
      1070,
    )

    expect(wrapper.text()).toMatch(/Recibida 15\/0?3, 00:20, 0 h 30 min/)
  })

  it('en ingles la fecha va con el orden del idioma y la hora en 24 h, sin AM/PM', async () => {
    const wrapper = await mountView(ShiftEntryTable, {
      locale: 'en',
      props: {
        entries: [
          shiftEntry({
            clocked_out_at: '2026-03-14T22:50:00.000000Z',
            clocked_out_at_local: '2026-03-14T23:50:00.000000+01:00',
            clocked_out_recorded_at: '2026-03-14T23:20:00.000000Z',
            duration_minutes: 1070,
          }),
        ],
        totalMinutes: 1070,
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
      },
    })

    expect(wrapper.text()).toMatch(/Received 0?3\/15, 00:20/)
    expect(wrapper.text()).not.toMatch(/AM|PM/)
  })

  it('sin una zona valida no pinta la hora de recepcion (nunca la del navegador)', async () => {
    const wrapper = await mountView(ShiftEntryTable, {
      props: {
        entries: [
          shiftEntry({
            clocked_in_recorded_at: '2026-03-14T05:12:30.900000Z',
            clocked_out_recorded_at: null,
          }),
        ],
        totalMinutes: 485,
        timeZone: 'No/Existe',
        workDate: '2026-03-14',
      },
    })

    expect(wrapper.text()).not.toContain('Recibida')
  })

  it('un fichaje que llega al momento, aunque sea por unos milisegundos, no lleva aviso', async () => {
    const wrapper = await mountTable([shiftEntry()], 485)

    expect(wrapper.text()).not.toContain('El servidor la recibió')
  })

  it('un tramo corregido por RRHH (sin marca de recepcion en la salida) no inventa una', async () => {
    const wrapper = await mountTable(
      [shiftEntry({ clocked_out_recorded_at: null, clock_out_source: 'manual_admin' })],
      485,
    )

    expect(wrapper.text()).toContain(es.myRecords.sources.manual_admin)
    expect(wrapper.text()).not.toContain('El servidor la recibió')
  })
})

describe('pausa doble', () => {
  it('dos pausas en un dia dan dos filas y la suma de los tramos cuadra con el total', async () => {
    const [morning, afternoon] = shiftEntriesWithBreak()

    if (morning === undefined || afternoon === undefined) {
      throw new Error('fixture')
    }

    // Se parte la tarde en dos tramos con una segunda pausa de 15 min
    // (13:30Z-13:45Z -> 14:30 a 14:45 locales).
    const firstAfternoon: WorkDayShiftEntry = {
      ...afternoon,
      uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b23',
      clocked_out_at: '2026-03-14T13:30:00.000000Z',
      clocked_out_at_local: '2026-03-14T14:30:00.000000+01:00',
      clocked_out_recorded_at: '2026-03-14T13:30:00.000000Z',
      closed_by: 'break_start',
      duration_minutes: 120,
    }
    const lastAfternoon: WorkDayShiftEntry = {
      ...afternoon,
      uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b24',
      clocked_in_at: '2026-03-14T13:45:00.000000Z',
      clocked_in_at_local: '2026-03-14T14:45:00.000000+01:00',
      clocked_in_recorded_at: '2026-03-14T13:45:00.000000Z',
      opened_by: 'break_end',
      duration_minutes: 75,
    }

    const wrapper = await mountTable([morning, firstAfternoon, lastAfternoon], 360 + 120 + 75)
    const rows = wrapper.findAll('[data-test="break-row"]')

    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('Pausa de 12:00 a 12:30 (0 h 30 min)')
    expect(rows[1]?.text()).toContain('Pausa de 14:30 a 14:45 (0 h 15 min)')
    expect(wrapper.find('[data-test="summed-total"]').text()).toBe('9 h 15 min')
    expect(wrapper.find('[data-test="totals-mismatch"]').exists()).toBe(false)
  })
})

describe('cambio de hora (DST)', () => {
  // Madrid pasa de UTC+1 a UTC+2 el 29 de marzo de 2026 a las 02:00 locales.
  function nightOfTheClockChange(): WorkDayShiftEntry {
    return shiftEntry({
      clocked_in_at: '2026-03-28T21:00:00.000000Z',
      clocked_in_at_local: '2026-03-28T22:00:00.000000+01:00',
      clocked_in_recorded_at: '2026-03-28T21:00:01.250000Z',
      clocked_out_at: '2026-03-29T05:00:00.000000Z',
      clocked_out_at_local: '2026-03-29T07:00:00.000000+02:00',
      clocked_out_recorded_at: '2026-03-29T05:20:00.000000Z',
      duration_minutes: 480,
    })
  }

  it('el turno de la noche del cambio es UN tramo de 8 h aunque el reloj salte una hora', async () => {
    const wrapper = await mountTable([nightOfTheClockChange()], 480, '2026-03-28')

    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('22:00')
    expect(wrapper.text()).toContain('07:00')
    expect(wrapper.text()).toContain(es.myRecords.entries.nextDay)
    expect(wrapper.find('[data-test="entry-duration"]').text()).toBe('8 h 00 min')
    expect(wrapper.find('[data-test="summed-total"]').text()).toBe('8 h 00 min')
  })

  it('la recepcion de esa noche se lee con el desfase de verano (+02:00), no con el de invierno', async () => {
    const wrapper = await mountTable([nightOfTheClockChange()], 480, '2026-03-28')

    // 05:20Z = 07:20 en Madrid ya en horario de verano.
    expect(wrapper.text()).toContain('Recibida 07:20')
  })

  it('el historial convierte con la zona del centro a ambos lados del salto', async () => {
    const wrapper = await mountView(CorrectionHistory, {
      props: {
        timeZone: 'Europe/Madrid',
        corrections: [
          correction({
            performed_at: '2026-03-29T01:30:00.000000Z',
            performed_at_local: '2026-03-29T03:30:00.000000+02:00',
            before: {
              version: 1,
              clocked_in_at: '2026-03-29T00:30:00.000000Z',
              clocked_out_at: '2026-03-29T01:30:00.000000Z',
              closed_by: 'clock_out',
              worked_minutes: 60,
            },
            after: {
              version: 2,
              clocked_in_at: '2026-03-29T00:30:00.000000Z',
              clocked_out_at: '2026-03-29T03:30:00.000000Z',
              closed_by: 'clock_out',
              worked_minutes: 180,
            },
          }),
        ],
      },
    })
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    // 00:30Z = 01:30 (+01), 01:30Z = 03:30 (+02, la hora 02:xx no existe), 03:30Z = 05:30.
    expect(rows[0]).toContain('1:30')
    expect(rows[0]).toContain('3:30')
    expect(rows[1]).toContain('1:30')
    expect(rows[1]).toContain('5:30')
    expect(wrapper.text()).toContain('03:30')
  })
})
