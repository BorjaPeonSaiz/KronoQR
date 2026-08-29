// Movida de `frontend-kiosk/src/shared/qr/renderQrPath.ts` (ADR-036): el
// codificador es identico para cualquier pantalla que necesite convertir un
// texto en un QR dibujable, quiosco o panel.
import { describe, expect, it } from 'vitest'
import { renderQrPath } from '../../src/qr/renderQrPath'

describe('renderQrPath', () => {
  it('codifica un texto en un trazado SVG con modulos', async () => {
    const qr = await renderQrPath(
      'otpauth://totp/KronoQR:rrhh%40hotel.example?secret=JBSWY3DPEHPK3PXP',
    )

    expect(qr).not.toBeNull()
    expect(qr?.size).toBeGreaterThan(0)
    expect(qr?.path).toMatch(/^M\d+ \d+h1v1h-1z/)
  })

  it('devuelve null para un texto vacio, sin intentar codificar nada', async () => {
    await expect(renderQrPath('')).resolves.toBeNull()
  })

  it('codifica textos distintos en trazados distintos', async () => {
    const a = await renderQrPath('https://kronoqr.example/privacidad')
    const b = await renderQrPath(
      'otpauth://totp/KronoQR:otra%40hotel.example?secret=ABCDEFGHIJKLMNOP',
    )

    expect(a?.path).not.toBe(b?.path)
  })
})
