// Reflujo a 320 px CSS (WCAG 1.4.10, V7-PA-9): ninguna pantalla del panel obliga a
// desplazar la PAGINA en horizontal. Las tablas anchas se desplazan dentro de su
// propia region (enfocable y con nombre: `scrollContainers.spec.ts`), nunca el documento.
//
// Recorre las mismas pantallas que la pasada de axe (`AXE_ROUTE_PASSES`, que una prueba
// unitaria obliga a mantener completa): una pantalla nueva entra aqui sin tocar este fichero.
// Solo las que se abren con sesion de administracion; el acceso y el asistente tienen su
// propio recorrido.
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
import { AXE_ROUTE_PASSES } from './support/axeRoutes'

const NARROW = { width: 320, height: 640 }

const SCREENS = Object.entries(AXE_ROUTE_PASSES).filter(([, pass]) => pass.access === 'admin')

for (const [name, pass] of SCREENS) {
  test(`${name} (${pass.path}) no desborda la pagina a 320 px`, async ({ page }) => {
    await page.setViewportSize(NARROW)
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    await page.goto(pass.path)
    await expect(page.getByRole('heading', { level: 1, name: pass.heading })).toBeVisible()
    await page.waitForLoadState('networkidle')

    const widths = await page.evaluate(() => ({
      document: document.documentElement.scrollWidth,
      viewport: document.documentElement.clientWidth,
    }))

    expect(widths.document).toBeLessThanOrEqual(widths.viewport)
  })
}
