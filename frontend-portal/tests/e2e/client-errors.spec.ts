// Reporte de errores de cliente hacia el historico del panel (RF-PD-15,
// tarea 5.12). El backend no participa: lo que se prueba aqui es que el
// portal capta un error real, lo manda a `POST /api/v1/client-errors` en
// cuanto hay sesion, y que un fallo al reportarlo NO bloquea el portal ni
// reintenta en bucle. El saneado en servidor, la agrupacion por huella y la
// autorizacion negativa se prueban en el backend (regla dura 18); mismo
// patron que `frontend-admin/tests/e2e/errors.spec.ts`.
import { expect, test } from '@playwright/test'
import {
  PORTAL_EMPLOYEE_CODE,
  PORTAL_PIN,
  stubClientErrorsEndpoint,
  stubPortalApi,
} from './support/portal'

test(
  'un fallo al reportar un error de cliente no bloquea el portal ni reintenta en bucle',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    const clientErrors = stubClientErrorsEndpoint(page)

    await page.goto('/login')

    // Un error real, sin capturar por Playwright: se dispara en una
    // macrotarea aparte para que llegue al `window.onerror` de la propia
    // pagina -ANTES de iniciar sesion, para que el transporte lo vacie justo
    // al pasar a autenticado, sin depender del intervalo periodico de 60 s,
    // que un E2E no puede esperar-.
    await page.evaluate(() => {
      window.setTimeout(() => {
        throw new Error('boom-e2e-portal')
      }, 0)
    })

    // Se entra SIN volver a navegar (nada de `page.goto`, que recargaria la
    // pagina y perderia el error ya lanzado, en memoria hasta que algo lo
    // vacie): se rellena y se envia el formulario ya cargado, igual que
    // `frontend-admin/tests/e2e/errors.spec.ts`.
    await page.locator('input[name="employee_code"]').fill(PORTAL_EMPLOYEE_CODE)
    await page.locator('input[name="pin"]').fill(PORTAL_PIN)
    await page.locator('form button[type="submit"]').click()
    await page.waitForURL('**/records')

    await expect.poll(() => clientErrors.count(), { timeout: 5_000 }).toBeGreaterThanOrEqual(1)

    // El portal sigue funcionando tras el 500: se puede seguir navegando.
    await page.goto('/export')
    await expect(
      page.getByRole('heading', { level: 1, name: 'Descargar mi historial' }),
    ).toBeVisible()

    const firstCount = clientErrors.count()

    // Sin reintento en bucle: unos segundos despues, el recuento no ha
    // subido (el siguiente intento no llega hasta el intervalo periodico de
    // 60 s).
    await page.waitForTimeout(3_000)
    expect(clientErrors.count()).toBe(firstCount)
  },
)
