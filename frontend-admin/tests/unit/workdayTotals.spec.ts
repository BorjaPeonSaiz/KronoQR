// La aritmetica de la jornada, sin Vue de por medio.
//
// Es la parte que no puede equivocarse: estos minutos acaban en una nomina.
// Cubre RF-PA-03 (detalle de jornada) y RN-06 / ADR-007 (el total es una
// proyeccion recalculada, y la suma de las partes tiene que cuadrar con el).
import { describe, expect, it } from 'vitest'
import { durationParts, sumShiftMinutes, workDayTotals } from '@/features/workdays/workdayTotals'
import {
  exceedsMaxRange,
  isInvertedRange,
  MAX_RANGE_DAYS,
  rangeLengthInDays,
} from '@/features/workdays/dateRange'
import { shiftEntry, workDay } from './support/fixtures'

describe('duracion en horas y minutos', () => {
  it('no usa decimales: 485 minutos son 8 h 05 min, no 8,08 h', () => {
    expect(durationParts(485)).toEqual({ hours: 8, minutes: '05' })
  })

  it('escribe los minutos con dos digitos, que es como se leen de un vistazo', () => {
    expect(durationParts(65).minutes).toBe('05')
    expect(durationParts(70).minutes).toBe('10')
  })

  it('una jornada sin minutos es 0 h 00 min y no un hueco', () => {
    expect(durationParts(0)).toEqual({ hours: 0, minutes: '00' })
  })

  it('no inventa una duracion negativa ni una fraccionaria', () => {
    expect(durationParts(-30)).toEqual({ hours: 0, minutes: '00' })
    expect(durationParts(Number.NaN)).toEqual({ hours: 0, minutes: '00' })
  })
})

describe('suma de los tramos de una jornada', () => {
  it('suma los tramos vigentes, y las partes dan el total', () => {
    const entries = [
      shiftEntry({ uuid: 'a', duration_minutes: 245 }),
      shiftEntry({ uuid: 'b', duration_minutes: 240 }),
    ]

    expect(sumShiftMinutes(entries)).toBe(485)
    expect(workDayTotals(workDay({ shift_entries: entries, total_minutes: 485 })).agree).toBe(true)
  })

  it('un tramo abierto aporta cero: no se le inventan minutos hasta ahora', () => {
    const entries = [
      shiftEntry({ uuid: 'a', duration_minutes: 245 }),
      shiftEntry({
        uuid: 'b',
        duration_minutes: null,
        clocked_out_at: null,
        clocked_out_at_local: null,
        clocked_out_recorded_at: null,
        clock_out_source: null,
        status: 'open',
      }),
    ]

    const totals = workDayTotals(workDay({ shift_entries: entries, total_minutes: 245 }))

    expect(totals.summed).toBe(245)
    expect(totals.openEntries).toBe(1)
    expect(totals.agree).toBe(true)
  })

  it('un dia sin tramos vigentes suma cero, no falla', () => {
    const totals = workDayTotals(workDay({ shift_entries: [], total_minutes: 0 }))

    expect(totals.summed).toBe(0)
    expect(totals.agree).toBe(true)
  })

  it('detecta que el total declarado no cuadra con la suma, en vez de elegir uno', () => {
    const totals = workDayTotals(
      workDay({ shift_entries: [shiftEntry({ duration_minutes: 485 })], total_minutes: 500 }),
    )

    expect(totals.summed).toBe(485)
    expect(totals.declared).toBe(500)
    expect(totals.agree).toBe(false)
  })
})

describe('rango de jornadas pedido', () => {
  it('cuenta los dos extremos: del 1 al 31 son 31 jornadas', () => {
    expect(rangeLengthInDays({ from: '2026-03-01', to: '2026-03-31' })).toBe(31)
    expect(rangeLengthInDays({ from: '2026-03-14', to: '2026-03-14' })).toBe(1)
  })

  it('cuenta dias civiles y no se descuadra con el cambio de hora', () => {
    // El 29 de marzo de 2026 Madrid pierde una hora. Son 31 dias igual: aqui no
    // interviene ninguna zona.
    expect(rangeLengthInDays({ from: '2026-03-01', to: '2026-03-31' })).toBe(31)
  })

  it('no cuenta un rango sin fechas: lo resuelve el servidor', () => {
    expect(rangeLengthInDays({ from: '', to: '' })).toBeNull()
    expect(isInvertedRange({ from: '', to: '' })).toBe(false)
    expect(exceedsMaxRange({ from: '', to: '' })).toBe(false)
  })

  it('avisa de un periodo que termina antes de empezar, sin darle la vuelta', () => {
    expect(isInvertedRange({ from: '2026-03-31', to: '2026-03-01' })).toBe(true)
  })

  it('avisa del techo del contrato en vez de gastar la peticion', () => {
    expect(exceedsMaxRange({ from: '2026-01-01', to: '2026-12-31' })).toBe(false)
    expect(exceedsMaxRange({ from: '2025-01-01', to: '2026-12-31' })).toBe(true)
    expect(MAX_RANGE_DAYS).toBe(366)
  })
})
