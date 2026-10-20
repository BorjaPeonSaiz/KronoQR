// Idioma del portal (RF-PD-08, bloque 26 de la 2.2.1): el espanol va de serie
// y el ingles se activa por configuracion (`branding.locales.available`).
//
// Con un solo idioma ofrecido el portal no sigue al navegador ni al idioma de
// la persona; con dos, si. Aqui el navegador pide ingles britanico en ambos
// casos: lo unico que cambia es la politica de la instalacion.
import { expect, test } from '@playwright/test'
import {
  BILINGUAL_BRANDING,
  PORTAL_EMPLOYEE_CODE,
  PORTAL_PIN,
  PRODUCT_BRANDING,
  stubPortalApi,
} from './support/portal'

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

test(
  'con un solo idioma ofrecido no hay selector de idioma',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es', branding: PRODUCT_BRANDING })

    await page.goto('/login')
    await expect(page.getByRole('heading', { level: 1, name: 'Acceso al portal' })).toBeVisible()

    await expect(page.getByTestId('language-selector')).toHaveCount(0)
  },
)

test(
  'con dos idiomas el selector cambia el idioma en el acceso y se mantiene al entrar',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'en', branding: BILINGUAL_BRANDING })

    await page.goto('/login')

    const selector = page.getByTestId('language-selector')

    // El navegador pide ingles britanico: arranca en ingles.
    await expect(page.locator('html')).toHaveAttribute('lang', 'en')

    await selector.getByRole('button', { name: 'Español' }).click()

    await expect(page.getByRole('heading', { level: 1, name: 'Acceso al portal' })).toBeVisible()
    await expect(page.locator('html')).toHaveAttribute('lang', 'es')
    await expect(selector.getByRole('button', { name: 'Español' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    // Cada boton se lee en su propio idioma, sea cual sea el de la pantalla.
    await expect(selector.getByRole('button', { name: 'English' })).toHaveAttribute('lang', 'en')

    // Entra: la sesion es de una persona cuyo idioma es el ingles, pero la
    // eleccion manual manda mientras la pestana siga abierta.
    await page.locator('input[name="employee_code"]').fill(PORTAL_EMPLOYEE_CODE)
    await page.locator('input[name="pin"]').fill(PORTAL_PIN)
    await page.locator('form button[type="submit"]').click()
    await page.waitForURL('**/records')

    await expect(page.locator('html')).toHaveAttribute('lang', 'es')
    await expect(
      page.getByRole('banner').getByTestId('language-selector').getByRole('button', {
        name: 'Español',
      }),
    ).toHaveAttribute('aria-pressed', 'true')
  },
)
