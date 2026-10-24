// A1: la cola se guarda cifrada y las filas en claro de versiones anteriores se
// migran al arrancar sin perder ninguna: despues siguen drenando con la tarjeta
// descifrada.

import { expect, test } from '@playwright/test'
import { FIXTURE_PAYLOAD, stubKioskApi } from './support/kiosk'
import { announceOnline, readQueue, seedQueue, stubBatchApi } from './support/offlineQueue'

test.beforeEach(async ({ page }) => {
  await stubKioskApi(page)
})

test(
  'una fila en claro de antes se cifra al arrancar y despues se envia con la tarjeta descifrada',
  { tag: ['@RF-KI-03', '@RQ-05'] },
  async ({ page }) => {
    const SEEDED = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8f77'
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    // El lote no contesta bien: la fila se queda en disco para poder inspeccionarla.
    await stubBatchApi(page, 503)
    await page.goto('/')
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)

    await seedQueue(page, [
      { scan_id: SEEDED, occurred_at: '2026-08-01T04:00:00.000Z', qr_payload: FIXTURE_PAYLOAD },
    ])
    expect(JSON.stringify(await readQueue(page))).toContain(FIXTURE_PAYLOAD)

    // Arranque: la migracion sustituye la fila en claro por la cifrada.
    await page.reload()
    await expect
      .poll(async () => JSON.stringify(await readQueue(page)).includes(FIXTURE_PAYLOAD))
      .toBe(false)
    expect((await readQueue(page)).some((row) => row.scan_id === SEEDED)).toBe(true)

    // Vuelve el servidor: la fila migrada sale por el lote con la tarjeta descifrada.
    await page.unroute('**/api/v1/scan')
    await page.unroute('**/api/v1/scan/batch')
    const sent: Array<{ scan_id: string; qr_payload: string }> = []
    await page.route('**/api/v1/scan/batch', async (route) => {
      const body = route.request().postDataJSON() as {
        scans: Array<{ scan_id: string; qr_payload: string }>
      }
      sent.push(...body.scans)
      await route.fulfill({
        status: 207,
        contentType: 'application/json',
        body: JSON.stringify({
          results: body.scans.map((item) => ({
            scan_id: item.scan_id,
            status: 422,
            outcome: {
              type: 'urn:kronoqr:problem:scan-rejected',
              title: 'x',
              status: 422,
              detail: 'x',
              scan_id: item.scan_id,
            },
          })),
        }),
      })
    })
    await announceOnline(page)

    await expect.poll(async () => (await readQueue(page)).length).toBe(0)
    expect(sent.find((item) => item.scan_id === SEEDED)?.qr_payload).toBe(FIXTURE_PAYLOAD)
  },
)
