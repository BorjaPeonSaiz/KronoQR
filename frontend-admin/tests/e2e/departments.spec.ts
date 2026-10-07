// Departamentos y centro desde el panel (RF-ID-03, R3-PA-04, bloque 21 de la 2.2.0).
//
// LOS RECORRIDOS QUE IMPORTAN: quien administra crea un departamento y lo
// renombra viendo «antes → despues»; un nombre repetido se explica junto al
// campo; el nombre del centro se edita y su zona horaria se ve sin poder
// cambiarse; y quien solo consulta (auditor) ve la lista sin ningun control de
// escritura.
//
// El backend no participa: la API va simulada con las formas del contrato
// (`support/admin.ts`). Que `rrhh` no pueda asignar responsable o que el nombre
// sea unico dentro del centro lo prueba el backend.
//
// Con `data-test` y no con etiquetas exactas: las etiquetas de `FormField`
// llevan un asterisco aria-hidden.
import AxeBuilder from '@axe-core/playwright'
import { expect, type Page, test } from '@playwright/test'
import { logInAsAdmin, logInAsAuditor, stubManagementApi } from './support/admin'

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

async function expectNoAxeViolations(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze()

  expect(results.violations).toEqual([])
}

async function openDepartments(page: Page): Promise<void> {
  await page.getByRole('banner').getByRole('link', { name: 'Departamentos' }).click()
  await expect(page.getByRole('heading', { name: 'Departamentos', level: 1 })).toBeVisible()
}

test.describe('departamentos y centro: administracion', () => {
  test.beforeEach(async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)
    await openDepartments(page)
  })

  test('crea un departamento y aparece en la lista', { tag: ['@RF-ID-03'] }, async ({ page }) => {
    await page.locator('[data-test="department-create-name"]').fill('Mantenimiento')
    await page.locator('[data-test="department-create-submit"]').click()

    await expect(
      page.getByRole('table').getByRole('rowheader', { name: 'Mantenimiento' }),
    ).toBeVisible()
    await expect(page.locator('[data-test="department-create-name"]')).toHaveValue('')
    await expectNoAxeViolations(page)
  })

  test(
    'un nombre repetido se explica junto al campo y no se crea',
    { tag: ['@RF-ID-03'] },
    async ({ page }) => {
      await page.locator('[data-test="department-create-name"]').fill('Pisos')
      await page.locator('[data-test="department-create-submit"]').click()

      await expect(page.locator('[data-test="department-create"]')).toContainText(
        'Ya existe un departamento con ese nombre.',
      )
      await expect(page.locator('[data-test="department-create-name"]')).toHaveAttribute(
        'aria-invalid',
        'true',
      )
      await expect(page.locator('[data-test="department-4"]')).toHaveCount(1)
    },
  )

  test(
    'renombra un departamento viendo el valor actual y el nuevo antes de confirmar',
    { tag: ['@RF-ID-03'] },
    async ({ page }) => {
      await page.locator('[data-test="rename-4"]').click()

      const dialog = page.getByRole('dialog')

      await dialog.locator('[data-test="rename-input"]').fill('Pisos y lavandería')
      await expect(dialog.locator('[data-test="rename-preview"]')).toContainText('Pisos')
      await expect(dialog.locator('[data-test="rename-preview"]')).toContainText(
        'Pisos y lavandería',
      )
      await expectNoAxeViolations(page)

      await dialog.getByRole('button', { name: 'Guardar nombre' }).click()

      await expect(dialog).toHaveCount(0)
      await expect(page.locator('[data-test="department-4"]')).toContainText('Pisos y lavandería')
    },
  )

  test(
    'muestra el centro con su zona horaria en solo lectura y renombra el centro',
    { tag: ['@RF-ID-03'] },
    async ({ page }) => {
      const site = page.locator('[data-test="site-section"]')

      await expect(site.locator('[data-test="site-name"]')).toContainText('Hotel Marina')
      await expect(site.locator('[data-test="site-timezone"]')).toHaveText('Europe/Madrid')
      await expect(site.locator('[data-test="site-timezone-note"]')).toBeVisible()
      await expect(site.getByRole('textbox')).toHaveCount(0)

      await site.locator('[data-test="site-rename"]').click()

      const dialog = page.getByRole('dialog')

      await dialog.locator('[data-test="rename-input"]').fill('Hotel Marina Playa')
      await dialog.getByRole('button', { name: 'Guardar nombre' }).click()

      await expect(dialog).toHaveCount(0)
      await expect(site.locator('[data-test="site-name"]')).toContainText('Hotel Marina Playa')
      await expect(site.locator('[data-test="site-timezone"]')).toHaveText('Europe/Madrid')
    },
  )
})

test.describe('departamentos: solo lectura', () => {
  test(
    'el auditor ve la lista sin alta, renombrado ni centro',
    { tag: ['@RF-ID-03'] },
    async ({ page }) => {
      await stubManagementApi(page, { role: 'auditor' })
      await logInAsAuditor(page)
      await openDepartments(page)

      await expect(page.locator('[data-test="department-3"]')).toBeVisible()
      await expect(page.locator('[data-test="department-create"]')).toHaveCount(0)
      await expect(page.locator('[data-test="rename-3"]')).toHaveCount(0)
      await expect(page.locator('[data-test="site-section"]')).toHaveCount(0)
      await expect(page.getByRole('combobox')).toHaveCount(0)
      await expectNoAxeViolations(page)
    },
  )
})
