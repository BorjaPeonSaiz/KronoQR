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

    await expect(page.getByRole('link', { name: 'Perfil de cumplimiento' })).not.toBeVisible()

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

    await page.getByRole('link', { name: 'Perfil de cumplimiento' }).click()
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

// --- Aviso de RN-12, condicionado al fichaje de pausa (RF-AT-12, tarea 3.5) -

test(
  'con el fichaje de pausa desactivado, el aviso explica por que y enlaza a donde se activa',
  { tag: ['@RF-PD-07', '@RF-AT-12'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    await page.goto('/compliance-profile')

    await expect(page.getByTestId('break-suspended')).toContainText(
      'no abre incidencias mientras el fichaje de pausa esté desactivado',
    )
    await expect(
      page.getByTestId('break-suspended').getByRole('link', { name: /Ajustes operativos/ }),
    ).toBeVisible()
    // No promete lo que el ajuste desactivado no cumple.
    await expect(page.getByTestId('pending-detection-warning')).not.toBeVisible()
    await page.getByTestId('break-required-after-hours').fill('5')
    await expect(page.getByTestId('pending-detection-warning')).not.toBeVisible()
  },
)

test(
  'con el fichaje de pausa activado, el aviso confirma que RN-12 abre incidencias',
  { tag: ['@RF-PD-07', '@RF-AT-12'] },
  async ({ page }) => {
    await stubManagementApi(page, {
      role: 'admin',
      operationalSettings: { breakClocking: 'enabled' },
    })
    await logInAsAdmin(page)

    await page.goto('/compliance-profile')

    await expect(page.getByTestId('break-suspended')).toHaveText(
      'El tramo continuo sin pausa se evalúa y abre incidencias: el fichaje de pausa está activado en esta instalación.',
    )
    await page.getByTestId('break-required-after-hours').fill('5')
    await expect(page.getByTestId('pending-detection-warning')).toBeVisible()
  },
)
