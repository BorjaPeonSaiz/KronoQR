// La marca de la instalacion en el portal (RF-PD-08, tarea 5.8): la misma
// disciplina que en el panel y el quiosco (ADR-036) — ninguna SPA declara un
// color propio, y sin nada configurado se ve el producto.
//
// Lo que se comprueba es lo que ve una persona: el acceso y el marco
// autenticado enseñan el nombre, el color de acento y el logotipo del
// cliente en cuanto llega la respuesta del servidor, y si esa respuesta no
// llega -sin red, o con una forma que no cuadra con el contrato- se quedan
// con el producto, nunca con una pantalla rota (`load()` nunca lanza).
//
// `BrandMark` marca su raiz con `data-testid` (compartido por las tres SPA,
// `packages/web-kit`), no con el `data-test` que este portal usa en el resto
// de sus propios componentes: por eso aqui se localiza con el selector de
// atributo crudo en vez de `getByTestId`, igual que
// `frontend-admin/tests/e2e/branding.spec.ts`.
import { expect, test } from '@playwright/test'
import type { Route } from '@playwright/test'
import { HOTEL_BRANDING, logInToPortal, stubPortalApi } from './support/portal'

const BRAND_MARK = '[data-testid="brand-mark"]'

test(
  'sin marca configurada, el acceso y el registro enseñan el nombre del producto',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })

    await page.goto('/login')
    await expect(page.locator(BRAND_MARK)).toHaveText('KronoQR')
    await expect(page).toHaveTitle('KronoQR')
    // Sin color de acento propio, ningun token se sobreescribe.
    await expect(page.locator('html')).toHaveAttribute('data-kq-branded', 'product')

    await logInToPortal(page)
    await expect(page.getByRole('banner').locator(BRAND_MARK)).toHaveText('KronoQR')
    await expect(page).toHaveTitle('KronoQR')
  },
)

test(
  'con la marca del cliente aplicada, el acceso y el marco autenticado la muestran',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es', branding: HOTEL_BRANDING })

    await page.goto('/login')
    // Con logotipo, la imagen lleva el nombre como alternativa textual: no
    // hay un segundo texto suelto que lo repita (un solo significado para
    // quien usa lector de pantalla).
    await expect(page.locator(BRAND_MARK)).toHaveAttribute('alt', 'Hotel Marina')
    await expect(page).toHaveTitle('Hotel Marina')
    // Y el color de acento del cliente se aplica sobre los tokens `--kq-*`.
    await expect(page.locator('html')).toHaveAttribute('data-kq-branded', 'accent')

    await logInToPortal(page)
    await expect(page.getByRole('banner').locator(BRAND_MARK)).toHaveAttribute(
      'alt',
      'Hotel Marina',
    )
    await expect(page).toHaveTitle('Hotel Marina')
  },
)

test(
  'un fallo al pedir la marca deja el nombre del producto, sin romper el acceso',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    // Se registra DESPUES de `stubPortalApi` para que esta ruta, mas
    // especifica, gane a la generica: `GET /api/v1/branding` falla del todo.
    await page.route('**/api/v1/branding', async (route: Route) => {
      await route.abort('failed')
    })

    await page.goto('/login')

    await expect(page.locator(BRAND_MARK)).toHaveText('KronoQR')
    await expect(page).toHaveTitle('KronoQR')
    await expect(page.getByRole('heading', { name: 'Acceso al portal' })).toBeVisible()
  },
)

test(
  'una respuesta de marca con una forma que no cuadra con el contrato tambien deja el producto',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    await page.route('**/api/v1/branding', async (route: Route) => {
      // Sin `locales`, que el contrato exige: `parseBranding` la rechaza.
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ application_name: '', accent_color: null, logo_url: null }),
      })
    })

    await page.goto('/login')

    await expect(page.locator(BRAND_MARK)).toHaveText('KronoQR')
    await expect(page).toHaveTitle('KronoQR')
  },
)
