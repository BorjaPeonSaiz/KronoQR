// Movida de `frontend-admin/tests/unit/workdayTotals.spec.ts` (ADR-036). Es la
// pieza critica del paquete: cubre RF-PA-03 (detalle de jornada) y RN-06 /
// ADR-007 (el total es una proyeccion recalculada, y la suma de las partes
// tiene que cuadrar con el). Antes de compartirse, esta era la funcion cuyo
// cuerpo se habia copiado carater por caracter en `frontend-portal`.
import { describe, expect, it } from 'vitest'
import {
  durationParts,
  QUEUE_NOTICE_MINUTES,
  shiftMark,
  sumShiftMinutes,
  workDayTotals,
} from '../../src/workdayTotals'
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

// La marca de un tramo que leen igual el panel y el portal (C1): antes cada
// `ShiftEntryTable.vue` tenia su `mark()` y su `QUEUE_NOTICE_MINUTES`.
describe('marca de un tramo', () => {
  it('lee la hora local tal cual, sin convertir', () => {
    const mark = shiftMark(
      '2026-03-14T05:00:00.000000Z',
      '2026-03-14T06:00:00.000000+01:00',
      '2026-03-14T05:00:02.113000Z',
      '2026-03-14',
    )

    expect(mark).toEqual({
      local: '06:00',
      date: '2026-03-14',
      nextDay: false,
      queueDelayMinutes: null,
    })
  })

  it('marca la salida de un turno de noche en el dia siguiente, sin partirlo', () => {
    const mark = shiftMark(
      '2026-03-15T05:00:00.000000Z',
      '2026-03-15T06:00:00.000000+01:00',
      null,
      '2026-03-14',
    )

    expect(mark.nextDay).toBe(true)
    expect(mark.date).toBe('2026-03-15')
  })

  it('avisa de la cola a partir del umbral, no antes', () => {
    const at = '2026-03-14T05:00:00.000000Z'
    const local = '2026-03-14T06:00:00.000000+01:00'

    expect(QUEUE_NOTICE_MINUTES).toBe(5)
    expect(
      shiftMark(at, local, '2026-03-14T05:04:59.999000Z', '2026-03-14').queueDelayMinutes,
    ).toBeNull()
    expect(
      shiftMark(at, local, '2026-03-14T05:05:00.000000Z', '2026-03-14').queueDelayMinutes,
    ).toBe(5)
    expect(
      shiftMark(at, local, '2026-03-14T07:13:00.000000Z', '2026-03-14').queueDelayMinutes,
    ).toBe(133)
  })

  it('sin hora local legible no inventa un dia', () => {
    const mark = shiftMark('2026-03-14T05:00:00.000000Z', 'no es una hora', null, '2026-03-14')

    expect(mark.local).toBe('')
    expect(mark.date).toBeNull()
    expect(mark.nextDay).toBe(false)
  })
})
