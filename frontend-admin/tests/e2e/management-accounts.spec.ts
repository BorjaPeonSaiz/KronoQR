// Cuentas de gestion desde el panel (RF-ID-10, RF-ID-02, bloque 12c de la 2.2.0).
//
// LOS RECORRIDOS QUE IMPORTAN: el administrador da de alta una cuenta y ve la
// contrasena temporal UNA vez; da de baja y restablece con motivo y
// reautenticandose con su segundo factor; no puede hacer nada de eso sobre su
// propia cuenta; y quien entra con una contrasena temporal no puede salir de la
// pantalla de cambio hasta fijar la suya.
//
// El backend no participa: la API va simulada con las formas del contrato
// (`support/admin.ts`). Que la baja cierre las sesiones, que la ultima admin no
// pueda darse de baja o que la contrasena temporal caduque lo prueba el backend.
import AxeBuilder from '@axe-core/playwright'
import { expect, type Page, test } from '@playwright/test'
import {
  ACCOUNT_COOK,
  ACCOUNT_COOK_UUID,
  ACCOUNT_OWN,
  logInAsAdmin,
  stubManagementApi,
  TEMPORARY_LOGIN_PASSWORD,
  TEMPORARY_PASSWORD,
  TOTP_CODE,
} from './support/admin'

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

async function expectNoAxeViolations(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze()

  expect(results.violations).toEqual([])
}

