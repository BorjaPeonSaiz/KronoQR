// El auditor solo ve la Inspeccion (bloque 25 de la 2.2.1, RF-ID-02).
//
// Su sesion lleva unicamente `reports:legal`: ni presencia, ni cumplimiento, ni
// plantilla, ni departamentos. La interfaz lo refleja (sin menu lateral, porque su
// unico enlace llevaria a la pantalla en la que ya esta, y guarda de rutas); la seguridad real es de la policy del servidor (regla dura 18).
import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import {
  EMPLOYEE_UUID,
  LEGAL_EXPORT_PERSON_NATIONAL_ID,
  LEGAL_EXPORT_PERSON_UUID,
  logInAsAuditor,
  stubManagementApi,
} from './support/admin'

test.describe('auditor: solo la exportacion para la Inspeccion', () => {
  test.beforeEach(async ({ page }) => {
    await stubManagementApi(page, { role: 'auditor' })
    await logInAsAuditor(page)
  })

  test(
    'aterriza en Inspeccion sin menu lateral de un solo enlace (V21-3-UX-3)',
    { tag: ['@RF-ID-02', '@regla-dura-18'] },
    async ({ page }) => {
      await expect(page).toHaveURL(/\/reports\/legal-export$/)

      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      await expect(page.getByRole('banner').getByRole('navigation')).toHaveCount(0)
    },
  )

  for (const path of [
    '/live',
    '/compliance',
    '/departments',
    `/employees/${EMPLOYEE_UUID}/workdays`,
  ]) {
    test(
      `${path} tecleado a mano vuelve a Inspeccion`,
      { tag: ['@RF-ID-02', '@regla-dura-18'] },
      async ({ page }) => {
        await page.goto(path)

        await expect(page).toHaveURL(/\/reports\/legal-export$/)
        await expect(page.getByRole('banner').getByRole('navigation')).toHaveCount(0)
      },
    )
  }

  test(
    'la descarga de la exportacion funciona',
    { tag: ['@RF-ID-02', '@regla-dura-18'] },
    async ({ page }) => {
      await page.getByLabel(/Desde/).fill('2026-03-01')
      await page.getByLabel(/Hasta/).fill('2026-03-31')

      // Toda la plantilla se genera en segundo plano (14.4): primero el avance y,
      // al terminar, la descarga con un enlace nuevo.
      await page.getByRole('button', { name: 'Generar y descargar' }).click()
      await expect(page.getByTestId('export-job')).toBeVisible()

      const download = page.waitForEvent('download')

      await page.getByTestId('export-job-download').click()

      expect((await download).suggestedFilename()).toMatch(/\.csv$/)
    },
  )
  test(
    'busca por nombre, elige a una persona de baja y descarga',
    { tag: ['@RF-IN-05', '@RF-ID-02'] },
    async ({ page }) => {
      await page.getByLabel(/Desde/).fill('2026-03-01')
      await page.getByLabel(/Hasta/).fill('2026-03-31')
      await page.getByLabel('Una persona').check()

      // Ya no hay un campo para teclear el UUID en crudo.
      await expect(page.getByLabel(/Trabajador/)).toHaveCount(0)

      // Teclear no busca: cada busqueda deja un asiento en la auditoria.
      let searches = 0

      page.on('request', (request) => {
        if (request.url().includes('/employees/search')) {
          searches += 1
        }
      })

      await page.getByLabel('Nombre, apellidos o código de empleado').pressSequentially('amrani')
      expect(searches).toBe(0)

      await page.getByLabel('Nombre, apellidos o código de empleado').press('Enter')

      // Intro en el buscador no envia la exportacion.
      const option = page.getByRole('radio', { name: /Amrani, Lucía · Cocina · De baja desde/ })

      await expect(option).toBeVisible()
      await expect(page.getByText('Esta búsqueda queda registrada.')).toBeVisible()
      expect(searches).toBe(1)

      // Axe sobre la pantalla con resultados.
      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze()

      expect(
        results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious'),
      ).toEqual([])

      await option.check()
      await page.getByRole('button', { name: 'Usar a esta persona' }).click()
      await expect(page.getByText(/Se exportará: Amrani, Lucía/)).toBeVisible()
      await expect(page.getByText(LEGAL_EXPORT_PERSON_UUID)).toHaveCount(0)

      const exportRequest = page.waitForRequest(
        (request) =>
          request.url().includes('/reports/legal-export?') &&
          request.url().includes(`employee_uuid=${LEGAL_EXPORT_PERSON_UUID}`),
      )
      const download = page.waitForEvent('download')

      await page.getByRole('button', { name: 'Generar y descargar' }).click()

      await exportRequest
      expect((await download).suggestedFilename()).toBe(
        'registro-horario-2026-03-01_2026-03-31.csv',
      )
    },
  )

  test('busca por DNI', { tag: ['@RF-IN-05', '@RF-ID-02'] }, async ({ page }) => {
    const urls: string[] = []

    page.on('request', (request) => urls.push(request.url()))

    await page.getByLabel('Una persona').check()
    await page
      .getByLabel('Documento de identidad (DNI, NIE, pasaporte)', { exact: true })
      .first()
      .check()

    const field = page.getByRole('textbox', {
      name: 'Documento de identidad (DNI, NIE, pasaporte)',
    })

    await field.fill(LEGAL_EXPORT_PERSON_NATIONAL_ID)
    await page.getByRole('button', { name: 'Buscar' }).click()

    await expect(page.getByRole('radio', { name: /Amrani, Lucía/ })).toBeVisible()

    // El documento se borra del campo y nunca viaja en una URL.
    await expect(field).toHaveValue('')
    expect(urls.filter((url) => url.includes(LEGAL_EXPORT_PERSON_NATIONAL_ID))).toEqual([])
  })
})
