// Tarjeta deteriorada (doc 01 §11, doc 02 §9.4 fila «QR degradado»).
//
// Este fichero corre en el proyecto `kiosk-qr-degraded`, que arranca Chromium
// con `qr-video-degraded.y4m`: el mismo QR con un trozo tapado. Lo que se
// verifica es que el nivel de correccion de errores Q cumple lo prometido
// (RF-QR-05) y que una tarjeta con roces sigue fichando, en lugar de mandar a
// alguien a recepcion a por una nueva.
//
// El limite real -oclusion opaca y contigua, el caso peor- esta medido en
// `scripts/generate-qr-fixture.mjs` y documentado con sus cifras exactas
// (fraccion del lado, area del simbolo real, resultado) en
// `e2e/fixtures/README.md`. Estas cifras son evidencia de que el
// decodificador no ha sufrido una regresion, no la promesa de ninguna
// tolerancia al cliente: el producto no documenta ese numero.

import { expect, test } from '@playwright/test'
import { FIXTURE_PAYLOAD, stubKioskApi, stubScanApi } from './support/kiosk'

test(
  'una tarjeta parcialmente tapada se sigue leyendo',
  { tag: ['@RQ-04', '@RF-KI-02', '@RF-QR-05'] },
  async ({ page }) => {
    await stubKioskApi(page)
    const stub = await stubScanApi(page, { outcome: 'clock_in' })

    await page.goto('/')

    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    // Y lo leido es el payload INTACTO: la correccion de errores lo reconstruyo.
    // Se espera al envio: desde la tarea 1.9 la confirmacion es local y va por
    // delante de la peticion, asi que darla por hecha aqui seria una carrera.
    await expect.poll(() => stub.recorded[0]?.qrPayload).toBe(FIXTURE_PAYLOAD)
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
