// Presentacion pura del historico de errores (RF-PD-15, tarea 5.12): el badge
// de nivel, la antiguedad contra el reloj del servidor, los limites de cada
// periodo y que cada combinacion de origen y nivel tiene su texto «que hacer».
import { describe, expect, it } from 'vitest'
import {
  ageSinceLastSeen,
  levelBadgeClass,
  periodBounds,
  whatToDoKey,
} from '@/features/errors/errorPresentation'
import type { ErrorLevel, ErrorSource } from '@/shared/api/types'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'

const SOURCES: readonly ErrorSource[] = [
  'api',
  'worker',
  'scheduler',
  'console',
  'kiosk',
  'admin',
  'portal',
]
const LEVELS: readonly ErrorLevel[] = ['error', 'critical']

describe('levelBadgeClass', () => {
  it('critico y error llevan clases distintas, las dos con color Y texto (WCAG 1.4.1)', () => {
    expect(levelBadgeClass('critical')).not.toBe(levelBadgeClass('error'))
    expect(levelBadgeClass('critical')).toContain('danger')
    expect(levelBadgeClass('error')).toContain('warning')
  })
})

describe('ageSinceLastSeen', () => {
  it('cuenta en horas y minutos enteros, nunca decimales', () => {
    const lastSeenAt = '2026-09-09T06:00:00.000000Z'
    const serverNowMs = Date.parse('2026-09-09T08:30:00.000000Z')

    expect(ageSinceLastSeen(lastSeenAt, serverNowMs)).toEqual({ hours: 2, minutes: '30' })
  })

  it('nunca negativo: un reloj local adelantado no da una antiguedad negativa', () => {
    const lastSeenAt = '2026-09-09T08:00:00.000000Z'
    const serverNowMs = Date.parse('2026-09-09T07:00:00.000000Z')

    expect(ageSinceLastSeen(lastSeenAt, serverNowMs)).toEqual({ hours: 0, minutes: '00' })
  })
})

describe('whatToDoKey', () => {
  it('cada combinacion de origen y nivel tiene su texto en español e ingles', () => {
    for (const source of SOURCES) {
      for (const level of LEVELS) {
        const key = whatToDoKey(source, level)

        expect(key).toBe(`errorEvents.whatToDo.${source}.${level}`)
        expect(es.errorEvents.whatToDo[source]?.[level]).toBeTruthy()
        expect(en.errorEvents.whatToDo[source]?.[level]).toBeTruthy()
      }
    }
  })
})

describe('periodBounds', () => {
  const nowMs = Date.parse('2026-09-09T08:00:00.000Z')

  it('un preset de dias cuenta hacia atras desde ahora, sin cota superior (hallazgo I4)', () => {
    const bounds = periodBounds('7', { from: '', to: '' }, nowMs)

    // Sin `to`: un reloj local atrasado tras la extrapolacion de
    // `serverNowMs` no puede dejar fuera un error recien creado.
    expect(bounds.to).toBeUndefined()
    expect(bounds.from).toBe('2026-09-02T08:00:00.000Z')
  })

  it('30 y 90 dias cuentan lo que dicen', () => {
    expect(periodBounds('30', { from: '', to: '' }, nowMs).from).toBe('2026-08-10T08:00:00.000Z')
    expect(periodBounds('90', { from: '', to: '' }, nowMs).from).toBe('2026-06-11T08:00:00.000Z')
  })

  it('personalizado se declara en UTC explicito, nunca la zona del navegador', () => {
    const bounds = periodBounds('custom', { from: '2026-09-01', to: '2026-09-08' }, nowMs)

    expect(bounds.from).toBe('2026-09-01T00:00:00.000Z')
    expect(bounds.to).toBe('2026-09-08T23:59:59.999Z')
  })

  it('personalizado sin alguna fecha escrita, no manda ese limite', () => {
    expect(periodBounds('custom', { from: '', to: '' }, nowMs)).toEqual({})
    expect(periodBounds('custom', { from: '2026-09-01', to: '' }, nowMs)).toEqual({
      from: '2026-09-01T00:00:00.000Z',
    })
  })
})
