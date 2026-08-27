import { describe, expect, it } from 'vitest'
import { splitWorkedMinutes } from '@/features/scan/domain/workedTime'
import { formatClockTime } from '@/features/scan/domain/clockTime'
import { greetingSlotFor } from '@/features/scan/domain/greeting'

describe('acumulado de la jornada', () => {
  it('parte el ejemplo del documento 01 §11: 360 minutos son 6 h 0 min', () => {
    expect(splitWorkedMinutes(360)).toEqual({ hours: 6, minutes: 0 })
  })

  it('nunca produce decimales', () => {
    expect(splitWorkedMinutes(482)).toEqual({ hours: 8, minutes: 2 })
    expect(splitWorkedMinutes(465)).toEqual({ hours: 7, minutes: 45 })
    expect(splitWorkedMinutes(59)).toEqual({ hours: 0, minutes: 59 })
  })

  it('trata un total imposible como cero en vez de pintar NaN', () => {
    expect(splitWorkedMinutes(-5)).toEqual({ hours: 0, minutes: 0 })
    expect(splitWorkedMinutes(Number.NaN)).toEqual({ hours: 0, minutes: 0 })
    expect(splitWorkedMinutes(Number.POSITIVE_INFINITY)).toEqual({ hours: 0, minutes: 0 })
  })

  it('trunca los segundos que pudieran venir como fraccion de minuto', () => {
    expect(splitWorkedMinutes(120.9)).toEqual({ hours: 2, minutes: 0 })
  })
})

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
