// Tarjeta deteriorada (doc 01 §11, doc 02 §9.4 fila «QR degradado»).
//
// Este fichero corre en el proyecto `kiosk-qr-degraded`, que arranca Chromium
// con `qr-video-degraded.y4m`: el mismo QR con un trozo tapado. Lo que se
// verifica es que el nivel de correccion de errores Q cumple lo prometido
// (RF-QR-05) y que una tarjeta con roces sigue fichando, en lugar de mandar a
// alguien a recepcion a por una nueva.
//
// El limite real esta medido en `scripts/generate-qr-fixture.mjs`: con una
// oclusion OPACA Y CONTIGUA, el simbolo decodifica hasta el 28 % del lado y
// falla a partir del 32 %. El «25 % de degradacion» del documento 02 §5.1 son
// palabras de codigo repartidas, que es como se estropea una tarjeta de verdad.

import { expect, test } from '@playwright/test'
import { FIXTURE_PAYLOAD, stubKioskApi, stubScanApi } from './support/kiosk'

test(
  'una tarjeta parcialmente tapada se sigue leyendo',
  { tag: ['@RF-KI-02', '@RF-QR-05'] },
  async ({ page }) => {
    await stubKioskApi(page)
    let sentPayload: string | null = null

    await page.route('**/api/v1/scan', async (route) => {
      const body = route.request().postDataJSON() as {
        qr_payload: string
        scan_id: string
        occurred_at: string
      }
      sentPayload = body.qr_payload
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          scan_id: body.scan_id,
          action: 'clock_in',
          employee_display_name: 'Lucia G.',
          work_date: body.occurred_at?.slice(0, 10) ?? '2026-08-14',
          occurred_at: new Date().toISOString(),
          recorded_at: new Date().toISOString(),
          worked_minutes: 0,
        }),
      })
    })

    await page.goto('/')

    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    // Y lo leido es el payload INTACTO: la correccion de errores lo reconstruyo.
    // Se espera al envio: desde la tarea 1.9 la confirmacion es local y va por
    // delante de la peticion, asi que darla por hecha aqui seria una carrera.
    await expect.poll(() => sentPayload).toBe(FIXTURE_PAYLOAD)
  },
)

test(
  'y confirma con el mismo feedback que una tarjeta nueva',
  { tag: ['@RF-AT-05'] },
  async ({ page }) => {
    await stubKioskApi(page)
    await stubScanApi(page, { outcome: 'clock_in', displayName: 'Lucia G.' })

    await page.goto('/')

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-variant', 'entry')
    await expect(page.getByTestId('confirmation-headline')).toContainText('Lucia G.')
  },
)
