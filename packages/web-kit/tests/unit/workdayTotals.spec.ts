// Movida de `frontend-admin/tests/unit/workdayTotals.spec.ts` (ADR-036). Es la
// pieza critica del paquete: cubre RF-PA-03 (detalle de jornada) y RN-06 /
// ADR-007 (el total es una proyeccion recalculada, y la suma de las partes
// tiene que cuadrar con el). Antes de compartirse, esta era la funcion cuyo
// cuerpo se habia copiado carater por caracter en `frontend-portal`.
import { describe, expect, it } from 'vitest'
import { durationParts, sumShiftMinutes, workDayTotals } from '../../src/workdayTotals'
import type { ShiftEntryDuration, WorkDayDurations } from '../../src/workdayTotals'

function shiftEntry(durationMinutes: number | null): ShiftEntryDuration {
  return { duration_minutes: durationMinutes }
}

function workDay(overrides: Partial<WorkDayDurations>): WorkDayDurations {
  return { shift_entries: [], total_minutes: 0, ...overrides }
}

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
    const entries = [shiftEntry(245), shiftEntry(240)]

    expect(sumShiftMinutes(entries)).toBe(485)
    expect(workDayTotals(workDay({ shift_entries: entries, total_minutes: 485 })).agree).toBe(true)
  })

  it('un tramo abierto aporta cero: no se le inventan minutos hasta ahora', () => {
    const entries = [shiftEntry(245), shiftEntry(null)]

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
    const totals = workDayTotals(workDay({ shift_entries: [shiftEntry(485)], total_minutes: 500 }))

    expect(totals.summed).toBe(485)
    expect(totals.declared).toBe(500)
    expect(totals.agree).toBe(false)
  })
})
