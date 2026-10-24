import { describe, expect, it } from 'vitest'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import { formatClockTime } from '@/features/scan/domain/clockTime'
import { greetingSlotFor } from '@/features/scan/domain/greeting'

describe('hora de pared', () => {
  it('usa 24 horas en los dos idiomas, como el ejemplo del documento 01', () => {
    const at0702 = new Date(2026, 7, 14, 7, 2, 31)
    expect(formatClockTime(at0702, 'es')).toBe('07:02')
    expect(formatClockTime(at0702, 'en')).toBe('07:02')

    const at1902 = new Date(2026, 7, 14, 19, 2, 0)
    expect(formatClockTime(at1902, 'en')).toBe('19:02')
  })

  it('no revienta con una fecha invalida', () => {
    expect(formatClockTime(new Date('nope'), 'es')).toBe('--:--')
  })
})

describe('saludo del turno', () => {
  it('el cambio de turno de las 06:00 es «buenos dias»', () => {
    expect(greetingSlotFor(new Date(2026, 7, 14, 6, 0))).toBe('morning')
    expect(greetingSlotFor(new Date(2026, 7, 14, 7, 2))).toBe('morning')
  })

  it('reparte tarde y noche por los tramos del hotel', () => {
    expect(greetingSlotFor(new Date(2026, 7, 14, 12, 59))).toBe('morning')
    expect(greetingSlotFor(new Date(2026, 7, 14, 13, 0))).toBe('afternoon')
    expect(greetingSlotFor(new Date(2026, 7, 14, 20, 59))).toBe('afternoon')
    expect(greetingSlotFor(new Date(2026, 7, 14, 21, 0))).toBe('night')
    expect(greetingSlotFor(new Date(2026, 7, 14, 5, 59))).toBe('night')
  })
})

// El acumulado sale de `durationParts` de web-kit, el mismo del panel y del
// portal (C4): antes el quiosco tenia su copia y enseñaba «6 h 5 min» donde el
// portal decia «6 h 05 min». Los bordes (NaN, negativos, fracciones) los cubre
// la prueba de web-kit; aqui, el ejemplo del documento 01 §11.
describe('acumulado de la jornada', () => {
  it('parte el ejemplo del documento 01 §11: 360 minutos son 6 h 00 min', () => {
    expect(durationParts(360)).toEqual({ hours: 6, minutes: '00' })
    expect(durationParts(365)).toEqual({ hours: 6, minutes: '05' })
  })
})
