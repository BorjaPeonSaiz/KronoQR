// Vinculacion del quiosco por codigo de emparejamiento (RF-PD-06, tarea 5.6).
//
// Recorrido completo: una tablet SIN token arranca en `/pair` (guard del
// router), pide un codigo, lo muestra, sondea hasta que el mock simula la
// confirmacion del panel, guarda el token recien emitido y entra a la
// pantalla de fichaje -- donde un escaneo de verdad (camara simulada, mismo
// fixture que `scan.spec.ts`) llega al servidor firmado con ESE token.
//
// A proposito, NO se llama a `pairDevice`/`stubKioskApi` (`support/kiosk.ts`):
// esta prueba empieza justo donde esas dos dejan a las demas, sin token.

import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

const PAIRING_ID = '0199f3c1-4a2b-7e55-9c10-8d7e6f5a4b32'
const PAIRING_SECRET = '9x2Kd4pQ7vLmN8tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ'
const DEVICE_UUID = '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81'
const TOKEN_VALUE = '92|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ'

/** Cuantos sondeos hacen falta antes de que el mock «confirme» el codigo. */
const PENDING_POLLS = 1

async function stubPairing(page: import('@playwright/test').Page): Promise<void> {
  let claimCalls = 0

  await page.route('**/api/v1/kiosk/pair', async (route) => {
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        pairing_id: PAIRING_ID,
        pairing_secret: PAIRING_SECRET,
        code: '483921',
        expires_at: new Date(Date.now() + 10 * 60_000).toISOString(),
        // Cadencia rapida: esta prueba no tiene por que esperar los 5 s de serie.
        poll_interval_seconds: 1,
      }),
    })
  })

  await page.route('**/api/v1/kiosk/pair/claim', async (route) => {
    claimCalls += 1
    if (claimCalls <= PENDING_POLLS) {
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
  await page.route('**/api/v1/kiosk/heartbeat', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ server_time: new Date().toISOString() }),
    })
  })
  await page.route('**/api/v1/kiosk/roster', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ generated_at: new Date().toISOString(), entries: [] }),
    })
  })
}

test.beforeEach(async ({ page }) => {
  await stubPairing(page)
})

test(
  'una tablet sin token arranca en la pantalla de emparejamiento, sin que nadie la mande',
  { tag: ['@RF-PD-06'] },
  async ({ page }) => {
    await page.goto('/')

    // El guard del router la trajo aqui sola: la URL pedida era `/`.
    await expect(page).toHaveURL(/\/pair$/)
    await expect(page.getByTestId('pairing-code')).toHaveText('483 921')
  },
)

test(
  'vinculacion completa: guarda el token y el primer fichaje llega firmado con el',
  { tag: ['@RF-PD-06'] },
  async ({ page }) => {
    const scanCalls: { authorization: string | undefined }[] = []
    await page.route('**/api/v1/scan', async (route) => {
      const body = route.request().postDataJSON() as { scan_id: string; occurred_at: string }
      scanCalls.push({ authorization: route.request().headers()['authorization'] })
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          scan_id: body.scan_id,
          action: 'clock_in',
          employee_display_name: 'Lucia G.',
          work_date: body.occurred_at.slice(0, 10),
          occurred_at: body.occurred_at,
          recorded_at: new Date().toISOString(),
          worked_minutes: 0,
        }),
      })
    })

    await page.goto('/')
    await expect(page.getByTestId('pairing-code')).toBeVisible()

    // El sondeo confirma la vinculacion y la PWA navega sola a la pantalla de
    // fichaje: ni un clic, exactamente como al recibir `paired`.
    await expect(page).toHaveURL(/\/$/, { timeout: 10_000 })

    // El token es el que trajo `PairingCompleted`, no uno inventado.
    expect(await page.evaluate(() => localStorage.getItem('kronoqr.kiosk.device_token'))).toBe(
      TOKEN_VALUE,
    )
    expect(await page.evaluate(() => localStorage.getItem('kronoqr.kiosk.device_id'))).toBe(
      DEVICE_UUID,
    )

    // La camara simulada decodifica el fixture `FH1` en continuo (mismo video
    // que `scan.spec.ts`): el primer fichaje llega solo, y firmado con el
    // token recien obtenido.
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    await expect.poll(() => scanCalls.length).toBeGreaterThan(0)
    expect(scanCalls[0]?.authorization).toBe(`Bearer ${TOKEN_VALUE}`)
  },
)

test(
  'la pantalla de emparejamiento no tiene violaciones criticas ni graves',
  { tag: ['@RF-PD-06'] },
  async ({ page }) => {
    await page.goto('/')
    await expect(page.getByTestId('pairing-code')).toBeVisible()

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze()

    const blocking = results.violations.filter(
      (violation) => violation.impact === 'critical' || violation.impact === 'serious',
    )

    expect(
      blocking,
      blocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
    ).toEqual([])
  },
)
