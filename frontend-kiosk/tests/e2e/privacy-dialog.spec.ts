// El dialogo del QR de la politica de privacidad (RF-KI-09, V7-KI-02): un
// fichaje siempre manda sobre el aviso, y es un dialogo modal de verdad.
// El cierre por inactividad (30 s, tope de 120 s) lo cubren las unitarias con
// temporizadores falsos: aqui no hay forma honesta de adelantar el reloj de la
// pagina sin parar la camara simulada.

import { expect, test } from '@playwright/test'
import {
  delayCameraStart,
  holdCameraStart,
  stubBrandingApi,
  stubKioskApi,
  stubScanApi,
} from './support/kiosk'

const POLICY_URL = 'https://marina.example/privacidad'

test.beforeEach(async ({ page }) => {
  await stubKioskApi(page)
  await stubBrandingApi(page, {
    variant: 'hotel-marina',
    privacyNotice: { controllerName: 'Hotel Marina S.L.', policyUrl: POLICY_URL },
  })
})

test(
  'el dialogo del QR se cierra en el acto cuando llega una confirmacion de fichaje',
  { tag: ['@RF-KI-09', '@RF-AT-05'] },
  async ({ page }) => {
    // La camara queda retenida hasta que la prueba la libera, con el dialogo ya abierto.
    const releaseCamera = await holdCameraStart(page)
    await stubScanApi(page, { outcome: 'clock_in' })

    await page.goto('/')
    await page.getByTestId('privacy-show-qr').click()
    const dialog = page.getByTestId('privacy-qr-dialog')
    await expect(dialog).toBeVisible()
    await expect(page.getByTestId('scan-confirmation')).toHaveCount(0)

    await releaseCamera()

    // Llega el fichaje: la confirmacion queda a la vista y el dialogo se va solo.
    await expect(page.getByTestId('scan-confirmation')).toBeVisible({ timeout: 10_000 })
    await expect(dialog).toHaveCount(0)
    // Y la pagina vuelve a ser operable (nada se queda inerte).
    await expect(page.getByTestId('scan-camera-section')).not.toHaveAttribute('inert', /.*/)
  },
)

test(
  'es modal: el foco entra, el resto queda inerte y Escape cierra devolviendo el foco',
  { tag: ['@RF-KI-09', '@RF-KI-06'] },
  async ({ page }) => {
    await delayCameraStart(page, 30_000)
    await stubScanApi(page, { outcome: 'clock_in' })

    await page.goto('/')
    const showQr = page.getByTestId('privacy-show-qr')
    await showQr.click()

    await expect(page.getByTestId('privacy-qr-dialog')).toBeVisible()
    await expect(page.getByTestId('privacy-qr-close')).toBeFocused()
    await expect(page.getByTestId('scan-camera-section')).toHaveAttribute('inert', '')

    await page.keyboard.press('Escape')

    await expect(page.getByTestId('privacy-qr-dialog')).toHaveCount(0)
    await expect(showQr).toBeFocused()
    await expect(page.getByTestId('scan-camera-section')).not.toHaveAttribute('inert', /.*/)
  },
)
