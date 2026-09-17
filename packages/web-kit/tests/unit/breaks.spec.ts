// Movido de `frontend-admin/tests/unit/ShiftEntryTable.spec.ts` y
// `frontend-portal/tests/unit/MyRecordsView.spec.ts` (ADR-036, tarea 3.5,
// segunda vuelta): las dos SPA llevaban su propia copia de este predicado y
// ya habian divergido. Es la pieza critica: cubre RF-PA-03/RF-ID-05 y
// ADR-024 (la pausa son dos tramos, no un hueco mudo).
import { describe, expect, it } from 'vitest'
import { breakBetween } from '../../src/breaks'
import type { ShiftEntryClosingBreak, ShiftEntryOpeningBreak } from '../../src/breaks'

function closing(overrides: Partial<ShiftEntryClosingBreak> = {}): ShiftEntryClosingBreak {
  return {
    closed_by: 'break_start',
    clocked_out_at: '2026-03-14T09:00:00.000000Z',
    clocked_out_at_local: '2026-03-14T10:00:00.000000+01:00',
    ...overrides,
  }
}

function opening(overrides: Partial<ShiftEntryOpeningBreak> = {}): ShiftEntryOpeningBreak {
  return {
    opened_by: 'break_end',
    clocked_in_at: '2026-03-14T09:30:00.000000Z',
    clocked_in_at_local: '2026-03-14T10:30:00.000000+01:00',
    ...overrides,
  }
}

describe('breakBetween', () => {
  it('la pareja break_start/break_end da la hora de salida, la de vuelta y los minutos', () => {
    expect(breakBetween(closing(), opening())).toEqual({
      from: '10:00',
      to: '10:30',
      minutes: 30,
    })
  })

  it('sin break_start en el tramo anterior, no hay pausa aunque el siguiente sea break_end', () => {
    expect(breakBetween(closing({ closed_by: 'clock_out' }), opening())).toBeNull()
  })

  it('sin break_end en el tramo siguiente, no hay pausa aunque el anterior sea break_start', () => {
    expect(breakBetween(closing(), opening({ opened_by: 'clock_in' }))).toBeNull()
  })

  it('un tramo abierto (closed_by: null) no puede cerrar una pausa', () => {
    expect(breakBetween(closing({ closed_by: null }), opening())).toBeNull()
  })

  it('sin clocked_out_at, no hay pausa que describir', () => {
    expect(
      breakBetween(closing({ clocked_out_at: null, clocked_out_at_local: null }), opening()),
    ).toBeNull()
  })

  it('un tramo corregido o dado de alta a mano entre los dos rompe la pareja: no hubo pausa fichada', () => {
    // El tramo "siguiente" es un tramo nuevo, no la vuelta de la pausa: no
    // lleva `opened_by: break_end` porque nadie escaneo una vuelta.
    const manual = opening({ opened_by: 'clock_in' })

    expect(breakBetween(closing(), manual)).toBeNull()
  })

  it('si minutesBetween no puede analizar las marcas, no hay pausa: nunca un 0 que finja un calculo', () => {
    expect(
      breakBetween(
        closing({ clocked_out_at: 'no-es-una-fecha' }),
        opening({ clocked_in_at: '2026-03-14T09:30:00.000000Z' }),
      ),
    ).toBeNull()
  })
})
