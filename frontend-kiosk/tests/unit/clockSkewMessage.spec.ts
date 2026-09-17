import { describe, expect, it } from 'vitest'
import {
  clockSkewMinutesFrom,
  exceedsClockSkewTolerance,
} from '@/features/scan/domain/clockSkewMessage'

describe('clockSkewMinutesFrom', () => {
  it('redondea a minutos y dice "adelantada" con desfase positivo', () => {
    expect(clockSkewMinutesFrom(2_400)).toEqual({ minutes: 40, direction: 'ahead' })
  })

  it('dice "atrasada" con desfase negativo', () => {
    expect(clockSkewMinutesFrom(-2_400)).toEqual({ minutes: 40, direction: 'behind' })
  })

  it('nunca redondea a cero minutos', () => {
    expect(clockSkewMinutesFrom(61).minutes).toBeGreaterThanOrEqual(1)
    expect(clockSkewMinutesFrom(-61).minutes).toBeGreaterThanOrEqual(1)
  })
})

describe('exceedsClockSkewTolerance', () => {
  it('sin umbral todavia (tablet sin latido), nunca supera: sin banda, no un valor inventado', () => {
    expect(exceedsClockSkewTolerance(10_000, null)).toBe(false)
  })

  it('supera cuando el valor absoluto pasa el umbral', () => {
    expect(exceedsClockSkewTolerance(2_400, 900)).toBe(true)
    expect(exceedsClockSkewTolerance(-2_400, 900)).toBe(true)
  })

  it('no supera dentro del umbral', () => {
    expect(exceedsClockSkewTolerance(800, 900)).toBe(false)
  })

  // Un solo operador (`>` estricto) en las tres rutas que deciden "supera"
  // (heartbeat.ts, settleFrom.ts y el servidor, `ReviewPolicy`): un desfase
  // EXACTAMENTE igual al umbral no avisa, ni en positivo ni en negativo
  // (revision de la segunda vuelta de la tarea 3.5).
  it('en el limite exacto (899/900/901 y -901), solo avisa por encima', () => {
    expect(exceedsClockSkewTolerance(899, 900)).toBe(false)
    expect(exceedsClockSkewTolerance(900, 900)).toBe(false)
    expect(exceedsClockSkewTolerance(901, 900)).toBe(true)
    expect(exceedsClockSkewTolerance(-901, 900)).toBe(true)
    expect(exceedsClockSkewTolerance(-900, 900)).toBe(false)
    expect(exceedsClockSkewTolerance(-899, 900)).toBe(false)
  })
})
