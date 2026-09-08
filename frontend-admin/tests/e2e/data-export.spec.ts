// Exportación íntegra de datos (RF-PD-14, RL-20, ADR-019, regla dura 15): «Tus
// datos son tuyos» dentro de la pantalla «Licencia».
//
// El backend no participa: aquí se prueba el recorrido por el panel con la API
// simulada en `support/admin.ts`. Que los secretos no salgan, que el volumen
// no agote memoria y la autorización negativa por rol se prueban en el
// backend (regla dura 18).
import { expect, test } from '@playwright/test'
import {
  DATA_EXPORT_FILENAME,
  DATA_EXPORT_RUNNING,
  DATA_EXPORT_UUID,
  logInAsAdmin,
  stubManagementApi,
} from './support/admin'

test(
  'pedir la exportación, verla en curso, terminar y descargarla',
  { tag: ['@RF-PD-14', '@RL-20'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })

    await logInAsAdmin(page)
    await page.goto('/license')

    await expect(page.getByRole('heading', { level: 2, name: 'Tus datos son tuyos' })).toBeVisible()
    await expect(page.getByTestId('data-export')).toContainText(
      'Todavía no se ha pedido ninguna exportación',
    )

    await page.getByTestId('open-generate').click()

    const dialog = page.getByRole('dialog', { name: 'Generar la exportación completa' })
    await expect(dialog).toBeVisible()

    const warning = dialog.getByTestId('data-export-warning')
    await expect(warning).toBeVisible()
    await expect(warning).toHaveAttribute('role', 'alert')
    await expect(warning).toContainText('todos los datos personales de la plantilla')

    await dialog.getByRole('button', { name: 'Generar exportación', exact: true }).click()
    await expect(dialog).not.toBeVisible()

    // Recien pedida: en cola o generando, y no se puede pedir otra a la vez.
    await expect(page.getByTestId(`status-${DATA_EXPORT_UUID}`)).toBeVisible()
    await expect(page.getByTestId('open-generate')).toBeDisabled()
    await expect(page.getByTestId('generate-disabled-hint')).toBeVisible()
    await expect(page.getByTestId('active-notice')).toBeVisible()

    // El doble hace progresar la fila sola con el reloj de verdad: pending ->
    // running -> completed, exactamente lo que el sondeo de 5 s del panel
    // tiene que ir descubriendo sin recargar la página.
    await expect(page.getByTestId(`status-${DATA_EXPORT_UUID}`)).toHaveText('Generando', {
      timeout: 8_000,
    })
    await expect(page.getByTestId(`status-${DATA_EXPORT_UUID}`)).toHaveText(
      'Lista para descargar',
      { timeout: 10_000 },
    )
    await expect(page.getByTestId('active-notice')).not.toBeVisible()
    await expect(page.getByTestId('open-generate')).toBeEnabled()

    const download = page.waitForEvent('download')
    await page.getByTestId(`download-${DATA_EXPORT_UUID}`).click()
    const file = await download

    expect(file.suggestedFilename()).toBe(DATA_EXPORT_FILENAME)
  },
)

test(
  'con una exportación ya en curso, el botón de generar aparece deshabilitado con su explicación',
  { tag: ['@RF-PD-14'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin', dataExports: [DATA_EXPORT_RUNNING] })

    await logInAsAdmin(page)
    await page.goto('/license')

    await expect(page.getByTestId(`status-${DATA_EXPORT_UUID}`)).toHaveText('Generando')
    await expect(page.getByTestId('open-generate')).toBeDisabled()
    await expect(page.getByTestId('generate-disabled-hint')).toContainText(
      'No se puede pedir otra mientras la anterior sigue en curso',
    )
  },
)
