import { describe, expect, it } from 'vitest'
import { isUuidV7, uuidV7 } from '@/shared/ids/uuidV7'

describe('scan_id (UUID v7)', () => {
  it('tiene la forma que exige el contrato', () => {
    expect(isUuidV7(uuidV7())).toBe(true)
  })

  it('marca version 7 y variante RFC 4122 pase lo que pase con el azar', () => {
    const allZeros = uuidV7(0, (target) => target.fill(0x00))
    const allOnes = uuidV7(0, (target) => target.fill(0xff))

    expect(isUuidV7(allZeros)).toBe(true)
    expect(isUuidV7(allOnes)).toBe(true)
  })

  it('codifica la marca de tiempo en los 48 bits altos', () => {
    const value = uuidV7(Date.parse('2026-08-14T05:02:00.000Z'), (target) => target.fill(0))
    const hex = value.replace(/-/g, '').slice(0, 12)

    expect(Number.parseInt(hex, 16)).toBe(Date.parse('2026-08-14T05:02:00.000Z'))
  })

  it('es ordenable temporalmente, que es por lo que se eligio frente a v4', () => {
    const earlier = uuidV7(Date.parse('2026-08-14T05:00:00.000Z'), (target) => target.fill(0))
    const later = uuidV7(Date.parse('2026-08-14T06:00:00.000Z'), (target) => target.fill(0))

    expect(earlier < later).toBe(true)
  })

  it('no repite', () => {
    const generated = new Set(Array.from({ length: 500 }, () => uuidV7()))
    expect(generated.size).toBe(500)
  })

  it('rechaza un v4 como scan_id', () => {
    expect(isUuidV7('9f1b6c2e-4a3d-4f7b-9c8a-1d2e3f4a5b6c')).toBe(false)
  })
})
