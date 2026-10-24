// ADR-061 (17.9) y P7, de negro: lo que el quiosco hace con los `422` de un lote.

import { expect, test } from '@playwright/test'
import { stubKioskApi } from './support/kiosk'
import {
  announceOnline,
  readDiscarded,
  readQueue,
  stubDiscardedScanReports,
} from './support/offlineQueue'

test.beforeEach(async ({ page }) => {
  await stubKioskApi(page)
})

async function queueOneScanThenServe(page: import('@playwright/test').Page, type: string) {
  await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
  const reports = await stubDiscardedScanReports(page)
  await page.goto('/')
  await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)

  await page.unroute('**/api/v1/scan')
  const sent: string[] = []
  await page.route('**/api/v1/scan/batch', async (route) => {
    const body = route.request().postDataJSON() as { scans: Array<{ scan_id: string }> }
    sent.push(...body.scans.map((item) => item.scan_id))
    await route.fulfill({
      status: 207,
      contentType: 'application/json',
      body: JSON.stringify({
        results: body.scans.map((item) => ({
          scan_id: item.scan_id,
          status: 422,
          outcome: {
            type,
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
  return { reports, sent }
}

test(
  'un `scan-discarded` del servidor saca el fichaje de la cola sin avisar ni abrir descartado propio',
  { tag: ['@RF-KI-03', '@RF-KI-04', '@RQ-05', '@RN-22'] },
  async ({ page }) => {
    const { reports, sent } = await queueOneScanThenServe(
      page,
      'urn:kronoqr:problem:scan-discarded',
    )

    await expect.poll(() => sent.length).toBeGreaterThan(0)
    await expect.poll(async () => (await readQueue(page)).length).toBe(0)
    expect(await readDiscarded(page)).toHaveLength(0)
    expect(reports.calls).toHaveLength(0)
  },
)

test(
  'un `422` de lote que no es estandar va a descartados y se avisa al servidor (RN-22)',
  { tag: ['@RF-KI-03', '@RF-KI-04', '@RQ-05', '@RN-22'] },
  async ({ page }) => {
    const { reports, sent } = await queueOneScanThenServe(page, 'urn:kronoqr:problem:algo-nuevo')

    await expect.poll(() => sent.length).toBeGreaterThan(0)
    await expect.poll(async () => (await readQueue(page)).length).toBe(0)
    await expect.poll(() => reports.calls.length).toBeGreaterThan(0)
    expect(reports.calls[0]?.[0]?.http_status).toBe(422)
    await expect.poll(async () => (await readDiscarded(page)).length).toBe(0)
  },
)
