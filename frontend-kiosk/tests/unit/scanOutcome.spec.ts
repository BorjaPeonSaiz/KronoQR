import { describe, expect, it } from 'vitest'
import {
  CONFIRMATION_DISPLAY_MS,
  isArrival,
  PIN_VERIFY_TIMEOUT_MS,
  toneFor,
  variantFor,
} from '@/features/scan/domain/scanOutcome'
import type { ScanConfirmation } from '@/features/scan/domain/scanOutcome'
import { uuidV7 } from '@/shared/ids/uuidV7'

const at = new Date('2026-08-14T05:02:00.000Z')

function confirmation(overrides: Partial<ScanConfirmation> & { kind: ScanConfirmation['kind'] }) {
  return { scanId: uuidV7(), occurredAt: at, ...overrides } as ScanConfirmation
}

describe('feedback diferenciado', () => {
  it('entrada y vuelta de pausa suenan igual: la persona vuelve al puesto', () => {
    expect(isArrival('clock_in')).toBe(true)
    expect(isArrival('break_end')).toBe(true)
    expect(isArrival('clock_out')).toBe(false)
    expect(isArrival('break_start')).toBe(false)
  })

  it('da un tono distinto a entrada, salida y error', () => {
    const entry = toneFor(
      confirmation({
        kind: 'accepted',
        action: 'clock_in',
        displayName: 'Lucia G.',
        workedMinutes: 0,
        workDate: '2026-08-14',
      } as never),
    )
    const exit = toneFor(
      confirmation({
        kind: 'accepted',
        action: 'clock_out',
        displayName: 'Lucia G.',
        workedMinutes: 360,
        workDate: '2026-08-14',
      } as never),
    )
    const error = toneFor(confirmation({ kind: 'rejected' }))

    expect(new Set([entry, exit, error]).size).toBe(3)
    expect(entry).toBe('entry')
    expect(exit).toBe('exit')
    expect(error).toBe('error')
  })

  it('un fichaje encolado NO suena como entrada ni como salida: aun no se sabe', () => {
    expect(toneFor(confirmation({ kind: 'pending', displayName: null } as never))).toBe('pending')
  })

  it('«Comprobando…» (PIN) tiene un tono propio, distinto de «pendiente» y de cualquier desenlace', () => {
    const tone = toneFor(confirmation({ kind: 'verifying' }))

    expect(tone).toBe('verifying')
    expect(tone).not.toBe('pending')
    expect(tone).not.toBe('entry')
    expect(tone).not.toBe('exit')
    expect(tone).not.toBe('error')
  })

  it('el plazo de «Comprobando…» en pantalla ES el plazo de espera del PIN, el mismo numero', () => {
    expect(CONFIRMATION_DISPLAY_MS.verifying).toBe(PIN_VERIFY_TIMEOUT_MS)
  })

  it('el anti-rebote no suena a error, porque no lo es (ADR-031)', () => {
    const tone = toneFor(
      confirmation({
        kind: 'debounced',
        displayName: 'Lucia G.',
        workedMinutes: 240,
        lastAcceptedAt: at,
      } as never),
    )
    expect(tone).toBe('notice')
    expect(tone).not.toBe('error')
  })

  it('un rechazo local y uno del servidor son indistinguibles (regla dura 17)', () => {
    expect(toneFor(confirmation({ kind: 'unreadable' }))).toBe(
      toneFor(confirmation({ kind: 'rejected' })),
    )
    expect(variantFor(confirmation({ kind: 'unreadable' }))).toBe(
      variantFor(confirmation({ kind: 'rejected' })),
    )
  })

  it('deja los errores en pantalla mas tiempo que los aciertos', () => {
    expect(CONFIRMATION_DISPLAY_MS.rejected).toBeGreaterThan(CONFIRMATION_DISPLAY_MS.accepted)
    expect(CONFIRMATION_DISPLAY_MS.unreadable).toBeGreaterThan(CONFIRMATION_DISPLAY_MS.accepted)
  })
})
