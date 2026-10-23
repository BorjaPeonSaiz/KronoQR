// Un trozo diferido que NO carga (tarea 17.10; «se paso por alto» de la tanda 3
// de la 2.2.1; RF-KI-01, RF-KI-02, regla dura 19).
//
// El quiosco carga por `import()` tres vistas (PIN, emparejamiento y
// diagnostico) y el decodificador. Con el service worker ya instalado salen del
// precache (lo prueba `kiosk-upgrade.spec.ts` tras una actualizacion urgente);
// aqui se prueba lo que pasa ANTES de tenerlo: el primer arranque, o una tablet
// cuyo precacheo no termino. Se bloquea el service worker y se corta UNA vez la
// peticion del trozo en la red.
//
// Lo que se exige es lo de la regla 19: la pantalla de fichaje sigue viva y, en
// cuanto el trozo vuelve a estar disponible, la persona puede usarlo sin que
// nadie recargue la tablet.

import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { stubKioskApi, stubScanApi } from './support/kiosk'
import { blockCamera } from './support/offlineQueue'
import { stubKioskApiWithPin } from './support/pin'

test.use({ serviceWorkers: 'block' })

/** Corta la PRIMERA peticion que case con `pattern`; las siguientes pasan. Devuelve cuantas hubo. */
async function failFirstRequest(page: Page, pattern: string): Promise<{ count: number }> {
  const seen = { count: 0 }
  await page.route(pattern, async (route) => {
    seen.count += 1
    await (seen.count === 1 ? route.abort('failed') : route.continue())
  })
  return seen
}

test(
  'si el trozo del PIN no carga, la pantalla de fichaje sigue viva',
  { tag: ['@RF-AT-11', '@RF-KI-01'] },
  async ({ page }) => {
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    const pinChunk = await failFirstRequest(page, '**/assets/PinView-*.js')
    await page.goto('/')

    await page.getByTestId('pin-entry-link').click()
    await expect.poll(() => pinChunk.count).toBe(1)

    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByTestId('scan-camera-section')).toBeVisible()
    await expect(page.getByTestId('pin-entry-link')).toBeVisible()
  },
)

test(
  'si el trozo del PIN no cargo, el segundo toque lo abre sin recargar la tablet',
  { tag: ['@RF-AT-11', '@RF-KI-01'] },
  async ({ page }) => {
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    const pinChunk = await failFirstRequest(page, '**/assets/PinView-*.js')
    await page.goto('/')
    await page.getByTestId('pin-entry-link').click()
    await expect.poll(() => pinChunk.count).toBe(1)

    await page.getByTestId('pin-entry-link').click()

    await expect(page.getByTestId('pin-step-code')).toBeVisible()
  },
)

test(
  'si el decodificador no carga, el escaner se recupera solo y la tarjeta vuelve a fichar',
  { tag: ['@RF-KI-02', '@RF-KI-01'] },
  async ({ page }) => {
    // DEFECTO ABIERTO (17.10, para frontend-quiosco): `useQrScanner.ts` deja el
    // escaner en `unavailable` cuando el `import()` del decodificador falla y
    // solo lo reintenta al volver a mostrarse la pagina, cosa que una tablet de
    // pared no hace nunca: hasta recargarla solo queda el PIN. Cuando el
    // escaner reintente solo, esta prueba empezara a pasar y `test.fail` la
    // hara fallar: entonces se quita esta linea.
    test.fail(true, 'el escaner no reintenta solo la carga del decodificador')
    await stubKioskApi(page)
    const scans = await stubScanApi(page, { outcome: 'clock_in' })
    const decoderChunk = await failFirstRequest(page, '**/assets/esm-*.js')
    await page.goto('/')
    await expect.poll(() => decoderChunk.count).toBeGreaterThan(0)

    await expect(page.getByTestId('scan-confirmation')).toBeVisible({ timeout: 20_000 })

    expect(scans.recorded.length).toBeGreaterThan(0)
  },
)
