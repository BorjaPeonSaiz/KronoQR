// Garantia de la propia suite (R2-PO-01): el proceso NO corre en la zona del
// centro ni en UTC, asi que cualquier formateo con la zona del navegador
// daria una hora distinta de la esperada y la prueba fallaria.
import { describe, expect, it } from 'vitest'

describe('zona horaria del proceso de pruebas', () => {
  it('no es ni la del centro (Madrid) ni UTC', () => {
    const offsetMinutes = -new Date('2026-03-14T12:00:00Z').getTimezoneOffset()

    expect(offsetMinutes).not.toBe(0)
    expect(offsetMinutes).not.toBe(60)
    expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe('Pacific/Auckland')
  })
})
