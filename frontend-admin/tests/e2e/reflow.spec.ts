// Reflujo a 320 px CSS (WCAG 1.4.10, V7-PA-9): ninguna pantalla del panel obliga a
// desplazar la PAGINA en horizontal. Las tablas anchas se desplazan dentro de su
// propia region (enfocable y con nombre: `scrollContainers.spec.ts`), nunca el documento.
//
// Tres causas reales, medidas en un navegador y no en jsdom: un `<fieldset>` que no
// encoge, un filtro `select` mas ancho que la pantalla y texto `sr-only` posicionado
// dentro de una tabla ancha, que escapa del `overflow` de su contenedor si este no
// es el bloque contenedor (`relative`).
//
// Sin etiqueta de requisito, como `shell.spec.ts`: WCAG 2.2 AA general, sin `id` propio
// en el catalogo de requisitos.
import { expect, test } from '@playwright/test'
import { logInAsAdmin, stubManagementApi } from './support/admin'

const NARROW = { width: 320, height: 640 }

const SCREENS: readonly string[] = [
  '/employees',
  '/departments',
  '/absences',
  '/live',
  '/incidents',
  '/compliance',
  '/credentials',
  '/reports',
  '/reports/payroll',
  '/reports/adoption',
  '/reports/legal-export',
  '/compliance-profile',
  '/settings',
  '/devices',
  '/branding',
  '/license',
  '/support',
  '/errors',
  '/accounts',
]

for (const path of SCREENS) {
  test(`${path} no desborda la pagina a 320 px`, async ({ page }) => {
    await page.setViewportSize(NARROW)
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    await page.goto(path)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    await page.waitForLoadState('networkidle')

    const widths = await page.evaluate(() => ({
      document: document.documentElement.scrollWidth,
      viewport: document.documentElement.clientWidth,
    }))

    expect(widths.document).toBeLessThanOrEqual(widths.viewport)
  })
}
