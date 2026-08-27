// Movida de `frontend-admin/tests/unit/workdayTotals.spec.ts` (ADR-036), donde
// convivia con la aritmetica de la jornada en un unico fichero. Se separa aqui
// porque ya es un modulo propio (`dateRange.ts`), compartido tambien por el
// portal para `GET /me/workdays` y `GET /me/export`.
import { describe, expect, it } from 'vitest'
import {
  exceedsMaxRange,
  isInvertedRange,
  MAX_RANGE_DAYS,
  rangeLengthInDays,
} from '../../src/dateRange'

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