test.describe('administracion de cuentas', () => {
  test.beforeEach(async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)
    await page.getByRole('banner').getByRole('link', { name: 'Cuentas' }).click()
    await expect(page.getByRole('heading', { name: 'Cuentas de gestión', level: 1 })).toBeVisible()
  })

  test(
    'lista las cuentas con sus siete columnas, la zona horaria y sin ningun secreto',
    { tag: ['@RF-ID-10'] },
    async ({ page }) => {
      const table = page.getByRole('table')

      for (const heading of [
        'Nombre',
        'Correo',
        'Rol',
        'Estado',
        'Segundo factor',
        'Contraseña',
        'Último acceso',
      ]) {
        await expect(table.getByRole('columnheader', { name: heading })).toBeVisible()
      }

      await expect(table.getByRole('columnheader', { name: /Último acceso/ })).toContainText(
        'Europe/Madrid',
      )
      await expect(table.getByRole('rowheader')).toHaveCount(3)
      await expect(page.locator('main')).not.toContainText(TEMPORARY_PASSWORD)

      await expectNoAxeViolations(page)
    },
  )

  test(
    'sobre la propia cuenta las acciones se explican y no abren nada',
    { tag: ['@RF-ID-10'] },
    async ({ page }) => {
      const own = page.getByTestId(`account-${ACCOUNT_OWN.uuid}`)
      const deactivate = own.getByRole('button', { name: /Dar de baja/ })

      await expect(own).toContainText('(tu cuenta)')
      await expect(deactivate).toHaveAttribute('aria-disabled', 'true')
      await expect(deactivate).toBeEnabled()
      await expect(own).toContainText('Es tu cuenta')

      await deactivate.click()

      await expect(page.getByRole('dialog')).toHaveCount(0)
    },
  )

  test(
    'el alta enseña la contrasena temporal una sola vez y exige confirmar la entrega',
    { tag: ['@RF-ID-10', '@RF-ID-02'] },
    async ({ page }) => {
      await page.getByTestId('create-account').click()

      const dialog = page.getByRole('dialog', { name: 'Nueva cuenta de gestión' })

      await dialog.getByLabel('Nombre').fill('Dirección de Recepción')
      await dialog.getByLabel('Correo electrónico').fill('recepcion@hotel.example')
      await dialog.getByLabel('Rol').selectOption('rrhh')
      await dialog.getByLabel(/Tu código del segundo factor/).fill('000000')
      await dialog.getByRole('button', { name: 'Crear cuenta' }).click()

      // Un codigo incorrecto se explica junto al campo y no cierra el alta.
      await expect(dialog.getByText('El código no es correcto.')).toBeVisible()

      await dialog.getByLabel(/Tu código del segundo factor/).fill(TOTP_CODE)
      await dialog.getByRole('button', { name: 'Crear cuenta' }).click()

      const reveal = page.getByRole('dialog', { name: 'Contraseña temporal' })

      await expect(reveal.getByTestId('password-value')).toHaveText(TEMPORARY_PASSWORD)
      await expect(reveal).toContainText('una sola vez')
      await expectNoAxeViolations(page)

      // No se cierra por descuido: ni con Escape ni sin la casilla.
      await page.keyboard.press('Escape')
      await expect(reveal).toBeVisible()
      await expect(reveal.getByTestId('acknowledge')).toBeDisabled()

      await reveal.getByLabel(/La he entregado en mano/).check()
      await reveal.getByTestId('acknowledge').click()

      await expect(reveal).toHaveCount(0)
      await expect(page.locator('body')).not.toContainText(TEMPORARY_PASSWORD)
      await expect(page.getByRole('rowheader', { name: 'Dirección de Recepción' })).toBeVisible()

      // Ni en el almacenamiento del navegador.
      const stored = await page.evaluate(() =>
        JSON.stringify([
          ...Object.entries(window.sessionStorage),
          ...Object.entries(window.localStorage),
        ]),
      )

      expect(stored).not.toContain(TEMPORARY_PASSWORD)
    },
  )

  test(
    'un correo ya usado se explica en el campo y no crea nada',
    { tag: ['@RF-ID-10'] },
    async ({ page }) => {
      await page.getByTestId('create-account').click()

      const dialog = page.getByRole('dialog', { name: 'Nueva cuenta de gestión' })

      await dialog.getByLabel('Nombre').fill('Otra persona')
      await dialog.getByLabel('Correo electrónico').fill(ACCOUNT_COOK.email)
      await dialog.getByLabel(/Tu código del segundo factor/).fill(TOTP_CODE)
      await dialog.getByRole('button', { name: 'Crear cuenta' }).click()

      await expect(dialog.getByText(/Ya hay una cuenta con este correo/)).toBeVisible()
    },
  )

  test(
    'la baja enseña que cambia, exige motivo y deja la cuenta visible como de baja',
    { tag: ['@RF-ID-10'] },
    async ({ page }) => {
      await page.getByTestId(`deactivate-${ACCOUNT_COOK_UUID}`).click()

      const dialog = page.getByRole('dialog', { name: 'Dar de baja la cuenta' })
      const confirm = dialog.getByRole('button', { name: 'Dar de baja', exact: true })

      // El antes y el despues, en una tabla con encabezados.
      const change = dialog.getByRole('row', { name: /Estado/ })

      await expect(change).toContainText('Activa')
      await expect(change).toContainText('De baja')
      await expect(confirm).toBeDisabled()
      await expectNoAxeViolations(page)

      await dialog.getByLabel('Motivo').fill('Deja el hotel al final de la temporada')
      await confirm.click()

      await expect(dialog).toHaveCount(0)

      // No se borra: sigue en la lista, de baja y sin acciones.
      const row = page.getByTestId(`account-${ACCOUNT_COOK_UUID}`)

      await expect(row).toContainText('De baja')
      await expect(row).toContainText('Sin acciones')
      await expect(page.getByRole('status').filter({ hasText: 'dada de baja' })).toHaveCount(1)
    },
  )

  test(
    'restablecer la contrasena pide motivo y segundo factor y enseña la nueva una vez',
    { tag: ['@RF-ID-10'] },
    async ({ page }) => {
      await page.getByTestId(`resetPassword-${ACCOUNT_COOK_UUID}`).click()

      const dialog = page.getByRole('dialog', { name: 'Restablecer la contraseña' })

      await dialog.getByLabel('Motivo').fill('Olvido')
      await dialog.getByLabel(/Tu código del segundo factor/).fill(TOTP_CODE)
      await dialog.getByRole('button', { name: 'Restablecer contraseña' }).click()

      const reveal = page.getByRole('dialog', { name: 'Contraseña temporal' })

      await expect(reveal.getByTestId('password-value')).toHaveText(TEMPORARY_PASSWORD)

      await reveal.getByLabel(/La he entregado en mano/).check()
      await reveal.getByTestId('acknowledge').click()

      await expect(page.getByTestId(`account-${ACCOUNT_COOK_UUID}`)).toContainText('Temporal')
    },
  )

  test(
    'retirar el segundo factor pide motivo y deja la cuenta sin el',
    { tag: ['@RF-ID-10'] },
    async ({ page }) => {
      await page.getByTestId(`resetTwoFactor-${ACCOUNT_COOK_UUID}`).click()

      const dialog = page.getByRole('dialog', { name: 'Restablecer el segundo factor' })

      await dialog.getByLabel('Motivo').fill('Teléfono extraviado')
      await dialog.getByLabel(/Tu código del segundo factor/).fill(TOTP_CODE)
      await dialog.getByRole('button', { name: 'Retirar segundo factor' }).click()

      await expect(dialog).toHaveCount(0)

      const row = page.getByTestId(`account-${ACCOUNT_COOK_UUID}`)

      await expect(row).toContainText('No activo')

      // Ya no hay nada que retirar: la accion se explica y no abre el dialogo.
      await expect(row.getByTestId(`resetTwoFactor-${ACCOUNT_COOK_UUID}`)).toHaveAttribute(
        'aria-disabled',
        'true',
      )
    },
  )

  test('filtra por rol y por estado en el servidor', { tag: ['@RF-ID-10'] }, async ({ page }) => {
    await page.getByLabel('Estado').selectOption('deactivated')

    await expect(page.getByRole('rowheader')).toHaveCount(1)
    await expect(page.getByRole('rowheader')).toContainText('Ex Recepción')

    await page.getByRole('button', { name: 'Limpiar filtros' }).click()
    await page.getByLabel('Rol').selectOption('admin')

    await expect(page.getByRole('rowheader')).toHaveCount(1)
    await expect(page.getByRole('rowheader')).toContainText(ACCOUNT_OWN.name)
  })
})

