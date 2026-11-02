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
  DIAGNOSTICS_SEALED_BUNDLE_FILENAME,
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
  'el paquete anonimizado se descarga como .json y se puede abrir y revisar',
  { tag: ['@RF-PD-09', '@RL-19'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })

    await logInAsAdmin(page)
    await page.goto('/support')

    const download = page.waitForEvent('download')

    await page.getByTestId('generate-bundle').click()

    const file = await download

    expect(file.suggestedFilename()).toMatch(/^kronoqr-diagnostics-.+\.json$/)
    expect(file.suggestedFilename()).not.toContain('.sealed.')
    await expect(page.getByTestId('diagnostics-success')).toContainText(
      'revísalo antes de enviarlo',
    )
    await expect(page.getByTestId('sealing-unavailable')).not.toBeVisible()
  },
)

test(
  'con datos personales avisa de que sale cifrado y descarga el .sealed.json',
  { tag: ['@RF-PD-09', '@RL-19'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })

    await logInAsAdmin(page)
    await page.goto('/support')

    await page.getByTestId('include-personal-data').check()

    await expect(page.getByTestId('personal-data-warning')).toContainText('cifrado')
    await expect(page.getByTestId('personal-data-warning')).toContainText('anonimizado')

    const download = page.waitForEvent('download')

    await page.getByTestId('generate-bundle').click()

    const file = await download

    expect(file.suggestedFilename()).toBe(DIAGNOSTICS_SEALED_BUNDLE_FILENAME)
    await expect(page.getByTestId('diagnostics-success')).toContainText(
      DIAGNOSTICS_SEALED_BUNDLE_FILENAME,
    )
    await expect(page.getByTestId('diagnostics-success')).toContainText('cifrado')
  },
)

test(
  'sin clave de soporte el 503 de sellado dice que envies el anonimizado, sin reintento',
  { tag: ['@RF-PD-09', '@RL-19'] },
  async ({ page }) => {
    const api = await stubManagementApi(page, { role: 'admin', sealingOutcome: 'unavailable' })

    await logInAsAdmin(page)
    await page.goto('/support')

    await page.getByTestId('include-personal-data').check()
    await page.getByTestId('generate-bundle').click()

    const notice = page.getByTestId('sealing-unavailable')

    await expect(notice).toBeVisible()
    await expect(notice).toHaveAttribute('role', 'alert')
    await expect(notice).toContainText('no puede generar el paquete con datos personales')
    await expect(notice).toContainText('paquete anonimizado')
    await expect(page.getByTestId('diagnostics-success')).not.toBeVisible()

    // Sin reintento automatico: una sola peticion.
    expect(api.requests.filter((it) => it.path === '/api/v1/diagnostics/bundle')).toHaveLength(1)

    // El anonimizado sigue saliendo.
    await page.getByTestId('include-personal-data').uncheck()

    const download = page.waitForEvent('download')

    await page.getByTestId('generate-bundle').click()

    expect((await download).suggestedFilename()).toBe(DIAGNOSTICS_BUNDLE_FILENAME)
    await expect(notice).not.toBeVisible()
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

test(
  'ver los usos de un acceso lista las ventanas de 15 minutos, pagina y se cierra con Escape',
  { tag: ['@RF-PD-11'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin', supportGrants: [SUPPORT_GRANT_ACTIVE] })

    await logInAsAdmin(page)
    await page.goto('/support')

    await page.getByTestId(`uses-${SUPPORT_GRANT_UUID}`).click()

    const dialog = page.getByRole('dialog', { name: 'Usos del acceso de soporte' })

    await expect(dialog).toBeVisible()
    await expect(dialog.getByTestId('grant-uses-note')).toContainText('ventana de 15 minutos')
    await expect(dialog.getByTestId('grant-use-row')).toHaveCount(25)
    await expect(dialog.getByRole('columnheader', { name: 'Inicio de la ventana' })).toBeVisible()

    await dialog.getByRole('button', { name: 'Siguiente' }).click()

    await expect(dialog.getByTestId('grant-use-row')).toHaveCount(5)

    await page.keyboard.press('Escape')

    await expect(dialog).not.toBeVisible()
    // El foco vuelve al boton que abrio el dialogo.
    await expect(page.getByTestId(`uses-${SUPPORT_GRANT_UUID}`)).toBeFocused()
  },
)
