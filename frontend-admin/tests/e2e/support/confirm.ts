// Confirma el dialogo «antes -> despues» que abren el perfil de cumplimiento y
// los ajustes operativos antes de guardar (R3-PA-09). Se localiza por rol y por
// el boton, no por la etiqueta de ningun campo.
import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'

export async function confirmChanges(page: Page): Promise<void> {
  const preview = page.getByTestId('change-preview')

  await expect(preview).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar y guardar' }).click()
}