test.describe('quien no es administrador', () => {
  test(
    'no ve «Cuentas» ni llega a la pantalla por URL',
    { tag: ['@RF-ID-10'] },
    async ({ page }) => {
      await stubManagementApi(page)
      await page.goto('/login')
      await page.getByLabel(/Correo electrónico/).fill('rrhh@hotel.example')
      await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
      await page.getByRole('button', { name: 'Entrar' }).click()
      await page.waitForURL('**/employees')

      await expect(page.getByRole('banner').getByRole('link', { name: 'Cuentas' })).toHaveCount(0)

      await page.goto('/accounts')

      await expect(page).not.toHaveURL(/\/accounts$/)
    },
  )
})

test.describe('contraseña temporal', () => {
  test.beforeEach(async ({ page }) => {
    await stubManagementApi(page, { role: 'admin', temporaryPassword: true })
    await page.goto('/login')
    await page.getByLabel(/Correo electrónico/).fill('direccion@hotel.example')
    await page.getByLabel(/Contraseña/).fill(TEMPORARY_LOGIN_PASSWORD)
    await page.getByRole('button', { name: 'Entrar' }).click()
    await page.waitForURL('**/account/password')
  })

  test(
    'solo deja cambiarla o cerrar sesión: ni menú ni otras pantallas',
    { tag: ['@RF-ID-10'] },
    async ({ page }) => {
      await expect(
        page.getByRole('heading', { name: 'Elige tu contraseña', level: 1 }),
      ).toBeVisible()
      await expect(page.getByRole('banner').getByRole('navigation')).toHaveCount(0)
      await expect(page.getByRole('button', { name: 'Cerrar sesión' })).toBeVisible()

      await page.goto('/employees')

      await expect(page).toHaveURL(/\/account\/password$/)
      await expectNoAxeViolations(page)
    },
  )

  test(
    'una contraseña actual incorrecta se explica en el campo y no cierra la sesión',
    { tag: ['@RF-ID-10'] },
    async ({ page }) => {
      await page.getByLabel('Contraseña actual').fill('no-es-esta')
      await page.getByLabel('Contraseña nueva', { exact: true }).fill('Una-propia-larga-9!')
      await page.getByLabel('Repite la contraseña nueva').fill('Una-propia-larga-9!')
      await page.getByRole('button', { name: 'Cambiar contraseña' }).click()

      await expect(page.getByText('La contraseña actual no es correcta.')).toBeVisible()
      await expect(page).toHaveURL(/\/account\/password$/)
    },
  )

  test('al cambiarla entra en el panel', { tag: ['@RF-ID-10'] }, async ({ page }) => {
    await page.getByLabel('Contraseña actual').fill(TEMPORARY_LOGIN_PASSWORD)
    await page.getByLabel('Contraseña nueva', { exact: true }).fill('Una-propia-larga-9!')
    await page.getByLabel('Repite la contraseña nueva').fill('Una-propia-larga-9!')
    await page.getByRole('button', { name: 'Cambiar contraseña' }).click()

    await page.waitForURL('**/employees')
    await expect(page.getByRole('banner').getByRole('link', { name: 'Plantilla' })).toBeVisible()
  })
})
