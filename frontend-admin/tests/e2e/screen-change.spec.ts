// El cambio de pantalla se anuncia y se titula (WCAG 2.4.2, 2.4.3, 4.1.3; V7-PA-2, V7-PA-3).
//
// Un panel de una sola pagina no recarga: sin esto, quien usa un lector de pantalla
// activa un enlace del menu y no oye nada, y la pestaña sigue llamandose igual en las
// 22 pantallas. Al navegar con el menu el foco pasa al encabezado de la pantalla nueva
// —esa es la señal— y la region viva global no arrastra el texto de la anterior.
//
// Etiquetada con el requisito de la pantalla que recorre, como las pasadas de axe
// (`support/axeRoutes.ts`): el WCAG 2.2 AA del doc 01 §6.5 no tiene identificador
// en el catalogo, y desde la tarea 22.2 de la 2.3.0 toda prueba lleva etiqueta
// (`EveryTestIsTaggedTest`).
import { expect, test } from '@playwright/test'
import { logInAsAdmin, stubManagementApi } from './support/admin'

test(
  'cada pantalla pone su titulo a la pestaña',
  { tag: ['@RF-GP-01', '@RF-PA-01', '@RF-PA-05'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)
    const nav = page.getByRole('navigation', { name: 'Secciones del panel' })

    await expect(page).toHaveTitle(/^Plantilla – /)

    await nav.getByRole('link', { name: 'Presencia' }).click()
    await expect(page).toHaveTitle(/^Presencia en vivo – /)

    await nav.getByRole('link', { name: 'Incidencias' }).click()
    await expect(page).toHaveTitle(/^Bandeja de incidencias – /)
  },
)

test(
  'al cambiar de pantalla con el menu el foco va al encabezado y el anuncio viejo se retira',
  { tag: ['@RF-PA-06', '@RF-PA-01'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)
    const nav = page.getByRole('navigation', { name: 'Secciones del panel' })
    const liveRegion = page.locator('p.sr-only[role="status"]')

    await nav.getByRole('link', { name: 'Cumplimiento', exact: true }).click()
    await expect(page.getByRole('heading', { level: 1 })).toBeFocused()

    await nav.getByRole('link', { name: 'Presencia' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Presencia' })).toBeFocused()
    // La region viva global no conserva el texto de la pantalla anterior.
    await expect(liveRegion).not.toContainText('incumplimiento')
  },
)
