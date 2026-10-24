// La fecha y hora de las filas del diagnostico del quiosco (RF-KI-08).
import { describe, expect, it } from 'vitest'
import { formatDiagnosticInstant } from '@/features/diagnostics/application/diagnosticInstant'

describe('formatDiagnosticInstant', () => {
  it('con la zona del centro, con segundos y en 24 h', () => {
    expect(formatDiagnosticInstant('2026-10-08T05:14:03Z', 'Europe/Madrid', 'es')).toBe(
      '8 oct 2026, 07:14:03',
    )
    expect(formatDiagnosticInstant('2026-10-08T05:14:03Z', 'Atlantic/Canary', 'en')).toBe(
      'Oct 8, 2026, 06:14:03',
    )
  })

  it('sin zona del centro usa la de la tablet y no deja la fila vacia', () => {
    const label = formatDiagnosticInstant('2026-10-08T05:14:03Z', null, 'es')

    expect(label).not.toBeNull()
    expect(label).toMatch(/2026, \d{2}:\d{2}:03$/)
  })

  it('una zona que el navegador no reconoce cuenta como sin zona', () => {
    expect(formatDiagnosticInstant('2026-10-08T05:14:03Z', 'Marte/Olympus', 'es')).toMatch(
      /2026, \d{2}:\d{2}:03$/,
    )
  })

  it('sin dato, o con uno ilegible, devuelve null para que la pantalla diga «no disponible»', () => {
    expect(formatDiagnosticInstant(null, 'Europe/Madrid', 'es')).toBeNull()
    expect(formatDiagnosticInstant('no es una fecha', 'Europe/Madrid', 'es')).toBeNull()
  })
})
