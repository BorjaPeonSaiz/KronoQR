// Sugerencias de zona horaria del centro (V21-1-UX-1, RN-23).
import { describe, expect, it } from 'vitest'
import { COMMON_TIME_ZONES, timezoneOptions } from '../../src/shared/ui/timezoneOptions'

describe('timezoneOptions', () => {
  it('usa la lista del navegador con las comunes primero y sin repetir', () => {
    const zones = timezoneOptions({
      supportedValuesOf: () => ['Africa/Cairo', 'Asia/Tokyo', 'Europe/Madrid', 'Europe/Paris'],
    })

    expect(zones.slice(0, 3)).toEqual(['Europe/Madrid', 'Europe/Paris', 'UTC'])
    expect(zones).toContain('Africa/Cairo')
    expect(zones).toContain('Asia/Tokyo')
    expect(new Set(zones).size).toBe(zones.length)
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
