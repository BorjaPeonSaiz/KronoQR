// Perfil de cumplimiento del centro (RF-PD-07, tarea 5.2). Hallazgo I5 del
// cierre de la Fase 5: la pantalla no tenia recorrido E2E propio.
//
// El backend no participa: aqui se prueba el recorrido por el panel con la
// API simulada en `support/admin.ts`. El rango de cada umbral y la
// autorizacion negativa por rol se prueban en el backend (regla dura 18); lo
// que se prueba aqui es que un cambio de umbral avisa antes de guardar y que
// un rol sin `settings:*` no ve la entrada ni puede llegar a la pantalla.
import { expect, test } from '@playwright/test'
import { logInAsAdmin, logInAsManager, stubManagementApi } from './support/admin'

test(
  'un responsable de departamento no ve «Cumplimiento» en la navegacion, ni puede llegar a la pantalla',
  { tag: ['@RF-PD-07'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'manager' })
    await logInAsManager(page)

    await expect(page.getByRole('link', { name: 'Cumplimiento' })).not.toBeVisible()

    // El enlace esta oculto, pero la URL sigue existiendo: quien la escribe a
    // mano no llega a la pantalla, y la guarda manda a la primera seccion a
    // su alcance (regla dura 18, la autorizacion real es del servidor).
    await page.goto('/compliance-profile')
    await expect(page).toHaveURL(/\/live$/)
    await expect(
      page.getByRole('heading', { level: 1, name: 'Perfil de cumplimiento' }),
    ).not.toBeVisible()
  },
)

test(
  'cambiar el descanso minimo avisa de que cambia que incidencias se abren, y persiste al guardar',
  { tag: ['@RF-PD-07'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    await page.getByRole('link', { name: 'Cumplimiento' }).click()
    await expect(
      page.getByRole('heading', { level: 1, name: 'Perfil de cumplimiento' }),
    ).toBeVisible()

    await expect(page.getByTestId('pending-detection-warning')).not.toBeVisible()

    await page.getByTestId('min-rest-hours').fill('10')
    await expect(page.getByTestId('pending-detection-warning')).toBeVisible()

    await page.getByTestId('save').click()
    await expect(page.getByTestId('saved')).toBeVisible()

    // El cambio persiste tras recargar: lo guardo el servidor.
    await page.reload()
    await expect(page.getByTestId('min-rest-hours')).toHaveValue('10')
  },
)
