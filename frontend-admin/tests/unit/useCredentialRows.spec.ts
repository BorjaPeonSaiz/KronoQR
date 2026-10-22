// Calculos puros del tablero de credenciales: el avance de la reimpresion (RF-QR-07).
// El filtrado y la paginacion pasaron al servidor y se prueban en CredentialBoardView.spec.
import { describe, expect, it } from 'vitest'
import type { CredentialCoverage } from '@/shared/api/types'
import { reprintProgressOf } from '@/features/credentials/useCredentialRows'

// Avance de la reimpresion durante una rotacion de clave (RF-QR-07). Tambien
// puro: entra el `summary` que devuelve el contrato y sale lo que se pinta.
describe('reprintProgressOf (RF-QR-07)', () => {
  function coverage(overrides: Partial<CredentialCoverage> = {}): CredentialCoverage {
    return {
      employees: 60,
      pending_print: 0,
      without_delivered_credential: 0,
      retiring_key_id: null,
      pending_reprint: 0,
      active_unknown_key: 0,
      ...overrides,
    }
  }

  it('no hay avance que mostrar fuera de una rotacion', () => {
    expect(reprintProgressOf(coverage())).toBeNull()
    expect(reprintProgressOf(null)).toBeNull()
  })

  it('mide el avance contra la plantilla entera, no contra lo que falta', () => {
    const progress = reprintProgressOf(
      coverage({ retiring_key_id: 'a2', pending_reprint: 12, employees: 60 }),
    )

    expect(progress).toEqual({ keyId: 'a2', pending: 12, done: 48, total: 60, percent: 80 })
  })

  it('llega al 100 % cuando ya nadie ficha con la clave saliente', () => {
    // Es el estado que autoriza `credentials:retire-key`.
    const progress = reprintProgressOf(coverage({ retiring_key_id: 'a2', pending_reprint: 0 }))

    expect(progress?.percent).toBe(100)
    expect(progress?.pending).toBe(0)
  })

  it('una instalacion sin plantilla no divide por cero', () => {
    const progress = reprintProgressOf(
      coverage({ retiring_key_id: 'a2', employees: 0, pending_reprint: 0 }),
    )

    expect(progress?.percent).toBe(100)
  })
})
