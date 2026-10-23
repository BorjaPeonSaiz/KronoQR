// Lo que la tablet instalada declara de si misma: manifiesto PWA (PR7) y version
// (DC8). Dos cosas que solo se ven en el BUILD servido, no en una prueba unitaria.

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'
import { stubBrandingApi, stubKioskApi } from './support/kiosk'
import { readProductionSecurityHeaders, watchBrowserDiagnostics } from './support/securityHeaders'

interface ManifestIcon {
  readonly src: string
  readonly sizes: string
  readonly type: string
  readonly purpose?: string
}

const REPOSITORY_VERSION = readFileSync(
  fileURLToPath(new URL('../../../VERSION', import.meta.url)),
  'utf8',
).trim()

test.beforeEach(async ({ page }) => {
  await stubKioskApi(page)
})

test(
  'el manifiesto declara iconos PNG de 192 y 512 px que se sirven de verdad (PR7)',
  { tag: ['@RF-KI-01'] },
  async ({ page, request }) => {
    await page.goto('/')

    const response = await request.get('/manifest.webmanifest')
    expect(response.ok()).toBe(true)
    const manifest = (await response.json()) as { icons?: ManifestIcon[] }
    const icons = manifest.icons ?? []

    const sizes = icons.map((icon) => icon.sizes)
    expect(sizes).toContain('192x192')
    expect(sizes).toContain('512x512')
    expect(icons.some((icon) => icon.purpose === 'maskable')).toBe(true)

    for (const icon of icons) {
      expect(icon.type).toBe('image/png')
      const image = await request.get(`/${icon.src}`)
      expect(image.ok(), icon.src).toBe(true)
      expect(image.headers()['content-type']).toContain('image/png')
    }
  },
)

test(
  'el index.html enlaza el manifiesto del servidor, UNO solo, y la CSP real lo deja cargar (PR7)',
  { tag: ['@RF-PD-08', '@RS-09'] },
  async ({ page }) => {
    await stubBrandingApi(page, { variant: 'hotel-marina' })
    const diagnostics = await watchBrowserDiagnostics(page)

    const document = await page.goto('/')
    const links = page.locator('link[rel="manifest"]')
    await expect(links).toHaveCount(1)
    await expect(links).toHaveAttribute('href', '/api/v1/branding/manifest.webmanifest')

    // La CSP es la de produccion (snippet de Nginx) y declara `manifest-src`.
    const csp = readProductionSecurityHeaders()['Content-Security-Policy'] ?? ''
    expect(csp).toContain("manifest-src 'self'")
    expect(document?.headers()['content-security-policy']).toContain("manifest-src 'self'")

    // Chromium solo pide el manifiesto cuando va a instalar, no en cada carga: se
    // pide el enlace tal cual, desde la pagina, y llega con la marca.
    const href = await links.getAttribute('href')
    const served = await page.evaluate(async (url) => {
      const response = await fetch(url)
      return { status: response.status, body: (await response.json()) as { name: string } }
    }, href ?? '')
    expect(served.status).toBe(200)
    expect(served.body.name).toBe('Hotel Marina')
    expect(diagnostics.blockedSince()).toEqual([])
  },
)

test(
  'el latido declara la version real del producto, no 0.0.0 (DC8)',
  { tag: ['@RF-KI-08'] },
  async ({ page }) => {
    const versions: string[] = []
    await page.route('**/api/v1/kiosk/heartbeat', async (route) => {
      const body = route.request().postDataJSON() as { app_version: string }
      versions.push(body.app_version)
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          server_time: new Date().toISOString(),
          client_errors_accepted: 0,
          service_code_hash: null,
          break_clocking_enabled: false,
          clock_skew_tolerance_seconds: 900,
        }),
      })
    })

    await page.goto('/')
    await expect.poll(() => versions.length).toBeGreaterThan(0)

    expect(versions[0]).toBe(REPOSITORY_VERSION)
    expect(versions[0]).not.toBe('0.0.0')
  },
)

test(
  'el service worker precachea cada recurso UNA sola vez (iconos incluidos)',
  { tag: ['@RF-KI-01'] },
  async ({ request }) => {
    const response = await request.get('/sw.js')
    expect(response.ok()).toBe(true)
    const urls = [...(await response.text()).matchAll(/url:"([^"]+)"/g)].map((match) => match[1])

    expect(urls.length).toBeGreaterThan(0)
    expect(urls.filter((url, index) => urls.indexOf(url) !== index)).toEqual([])
  },
)
