// Icono de la pestana del producto (PT-P4). Es el del fabricante, estatico y
// sin marca de cliente (regla dura 13): la marca blanca cambia nombre, color y
// logotipo por configuracion (RF-PD-08), no este fichero.
import { expect, test } from '@playwright/test'

test(
  'la pagina declara un icono SVG del producto y el servidor lo sirve',
  { tag: ['@RF-PD-08'] },
  async ({ page, request }) => {
    await page.goto('/login')

    const href = await page.locator('link[rel="icon"]').getAttribute('href')

    expect(href).toBe('/favicon.svg')

    const response = await request.get('/favicon.svg')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toContain('image/svg+xml')
    expect(await response.text()).toContain('<svg')
  },
)
