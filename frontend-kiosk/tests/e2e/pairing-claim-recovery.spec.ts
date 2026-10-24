// V6-KI-02: se pierde la respuesta del `claim` (el servidor ya entrego el token).
// La tablet no pide un codigo nuevo: repite la recogida con el mismo secreto, el
// servidor reemite el token dentro de su ventana y la vinculacion termina.

import type { Route } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { DEVICE_UUID, stubPairing } from './support/pairing'

test(
  'respuesta del claim perdida: rechazo «demasiado pronto» y despues el token reemitido, sin codigo nuevo',
  { tag: ['@RF-PD-06'] },
  async ({ page }) => {
    await stubPairing(page)
    const claims: string[] = []
    let pairRequests = 0
    await page.route('**/api/v1/kiosk/pair', async (route: Route) => {
      pairRequests += 1
      await route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          pairing_id: '0199f3c1-4a2b-7e55-9c10-8d7e6f5a4b32',
          pairing_secret: '9x2Kd4pQ7vLmN8tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ',
          code: '483921',
          expires_at: new Date(Date.now() + 10 * 60_000).toISOString(),
          poll_interval_seconds: 1,
        }),
      })
    })
    await page.route('**/api/v1/kiosk/pair/claim', async (route: Route) => {
      claims.push('claim')
      const attempt = claims.length
      if (attempt === 1) {
        // El servidor recogio, pero la respuesta nunca llega.
        await route.abort('failed')
        return
      }
      if (attempt === 2) {
        await route.fulfill({
          status: 422,
          contentType: 'application/problem+json',
          body: JSON.stringify({
            type: 'urn:kronoqr:problem:pairing-rejected',
            title: 'Emparejamiento no valido',
            status: 422,
            detail: 'La solicitud de emparejamiento no se ha podido completar.',
          }),
        })
        return
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'paired',
          device: { uuid: DEVICE_UUID, name: 'Recepcion' },
          token: { value: '93|token-reemitido', expires_at: '2026-12-06T10:07:00Z' },
        }),
      })
    })

    await page.goto('/')
    await expect(page.getByTestId('pairing-code')).toBeVisible()

    await expect(page).toHaveURL(/\/$/, { timeout: 15_000 })
    expect(await page.evaluate(() => localStorage.getItem('kronoqr.kiosk.device_token'))).toBe(
      '93|token-reemitido',
    )
    // Un solo codigo pedido y tres recogidas del mismo: nunca se descarto la solicitud.
    expect(pairRequests).toBe(1)
    expect(claims.length).toBeGreaterThanOrEqual(3)
  },
)
