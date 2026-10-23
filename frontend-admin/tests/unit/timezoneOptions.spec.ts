// Sugerencias de zona horaria del centro (V21-1-UX-1, RN-23).
import { describe, expect, it } from 'vitest'
import phpTimeZones from './fixtures/php-timezones.json'
import {
  CANONICAL_TIME_ZONES,
  COMMON_TIME_ZONES,
  timezoneOptions,
} from '../../src/shared/ui/timezoneOptions'

describe('timezoneOptions', () => {
  it('usa la lista del navegador con las comunes primero y sin repetir', () => {
    const zones = timezoneOptions({
      supportedValuesOf: () => ['Africa/Cairo', 'Asia/Tokyo', 'Europe/Madrid', 'Europe/Paris'],
    })

    expect(zones.slice(0, COMMON_TIME_ZONES.length)).toEqual([...COMMON_TIME_ZONES])
    expect(zones).toContain('Africa/Cairo')
    expect(zones).toContain('Asia/Tokyo')
    expect(new Set(zones).size).toBe(zones.length)
  })

  it('normaliza los alias de ICU al nombre canonico que acepta el servidor', () => {
    const zones = timezoneOptions({
      supportedValuesOf: () => [
        'America/Buenos_Aires',
        'Asia/Calcutta',
        'Europe/Kiev',
        'Asia/Tokyo',
      ],
    })

    expect(zones).toContain('America/Argentina/Buenos_Aires')
    expect(zones).toContain('Asia/Kolkata')
    expect(zones).toContain('Europe/Kyiv')
    expect(zones).not.toContain('America/Buenos_Aires')
    expect(zones).not.toContain('Europe/Kiev')
  })

  it('conserva siempre las comunes aunque el motor no las liste', () => {
    expect(timezoneOptions({ supportedValuesOf: () => ['Asia/Tokyo'] })).toEqual(
      expect.arrayContaining([...COMMON_TIME_ZONES]),
    )
  })

  it('todo lo que ofrece este motor lo acepta PHP (volcado de DateTimeZone::listIdentifiers)', () => {
    const accepted = new Set<string>(phpTimeZones)

    expect(timezoneOptions().filter((zone) => !accepted.has(zone))).toEqual([])
    for (const canonical of Object.values(CANONICAL_TIME_ZONES)) {
      expect(accepted.has(canonical), canonical).toBe(true)
    }
  })

  it('sin Intl.supportedValuesOf cae a las zonas comunes', () => {
    expect(timezoneOptions({})).toEqual(COMMON_TIME_ZONES)
  })

  it('si supportedValuesOf lanza o devuelve vacio, tambien', () => {
    expect(
      timezoneOptions({
        supportedValuesOf: () => {
          throw new RangeError('unsupported')
        },
      }),
    ).toEqual(COMMON_TIME_ZONES)
    expect(timezoneOptions({ supportedValuesOf: () => [] })).toEqual(COMMON_TIME_ZONES)
  })

  it('en este motor ofrece muchas mas de cuatro zonas', () => {
    expect(timezoneOptions().length).toBeGreaterThan(50)
  })
})
