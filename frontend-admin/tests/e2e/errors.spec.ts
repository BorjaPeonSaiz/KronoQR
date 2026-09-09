// Historico de errores agrupado por huella (RF-PD-15, tarea 5.12).
//
// El backend no participa: aqui se prueba el recorrido por el panel con la API
// simulada en `support/errors.ts`. La autorizacion negativa por rol, el
// saneado en servidor y la agrupacion por huella se prueban en el backend
// (regla dura 18).
import { expect, test } from '@playwright/test'
import { ADMIN_USER, logInAsAdmin, stubManagementApi } from './support/admin'
import {
  ERROR_EVENT_ID,
  KIOSK_CRITICAL_ERROR,
  stubClientErrorsEndpoint,
  stubErrorEventsApi,
} from './support/errors'

test(
  'el IT del cliente encuentra el error provocado, con su origen, nivel y recuento',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)

    await logInAsAdmin(page)
    await page.goto('/errors')

    await expect(page.getByRole('heading', { level: 1, name: 'Errores' })).toBeVisible()

    const row = page.getByTestId('error-row')

    await expect(row).toBeVisible()
    await expect(row).toContainText('Crítico')
    await expect(row).toContainText('La cámara ha dejado de responder')
    await expect(row).toContainText('12')
  },
)

test(
  'filtrar por nivel vuelve a pedir la pagina con ese filtro',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)

    await logInAsAdmin(page)
    await page.goto('/errors')
    await expect(page.getByTestId('error-row')).toBeVisible()

    await page.getByLabel('Nivel').selectOption('critical')
    await expect(page.getByTestId('error-row')).toBeVisible()

    await page.getByLabel('Nivel').selectOption('error')
    await expect(page.getByTestId('error-row')).toHaveCount(0)
    await expect(page.getByText('Sin errores pendientes')).toBeVisible()
  },
)

test(
  'expandir la fila enseña el detalle, el bloque «que hacer», y el trace_id se copia',
  { tag: ['@RF-PD-15'] },
  async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write'])
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)

    await logInAsAdmin(page)
    await page.goto('/errors')

    await page.getByTestId(`toggle-${ERROR_EVENT_ID}`).click()

    const whatToDo = page.getByTestId('what-to-do')

    await expect(whatToDo).toBeVisible()
    await expect(whatToDo).toContainText('La tablet indicada no puede escanear')

    await page.getByTestId(`copy-trace-${ERROR_EVENT_ID}`).click()

    const clipboard = await page.evaluate(() => navigator.clipboard.readText())

    expect(clipboard).toBe(KIOSK_CRITICAL_ERROR.trace_id)
  },
)

test(
  'marcarlo como resuelto lo saca de la vista de pendientes',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)

    await logInAsAdmin(page)
    await page.goto('/errors')
    await expect(page.getByTestId('error-row')).toBeVisible()

    await page.getByTestId(`resolve-${ERROR_EVENT_ID}`).click()

    const dialog = page.getByRole('dialog', { name: 'Marcar como resuelto' })
    await expect(dialog).toBeVisible()

    await dialog.getByRole('button', { name: 'Marcar como resuelto' }).click()

    await expect(page.getByRole('dialog')).not.toBeVisible()
    await expect(page.getByTestId('error-row')).toHaveCount(0)
    await expect(page.getByText('Sin errores pendientes')).toBeVisible()
  },
)

// La ocultacion del boton de resolver para un acceso de soporte con alcance
// `diagnostics`/`read_only` (decision 8 de la ficha) se prueba a nivel de
// componente en `tests/unit/ErrorsView.spec.ts`, que puede controlar
// `abilities` con precision; `stubManagementApi` (`support/admin.ts`) solo
// modela los seis roles de aplicacion, ninguno de los cuales es un acceso de
// soporte. La autorizacion negativa real -que el servidor rechace con `403`
// a cualquier acceso de soporte, sea cual sea su alcance- se prueba en el
// backend (regla dura 18).

test(
  'un fallo al reportar un error de cliente no bloquea el panel ni reintenta en bucle',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)
    const clientErrors = stubClientErrorsEndpoint(page)

    await page.goto('/login')

    // Un error real, sin capturar por Playwright: se dispara en una macrotarea
    // aparte para que llegue al `window.onerror` de la propia pagina -ANTES de
    // iniciar sesion, para que el transporte lo vacie justo al pasar a
    // autenticado (decision 7 de la ficha 5.12), sin depender del intervalo
    // periodico de 60 s, que un E2E no puede esperar-.
    await page.evaluate(() => {
      window.setTimeout(() => {
        throw new Error('boom-e2e')
      }, 0)
    })

    await page.getByLabel(/Correo electrónico/).fill(ADMIN_USER.email)
    await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
    await page.getByRole('button', { name: 'Entrar' }).click()
    await page.waitForURL('**/employees')

    await expect.poll(() => clientErrors.count(), { timeout: 5_000 }).toBeGreaterThanOrEqual(1)

    // El panel sigue funcionando tras el 500: se puede seguir navegando.
    await page.goto('/errors')
    await expect(page.getByRole('heading', { level: 1, name: 'Errores' })).toBeVisible()

    const firstCount = clientErrors.count()

    // Sin reintento en bucle: unos segundos despues, el recuento no ha subido
    // (el siguiente intento no llega hasta el intervalo periodico de 60 s).
    await page.waitForTimeout(3_000)
    expect(clientErrors.count()).toBe(firstCount)
  },
)
