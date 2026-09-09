// Soporte del emparejamiento de la tablet (RF-PD-06, tarea 5.6), compartido
// por `pairing.spec.ts` y por el generador de capturas de la tarea 5.11-E
// (`tests/screenshots/pairing.screenshots.ts`).
//
// El generador necesita quedarse MAS TIEMPO en el estado «pending» que el
// E2E funcional -para fotografiar el codigo con calma-, de ahi el parametro
// `pendingPolls`: el E2E lo deja en su valor de serie (1) y el generador lo
// sube.

import type { Page, Route } from '@playwright/test'

export const PAIRING_ID = '0199f3c1-4a2b-7e55-9c10-8d7e6f5a4b32'
export const PAIRING_SECRET = '9x2Kd4pQ7vLmN8tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ'
export const DEVICE_UUID = '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81'
export const TOKEN_VALUE = '92|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ'
export const PAIRING_CODE = '483921'

export interface PairingStubOptions {
  /** Cuantos sondeos hacen falta antes de que el mock «confirme» el codigo. */
  readonly pendingPolls?: number
  /** Cadencia de sondeo que anuncia `POST /pair`, en segundos. */
  readonly pollIntervalSeconds?: number
}

export async function stubPairing(page: Page, options: PairingStubOptions = {}): Promise<void> {
  const pendingPolls = options.pendingPolls ?? 1
  const pollIntervalSeconds = options.pollIntervalSeconds ?? 1
  let claimCalls = 0

  await page.route('**/api/v1/kiosk/pair', async (route: Route) => {
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        pairing_id: PAIRING_ID,
        pairing_secret: PAIRING_SECRET,
        code: PAIRING_CODE,
        expires_at: new Date(Date.now() + 10 * 60_000).toISOString(),
        poll_interval_seconds: pollIntervalSeconds,
      }),
    })
  })

  await page.route('**/api/v1/kiosk/pair/claim', async (route: Route) => {
    claimCalls += 1
    if (claimCalls <= pendingPolls) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: 'pending' }),
      })
      return
    }

    // El administrador ya ha tecleado el codigo en el panel: el siguiente
    // sondeo recoge el dispositivo vinculado y su token.
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'paired',
        device: { uuid: DEVICE_UUID, name: 'Recepcion' },
        token: { value: TOKEN_VALUE, expires_at: '2026-12-06T10:07:00Z' },
      }),
    })
  })

  // Canales del quiosco YA emparejado: hacen falta en cuanto `ScanView` monta.
  await page.route('**/api/v1/kiosk/heartbeat', async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ server_time: new Date().toISOString() }),
    })
  })
  await page.route('**/api/v1/kiosk/roster', async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ generated_at: new Date().toISOString(), entries: [] }),
    })
  })
}
