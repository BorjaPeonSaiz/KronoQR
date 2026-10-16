// El auditor solo ve la Inspeccion (bloque 25 de la 2.2.1, RF-ID-02).
//
// Su sesion lleva unicamente `reports:legal`: ni presencia, ni cumplimiento, ni
// plantilla, ni departamentos. La interfaz lo refleja (menu de una entrada y
// guarda de rutas); la seguridad real es de la policy del servidor (regla dura 18).
import { expect, test } from '@playwright/test'
import { logInAsAuditor, stubManagementApi } from './support/admin'

test.describe('auditor: solo la exportacion para la Inspeccion', () => {
  test.beforeEach(async ({ page }) => {
    await stubManagementApi(page, { role: 'auditor' })
    await logInAsAuditor(page)
  })

  test(
    'aterriza en Inspeccion con un menu de una sola entrada',
    { tag: ['@RF-ID-02', '@regla-dura-18'] },
    async ({ page }) => {
      await expect(page).toHaveURL(/\/reports\/legal-export$/)

      const links = page.getByRole('navigation').getByRole('link')

      await expect(links).toHaveCount(1)
      await expect(links.first()).toHaveText('Inspección')
    },
  )

  for (const path of ['/live', '/compliance', '/departments']) {
    test(
      `${path} tecleado a mano vuelve a Inspeccion`,
      { tag: ['@RF-ID-02', '@regla-dura-18'] },
      async ({ page }) => {
        await page.goto(path)

        await expect(page).toHaveURL(/\/reports\/legal-export$/)
        await expect(page.getByRole('navigation').getByRole('link')).toHaveCount(1)
      },
    )
  }

  test(
    'la descarga de la exportacion funciona',
    { tag: ['@RF-ID-02', '@regla-dura-18'] },
    async ({ page }) => {
      await page.getByLabel(/Desde/).fill('2026-03-01')
      await page.getByLabel(/Hasta/).fill('2026-03-31')

      const download = page.waitForEvent('download')

      await page.getByRole('button', { name: 'Generar y descargar' }).click()

      expect((await download).suggestedFilename()).toBe(
        'registro-horario-2026-03-01_2026-03-31.csv',
      )
    },
  )
})
