// Confirma el dialogo «antes -> despues» que abren el perfil de cumplimiento y
// los ajustes operativos antes de guardar (R3-PA-09). Se localiza por el
// `data-test` del boton del dialogo, no por su texto ni por la etiqueta de
// ningun campo.
import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'

export async function confirmChanges(page: Page): Promise<void> {
  await expect(page.getByTestId('change-preview')).toBeVisible()
  await page.getByTestId('confirm-dialog-confirm').click()
}

export async function cancelChanges(page: Page): Promise<void> {
  await page.getByTestId('confirm-dialog-cancel').click()
}
