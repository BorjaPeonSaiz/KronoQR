// La sesion que se acaba con el portal abierto (V3-PO-1, V2-PO-4, RF-ID-07).
//
// El servidor deja de reconocer el token (caducado o revocado): la persona no
// se queda mirando un error generico, ve «tu sesion ha caducado» en el acceso
// y, al entrar de nuevo, vuelve a la pantalla en la que estaba.
import { expect, test } from '@playwright/test'
import type { Route } from '@playwright/test'
import { logInToPortal, PORTAL_EMPLOYEE_CODE, PORTAL_PIN, stubPortalApi } from './support/portal'

test(
  'un 401 con la sesion abierta lleva al acceso con el aviso y, al entrar, vuelve a donde estaba',
  { tag: ['@RF-ID-07', '@RF-ID-06'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)

    await page.getByRole('link', { name: 'Descargar mi historial' }).click()
    await expect(
      page.getByRole('heading', { level: 1, name: 'Descargar mi historial' }),
    ).toBeVisible()

    // La siguiente descarga ya no la reconoce el servidor; las demas siguen igual.
    let rejected = false
    await page.route('**/api/v1/me/export**', async (route: Route) => {
      if (rejected) {
        await route.fallback()

        return
      }

      rejected = true
      await route.fulfill({
        status: 401,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'urn:kronoqr:problem:unauthenticated',
          title: 'No autenticado',
          status: 401,
        }),
      })
    })

    await page.getByTestId('export-submit').click()

    await expect(page).toHaveURL(/\/login\?redirect=(%2F|\/)export$/)
    await expect(page.getByTestId('session-expired')).toContainText('Tu sesión ha caducado')

    await page.locator('input[name="employee_code"]').fill(PORTAL_EMPLOYEE_CODE)
    await page.locator('input[name="pin"]').fill(PORTAL_PIN)
    await page.locator('form button[type="submit"]').click()

    await expect(page).toHaveURL(/\/export$/)
    await expect(page.getByTestId('session-expired')).toHaveCount(0)
  },
)
