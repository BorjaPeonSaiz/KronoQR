// Soporte (RF-PD-09, RF-PD-11, ADR-020): el paquete de diagnostico y los
// accesos temporales de soporte.
//
// El backend no participa: aqui se prueba el recorrido por el panel con la API
// simulada en `support/admin.ts`. Que el contenido del paquete vaya
// anonimizado, que un token de soporte no pueda conceder ni revocar accesos, y
// la autorizacion negativa por rol se prueban en el backend (regla dura 18).
import { expect, test } from '@playwright/test'
import {
  DIAGNOSTICS_BUNDLE_FILENAME,
  ISSUED_SUPPORT_TOKEN,
  SUPPORT_GRANT_ACTIVE,
  SUPPORT_GRANT_UUID,
  logInAsAdmin,
  logInAsManager,
  stubManagementApi,
} from './support/admin'

test(
  'quien no es admin no ve «Soporte» en el menu, ni puede llegar a la pantalla',
  { tag: ['@RF-PD-09', '@RF-PD-11'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'manager' })

    await logInAsManager(page)

    await expect(page.getByRole('link', { name: 'Soporte' })).not.toBeVisible()

    // El enlace esta oculto, pero la URL sigue existiendo: quien la escribe a
    // mano no llega a la pantalla, y la guarda la manda a la primera seccion
    // a su alcance -la presencia, para un `responsable_departamento`- (regla
    // dura 18, la autorizacion real es del servidor).
    await page.goto('/support')
    await expect(page).toHaveURL(/\/live$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Soporte' })).not.toBeVisible()
  },
)

test(
  'generar y descargar el paquete trae el fichero con el nombre de la cabecera',
  { tag: ['@RF-PD-09'] },
  async ({ page }) => {
    const api = await stubManagementApi(page, { role: 'admin' })

    await logInAsAdmin(page)
    await page.goto('/support')

    const download = page.waitForEvent('download')

    await page.getByTestId('generate-bundle').click()

    const file = await download

    expect(file.suggestedFilename()).toBe(DIAGNOSTICS_BUNDLE_FILENAME)

    await expect(page.getByTestId('diagnostics-success')).toContainText(DIAGNOSTICS_BUNDLE_FILENAME)

    // Por defecto, sin marcar la casilla: el paquete anonimizado (RL-19).
    const request = api.requests.find((it) => it.path === '/api/v1/diagnostics/bundle')

    expect(request).toBeDefined()
    expect(
      (request?.body as { include_personal_data?: boolean } | null)?.include_personal_data,
    ).toBe(false)
  },
)

test(
  'marcar «Incluir datos personales» muestra el aviso y lo manda al servidor',
  { tag: ['@RF-PD-09', '@RL-19'] },
  async ({ page }) => {
    const api = await stubManagementApi(page, { role: 'admin' })

    await logInAsAdmin(page)
    await page.goto('/support')

    await expect(page.getByTestId('personal-data-warning')).not.toBeVisible()
    await expect(page.getByTestId('include-personal-data')).not.toBeChecked()

    await page.getByTestId('include-personal-data').check()

    const warning = page.getByTestId('personal-data-warning')

    await expect(warning).toBeVisible()
    await expect(warning).toHaveAttribute('role', 'alert')
    await expect(page.getByTestId('period-days')).toBeVisible()

    const download = page.waitForEvent('download')

    await page.getByTestId('generate-bundle').click()
    await download

    const request = api.requests.find((it) => it.path === '/api/v1/diagnostics/bundle')

    expect(
      (request?.body as { include_personal_data?: boolean } | null)?.include_personal_data,
    ).toBe(true)
  },
)

test(
  'conceder un acceso muestra el token una sola vez y lo lista como activa',
  { tag: ['@RF-PD-11'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })

    await logInAsAdmin(page)
    await page.goto('/support')

    await expect(page.getByTestId('issued-token')).not.toBeVisible()

    await page.getByLabel('Motivo').fill('Incidencia #123: la cola no vacía')
    await page.getByRole('button', { name: 'Conceder acceso' }).click()

    const tokenBox = page.getByTestId('issued-token')

    await expect(tokenBox).toBeVisible()
    await expect(page.getByTestId('token-value')).toHaveText(ISSUED_SUPPORT_TOKEN)

    // Y ya aparece en la lista, activa.
    await expect(page.getByRole('table')).toContainText('Incidencia #123: la cola no vacía')
    await expect(page.getByRole('table')).toContainText('Activa')
  },
)

test(
  'revocar pide confirmacion y deja la fila como revocada',
  { tag: ['@RF-PD-11'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin', supportGrants: [SUPPORT_GRANT_ACTIVE] })

    await logInAsAdmin(page)
    await page.goto('/support')

    await expect(page.getByRole('table')).toContainText('Activa')

    await page.getByTestId(`revoke-${SUPPORT_GRANT_UUID}`).click()

    const dialog = page.getByRole('dialog', { name: 'Revocar acceso de soporte' })
    await expect(dialog).toBeVisible()

    await dialog.getByRole('button', { name: 'Revocar' }).click()

    await expect(page.getByRole('dialog')).not.toBeVisible()
    await expect(page.getByRole('table')).toContainText('Revocada')
    await expect(page.getByTestId(`revoke-${SUPPORT_GRANT_UUID}`)).not.toBeVisible()
  },
)
