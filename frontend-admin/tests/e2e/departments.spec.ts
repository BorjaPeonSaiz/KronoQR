// Departamentos y centro desde el panel (RF-ID-03, R3-PA-04, bloque 21 de la 2.2.0).
//
// LOS RECORRIDOS QUE IMPORTAN: quien administra crea un departamento y lo
// renombra viendo «antes → despues»; un nombre repetido se explica junto al
// campo; el nombre del centro se edita y su zona horaria se ve sin poder
// cambiarse mientras no haya fichajes (RN-23). El auditor ya no llega aqui
// (bloque 25): lo cubre `auditor.spec.ts`.
//
// El backend no participa: la API va simulada con las formas del contrato
// (`support/admin.ts`). Que `rrhh` no pueda asignar responsable o que el nombre
// sea unico dentro del centro lo prueba el backend.
//
// Con `data-test` y no con etiquetas exactas: las etiquetas de `FormField`
// llevan un asterisco aria-hidden.
import { expect, type Page, test } from '@playwright/test'
import { logInAsAdmin, SITE, stubManagementApi } from './support/admin'
import { expectNoBlockingViolations } from './support/axe'

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
    await expectNoBlockingViolations(page, { failOn: 'any' })
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
      await expectNoBlockingViolations(page, { failOn: 'any' })
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
      await expectNoBlockingViolations(page, { failOn: 'any' })

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
      await expectNoBlockingViolations(page, { failOn: 'any' })
      await dialog.getByRole('button', { name: 'Guardar nombre' }).click()

      await expect(dialog).toHaveCount(0)
      await expect(site.locator('[data-test="site-name"]')).toContainText('Hotel Marina Playa')
      await expect(site.locator('[data-test="site-timezone"]')).toHaveText('Europe/Madrid')
    },
  )
})

test.describe('centro: zona horaria (RN-23)', () => {
  test(
    'con la zona libre se cambia desde un dialogo con antes y despues',
    { tag: ['@RN-23'] },
    async ({ page }) => {
      await stubManagementApi(page, { role: 'admin' })
      await logInAsAdmin(page)
      await openDepartments(page)

      const site = page.locator('[data-test="site-section"]')

      await expect(site.locator('[data-test="site-timezone-note"]')).toContainText(
        'hasta el primer fichaje',
      )
      await site.locator('[data-test="site-change-timezone"]').click()

      const dialog = page.getByRole('dialog')

      await dialog.locator('[data-test="timezone-input"]').fill('Atlantic/Canary')
      await expect(dialog.locator('[data-test="timezone-preview"]')).toContainText('Europe/Madrid')
      await expect(dialog.locator('[data-test="timezone-preview"]')).toContainText(
        'Atlantic/Canary',
      )
      await expectNoBlockingViolations(page, { failOn: 'any' })

      await dialog.getByRole('button', { name: 'Cambiar zona' }).click()

      await expect(dialog).toHaveCount(0)
      await expect(site.locator('[data-test="site-timezone"]')).toHaveText('Atlantic/Canary')
    },
  )

  test(
    'si el primer fichaje llega con el dialogo abierto, el 409 se explica y la zona queda fija',
    { tag: ['@RN-23'] },
    async ({ page }) => {
      await stubManagementApi(page, { role: 'admin' })
      await logInAsAdmin(page)
      await openDepartments(page)

      const site = page.locator('[data-test="site-section"]')

      await site.locator('[data-test="site-change-timezone"]').click()

      const dialog = page.getByRole('dialog')

      await dialog.locator('[data-test="timezone-input"]').fill('Atlantic/Canary')

      // Entre tanto se registra el primer fichaje: el servidor ya la da por fija.
      await page.route('**/api/v1/site', async (route) => {
        const patching = route.request().method() === 'PATCH'

        await route.fulfill({
          status: patching ? 409 : 200,
          contentType: patching ? 'application/problem+json' : 'application/json',
          body: JSON.stringify(
            patching
              ? {
                  type: 'urn:kronoqr:problem:site-time-zone-locked',
                  title: 'Zona horaria fija',
                  status: 409,
                }
              : { ...SITE, timezone_locked: true },
          ),
        })
      })
      await dialog.getByRole('button', { name: 'Cambiar zona' }).click()

      await expect(dialog).toHaveCount(0)
      await expect(site.locator('[data-test="site-timezone-locked-error"]')).toContainText(
        'ya no se puede cambiar',
      )
      await expect(site.locator('[data-test="site-change-timezone"]')).toHaveCount(0)
      await expect(site.locator('[data-test="site-timezone"]')).toHaveText('Europe/Madrid')
    },
  )

  test(
    'con la zona fija no hay boton ni campo y se explica por que',
    { tag: ['@RN-23'] },
    async ({ page }) => {
      await stubManagementApi(page, { role: 'admin', siteTimezoneLocked: true })
      await logInAsAdmin(page)
      await openDepartments(page)

      const site = page.locator('[data-test="site-section"]')

      await expect(site.locator('[data-test="site-timezone"]')).toHaveText('Europe/Madrid')
      await expect(site.locator('[data-test="site-change-timezone"]')).toHaveCount(0)
      await expect(site.getByRole('textbox')).toHaveCount(0)
      await expect(site.locator('[data-test="site-timezone-note"]')).toContainText(
        'ya no se puede cambiar',
      )
      await expectNoBlockingViolations(page, { failOn: 'any' })
    },
  )
})
