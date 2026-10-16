// Idioma del portal (RF-PD-08, bloque 26 de la 2.2.1): el espanol va de serie
// y el ingles se activa por configuracion (`branding.locales.available`).
//
// Con un solo idioma ofrecido el portal no sigue al navegador ni al idioma de
// la persona; con dos, si. Aqui el navegador pide ingles britanico en ambos
// casos: lo unico que cambia es la politica de la instalacion.
import { expect, test } from '@playwright/test'
import { BILINGUAL_BRANDING, PRODUCT_BRANDING, stubPortalApi } from './support/portal'

test.use({ locale: 'en-GB' })

test(
  'con un solo idioma ofrecido, el portal sale en espanol aunque el navegador pida ingles',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'en', branding: PRODUCT_BRANDING })

    await page.goto('/login')

    await expect(page.getByRole('heading', { level: 1, name: 'Acceso al portal' })).toBeVisible()
    await expect(page.locator('html')).toHaveAttribute('lang', 'es')
  },
)

test(
  'con dos idiomas ofrecidos, el portal sigue al navegador',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'en', branding: BILINGUAL_BRANDING })

    await page.goto('/login')

    await expect(
      page.getByRole('heading', { level: 1, name: 'Sign in to the portal' }),
    ).toBeVisible()
    await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  },
)
