// Un boton en vuelo no suelta el foco (V21-7-PA-1): con `disabled`, el navegador lo deja en
// `<body>` en cuanto se bloquea el boton —justo al pulsar «Guardar»— y el siguiente Tab
// vuelve a empezar la pagina por el enlace de salto. Con `aria-disabled` + `aria-busy` el
// foco se queda en el boton, el lector lo anuncia como no disponible y el clic se ignora.
//
// El caso real, no una simulacion: el `PATCH` se retiene hasta que la prueba lo suelta.
//
// Etiquetada con el requisito de la pantalla que recorre, como las pasadas de axe
// (`support/axeRoutes.ts`): el WCAG 2.2 AA del doc 01 §6.5 no tiene identificador
// en el catalogo, y desde la tarea 22.2 de la 2.3.0 toda prueba lleva etiqueta
// (`EveryTestIsTaggedTest`).
import { expect, test } from '@playwright/test'
import { logInAsAdmin, stubManagementApi } from './support/admin'
import { confirmChanges } from './support/confirm'

test(
  '«Guardar» en vuelo conserva el foco, no se pulsa dos veces y el Tab sigue dentro del dialogo',
  { tag: ['@RF-PD-01'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    let patches = 0

    // La ultima ruta registrada manda; `fallback()` cede a la del doble de la API.
    await page.route('**/api/v1/settings', async (route) => {
      if (route.request().method() === 'PATCH') {
        patches += 1
        await gate
      }

      await route.fallback()
    })

    await page.getByRole('link', { name: 'Ajustes operativos' }).click()
    await page.getByTestId('debounce-seconds').fill('90')
    await page.getByTestId('save').click()
    await confirmChanges(page)

    const confirm = page.getByTestId('confirm-dialog-confirm')

    await expect(confirm).toHaveAttribute('aria-disabled', 'true')
    await expect(confirm).toHaveAttribute('aria-busy', 'true')
    await expect(confirm).toBeFocused()

    // Un segundo clic (o Enter) no repite el envio.
    await confirm.click({ force: true })
    await page.keyboard.press('Enter')
    await page.keyboard.press('Tab')
    await expect(page.locator('body')).not.toBeFocused()
    await expect(page.getByRole('dialog')).toContainText('Ajustes')

    release()

    await expect(page.getByTestId('saved')).toBeVisible()
    expect(patches).toBe(1)
  },
)
