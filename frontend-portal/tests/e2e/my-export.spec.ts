// Descarga de mi propio historico (RL-05, RF-ID-05, art. 20 RGPD).
//
// Lo que se comprueba es lo que le pasa a una persona: pide su historico y se
// lo lleva, sin pedirselo a nadie ni esperar a que RRHH lo genere. El
// contenido del CSV -el BOM, el separador, el formato HH:MM- se prueba en el
// backend, que es donde se escribe (regla dura 18); lo que SI es de este
// nivel es que la descarga se dispara con el token de la sesion y que el
// nombre del fichero no lleva el nombre de nadie (regla dura 21).
import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import {
  PORTAL_SESSION_TOKEN,
  WORKDAYS_FROM,
  WORKDAYS_TO,
  logInToPortal,
  stubPortalApi,
} from './support/portal'

test(
  'descarga su historico con el periodo por omision, sin su nombre en el fichero',
  { tag: ['@RL-05', '@RF-ID-05'] },
  async ({ page }) => {
    const api = await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)
    await page.goto('/export')

    await expect(
      page.getByRole('heading', { level: 1, name: 'Descargar mi historial' }),
    ).toBeVisible()

    const download = page.waitForEvent('download')
    await page.getByRole('button', { name: 'Descargar CSV' }).click()
    const file = await download

    expect(file.suggestedFilename()).toBe(`mi-registro-horario-${WORKDAYS_FROM}_${WORKDAYS_TO}.csv`)
    expect(file.suggestedFilename()).not.toContain('Amrani')

    // `#main` excluye la region viva global del marco autenticado
    // (`AppShellView`, que tambien anuncia el mismo texto por accesibilidad):
    // este es el aviso visible propio de la pantalla.
    await expect(page.locator('#main').getByRole('status')).toContainText('Descarga generada')

    const request = api.requests.find((it) => it.path === '/api/v1/me/export')
    expect(request).toBeDefined()
    expect(request?.query).toContain('format=csv')
    expect(request?.authorization).toBe(`Bearer ${PORTAL_SESSION_TOKEN}`)
  },
)

test(
  'con un periodo elegido, la descarga pide exactamente ese periodo',
  { tag: ['@RL-05', '@RF-ID-05'] },
  async ({ page }) => {
    const api = await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)
    await page.goto('/export')

    await page.locator('input[type="date"]').first().fill('2026-03-09')
    await page.locator('input[type="date"]').last().fill('2026-03-10')

    const download = page.waitForEvent('download')
    await page.getByRole('button', { name: 'Descargar CSV' }).click()
    const file = await download

    expect(file.suggestedFilename()).toBe('mi-registro-horario-2026-03-09_2026-03-10.csv')

    const request = api.requests.find((it) => it.path === '/api/v1/me/export')
    expect(request?.query).toContain('from=2026-03-09')
    expect(request?.query).toContain('to=2026-03-10')
  },
)

test(
  'un periodo invertido no deja descargar y no llega a pedir nada al servidor',
  { tag: ['@RL-05', '@RF-ID-05'] },
  async ({ page }) => {
    const api = await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)
    await page.goto('/export')

    await page.locator('input[type="date"]').first().fill('2026-03-20')
    await page.locator('input[type="date"]').last().fill('2026-03-09')

    await expect(page.getByText('El periodo termina antes de empezar')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Descargar CSV' })).toBeDisabled()

    expect(api.requests.some((it) => it.path === '/api/v1/me/export')).toBe(false)
  },
)

test(
  'descarga el PDF con el nombre del servidor, sin el nombre del empleado',
  { tag: ['@RL-05', '@RF-ID-05'] },
  async ({ page }) => {
    const api = await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)
    await page.goto('/export')

    await page.getByRole('radio', { name: /PDF/ }).check()

    const download = page.waitForEvent('download')
    await page.getByRole('button', { name: 'Descargar PDF' }).click()
    const file = await download

    expect(file.suggestedFilename()).toBe(`mi-registro-horario-${WORKDAYS_FROM}_${WORKDAYS_TO}.pdf`)
    expect(file.suggestedFilename()).not.toContain('Amrani')

    const request = api.requests.find((it) => it.path === '/api/v1/me/export')
    expect(request?.query).toContain('format=pdf')
    expect(request?.authorization).toBe(`Bearer ${PORTAL_SESSION_TOKEN}`)
  },
)

test(
  'si el servidor no puede generar el PDF (503), avisa y ofrece el CSV',
  { tag: ['@RL-05', '@RF-ID-05'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es', pdfOutcome: 'unavailable' })
    await logInToPortal(page)
    await page.goto('/export')

    await page.getByRole('radio', { name: /PDF/ }).check()
    await page.getByRole('button', { name: 'Descargar PDF' }).click()

    const notice = page.getByTestId('pdf-unavailable')

    await expect(notice).toBeVisible()
    await expect(notice).toContainText('no se puede generar el PDF')

    const download = page.waitForEvent('download')
    await notice.getByRole('button', { name: 'Descargar CSV en su lugar' }).click()
    const file = await download

    expect(file.suggestedFilename()).toBe(`mi-registro-horario-${WORKDAYS_FROM}_${WORKDAYS_TO}.csv`)
  },
)

// Reflujo (WCAG 2.2 AA, 1.4.10; R7-PO-02): a 320 px CSS (zoom al 200 % sobre
// 640 px) la pantalla no se desplaza en horizontal y los controles siguen
// siendo operables.
for (const width of [320, 195]) {
  test(
    `a ${width} px CSS «Descargar mi historial» no desborda y se puede operar`,
    { tag: ['@RL-05', '@RF-ID-05'] },
    async ({ page }) => {
      await page.setViewportSize({ width, height: 568 })
      await stubPortalApi(page, { locale: 'es' })
      await logInToPortal(page)
      await page.goto('/export')

      const submit = page.getByTestId('export-submit')
      await expect(submit).toBeVisible()

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      )
      expect(overflow, 'la pagina no se desplaza en horizontal').toBeLessThanOrEqual(0)

      // Cada control cabe dentro de la ventana.
      for (const control of await page.getByTestId('export-form').locator('input, button').all()) {
        const box = await control.boundingBox()
        expect(box).not.toBeNull()
        expect((box?.x ?? 0) + (box?.width ?? 0)).toBeLessThanOrEqual(width + 1)
      }

      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze()
      expect(
        results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious'),
      ).toEqual([])

      const download = page.waitForEvent('download')
      await submit.click()
      await download
    },
  )
}
