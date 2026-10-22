// Accesibilidad automatizada con axe-core (doc 02 §9.2 y §9.4).
//
// La pasada (etiquetas, `best-practice`, criterio y la regla `video-caption`
// desactivada con su motivo) vive en `support/axe.ts`, compartida por todos los
// E2E del quiosco.

import { expect, test } from '@playwright/test'
import { expectNoBlockingViolations } from './support/axe'
import { delayCameraStart, stubBrandingApi, stubKioskApi, stubScanApi } from './support/kiosk'

test.beforeEach(async ({ page }) => {
  await stubKioskApi(page)
})

test(
  'la pantalla de escaneo no tiene violaciones criticas ni graves',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'offline' })
    await page.goto('/')
    await expect(page.getByTestId('privacy-notice')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'la pantalla de confirmacion tampoco',
  { tag: ['@RF-KI-06', '@RF-AT-05'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'clock_in', workedMinutes: 0 })
    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'la pantalla de rechazo tampoco (cubre tambien «unreadable»)',
  { tag: ['@RF-KI-06', '@RS-03'] },
  async ({ page }) => {
    // `unreadable` (QR con formato ilegible) no tiene prueba propia: renderiza
    // por la MISMA rama de `ScanConfirmationPanel.vue` que `rejected` -mismo
    // titulo, mismo cuerpo, mismo tono `error`, misma variante- por diseno
    // (regla dura 17: un QR ilegible y uno rechazado no pueden distinguirse).
    // Cubrir `rejected` cubre estructuralmente los dos.
    await stubScanApi(page, { outcome: 'rejected' })
    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected')

    await expectNoBlockingViolations(page)
  },
)

test('en ingles tampoco', { tag: ['@RF-KI-05', '@RF-KI-06'] }, async ({ page }) => {
  await stubScanApi(page, { outcome: 'offline' })
  await stubBrandingApi(page, { variant: 'bilingual' })
  await page.goto('/')
  await page.getByRole('button', { name: 'English' }).click()
  await expect(page.getByTestId('privacy-notice')).toContainText('Data protection notice')

  await expectNoBlockingViolations(page)
})

test(
  'el resultado del escaneo se anuncia a un lector de pantalla',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'clock_in' })
    await page.goto('/')

    const panel = page.getByTestId('scan-confirmation')
    await expect(panel).toHaveAttribute('role', 'alert')
    await expect(panel).toHaveAttribute('aria-live', 'assertive')
  },
)

test(
  'con el boton «Pausa» armado tampoco hay violaciones (RF-AT-12, tarea 3.5)',
  { tag: ['@RF-KI-06', '@RF-AT-12'] },
  async ({ page }) => {
    // Sin `beforeEach` generico: este caso necesita el fichaje de pausa
    // activado Y ganarle la carrera a la camara simulada para poder armar el
    // boton antes de que el video en bucle produzca un fichaje.
    await delayCameraStart(page, 3_000)
    await stubKioskApi(page, { breakClockingEnabled: true })
    await stubScanApi(page, { outcome: 'break_start' })
    await page.goto('/')

    const toggle = page.getByTestId('break-toggle')
    await expect(toggle).toBeVisible()
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('break-armed-hint')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'con la banda de desfase de reloj visible tampoco hay violaciones (RF-AT-10, tarea 3.5)',
  { tag: ['@RF-KI-06', '@RF-AT-10'] },
  async ({ page }) => {
    const skewedServerTime = new Date(Date.now() - 40 * 60 * 1000).toISOString()
    await stubKioskApi(page, {
      clockSkewToleranceSeconds: 900,
      serverTime: () => skewedServerTime,
    })
    await stubScanApi(page, { outcome: 'offline' })
    await page.goto('/')

    await expect(page.getByTestId('clock-skew-banner')).toBeVisible({ timeout: 5_000 })

    await expectNoBlockingViolations(page)
  },
)

test(
  'con la marca de un cliente aplicada (logotipo y color propios) tampoco hay violaciones (RF-PD-08)',
  { tag: ['@RF-KI-06', '@RF-PD-08'] },
  async ({ page }) => {
    // `stubKioskApi` YA deja la marca del producto por defecto; se sobreescribe
    // DESPUES con la de «Hotel Marina» (acento, logotipo, un unico idioma) que
    // es el caso que mas toca: color de acento derivado en tiempo de ejecucion
    // y el selector de idioma SIN pintarse (doc 06 regla 8: nada que elegir).
    await stubBrandingApi(page, { variant: 'hotel-marina' })
    await stubScanApi(page, { outcome: 'clock_in' })
    await page.goto('/')

    await expect(page.getByTestId('brand-logo')).toBeVisible()
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

// La confirmacion LOCAL de un fichaje sin red (`pending`, RF-AT-10) es la que
// ve el empleado cada vez que falla la conexion, y ninguna pasada de axe la
// afirmaba: la prueba de la pantalla de escaneo esperaba solo al aviso de
// privacidad (AX7-03, R7-QA-01).

test(
  'la confirmacion pendiente de un fichaje sin red tampoco',
  { tag: ['@RF-KI-06', '@RF-AT-10', '@RF-KI-03'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'offline' })
    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')
    await expect(page.getByTestId('confirmation-pending-badge')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'la confirmacion pendiente en ingles tampoco',
  { tag: ['@RF-KI-06', '@RF-AT-10', '@RF-KI-05'] },
  async ({ page }) => {
    await delayCameraStart(page, 3_000)
    await stubScanApi(page, { outcome: 'offline' })
    await stubBrandingApi(page, { variant: 'bilingual' })
    await page.goto('/')
    await page.getByRole('button', { name: 'English' }).click()
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending', {
      timeout: 10_000,
    })
    await expect(page.locator('html')).toHaveAttribute('lang', 'en')

    await expectNoBlockingViolations(page)
  },
)

test(
  'la confirmacion de una salida tampoco',
  { tag: ['@RF-KI-06', '@RF-AT-05'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'clock_out', workedMinutes: 495 })
    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted')

    await expectNoBlockingViolations(page)
  },
)
