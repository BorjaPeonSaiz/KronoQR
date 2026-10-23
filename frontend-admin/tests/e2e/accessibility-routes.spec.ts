// axe en TODAS las rutas del panel, con `best-practice` (V21-7-QA-1, R7-QA-01,
// V7-QA-1, V7-QA-3). El dataset vive en `support/axeRoutes.ts` y
// `tests/unit/axeRouteCoverage.spec.ts` impide que una ruta se quede fuera.
//
// Es el suelo, no el techo: cada pantalla se analiza tal y como se abre. Los
// estados que solo aparecen tras una accion (dialogos, informes generados,
// avisos) siguen en `accessibility.spec.ts` y en la prueba de cada recorrido.

import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { logInAsAdmin, stubManagementApi } from './support/admin'
import { expectNoBlockingViolations } from './support/axe'
import type { AxeRouteAccess } from './support/axeRoutes'
import { AXE_ROUTE_PASSES } from './support/axeRoutes'
import { stubOnboardingApi } from './support/setupWizard'

const ARRIVE: Readonly<Record<AxeRouteAccess, (page: Page) => Promise<void>>> = {
  admin: async (page) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)
  },
  public: async (page) => {
    await stubManagementApi(page)
  },
  setup: async (page) => {
    await stubManagementApi(page)
    await stubOnboardingApi(page)
  },
}

for (const [name, pass] of Object.entries(AXE_ROUTE_PASSES)) {
  test(
    `la ruta «${name}» (${pass.path}) no tiene violaciones criticas ni graves`,
    { tag: [...pass.requirements] },
    async ({ page }) => {
      await ARRIVE[pass.access](page)

      await page.goto(pass.path)
      await expect(page.getByRole('heading', { level: 1, name: pass.heading })).toBeVisible()
      // Los dobles contestan al instante: sin peticiones en vuelo, la pantalla ya
      // pinto sus datos y no el estado de carga.
      await page.waitForLoadState('networkidle')

      // Todas las rutas salen limpias del todo, tambien de las menores y de
      // `best-practice` (`heading-order`, `region`...): que siga asi.
      await expectNoBlockingViolations(page, { failOn: 'any' })
    },
  )
}
