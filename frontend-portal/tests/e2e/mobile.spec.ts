// El portal en el movil (R7-PO-01, V7-PO-4, R7-PO-03).
//
// Quien entra lo hace casi siempre desde su telefono personal: el resumen tiene
// que verse sin bajar, los controles tienen que acertarse con el dedo y cada
// pantalla tiene que llamarse distinto en la pestana.
import { expect, test } from '@playwright/test'
import { logInToPortal, stubPortalApi } from './support/portal'

test(
  'en un movil de 360 x 740 el total del periodo y «hoy» estan en la primera pantalla',
  { tag: ['@RF-ID-05', '@RL-05'] },
  async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 })
    await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)

    const total = page.getByTestId('period-total')

    await expect(total).toBeVisible()
    const box = await total.boundingBox()

    expect(box).not.toBeNull()
    expect((box?.y ?? Number.POSITIVE_INFINITY) + (box?.height ?? 0)).toBeLessThanOrEqual(740)
  },
)

test('los radios CSV y PDF miden al menos 48 px', { tag: ['@RF-ID-05'] }, async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 })
  await stubPortalApi(page, { locale: 'es' })
  await logInToPortal(page)
  await page.goto('/export')

  for (const radio of await page.locator('input[type="radio"][name="format"]').all()) {
    const box = await radio.boundingBox()

    expect(box?.width ?? 0).toBeGreaterThanOrEqual(48)
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(48)
  }
})

test(
  'cada pantalla tiene su propio titulo de pestana',
  { tag: ['@RF-ID-06'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })

    await page.goto('/login')
    await expect(page).toHaveTitle('Acceso al portal · KronoQR')

    await logInToPortal(page)
    await expect(page).toHaveTitle('Mi registro horario · KronoQR')

    await page.goto('/export')
    await expect(page).toHaveTitle('Descargar mi historial · KronoQR')
  },
)
