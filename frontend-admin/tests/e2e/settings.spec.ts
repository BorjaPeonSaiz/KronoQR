// Ajustes operativos de la instalacion: los cuatro umbrales `ATTENDANCE_*` y
// los dos idiomas `LOCALE_*` (RF-PD-01, tarea 5.13, hallazgo B1 del cierre de
// la Fase 5: hasta ahora no habia pantalla, aunque el contrato y
// `docs/cliente/configuracion.md` §6.0/§2.1 ya prometian que se editaban
// desde el panel).
//
// El backend no participa: aqui se prueba el recorrido por el panel con la
// API simulada en `support/admin.ts`. La autorizacion real es del servidor
// (regla dura 18); lo que se prueba aqui es que un rol sin `settings:*` no
// vea la entrada ni pueda llegar a la pantalla escribiendo la URL a mano.
import { expect, test } from '@playwright/test'
import { logInAsAdmin, logInAsAuditor, stubManagementApi } from './support/admin'

test(
  'un auditor no ve «Ajustes operativos» en la navegacion, ni puede llegar a la pantalla',
  { tag: ['@RF-PD-01'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'auditor' })
    await logInAsAuditor(page)

    await expect(page.getByRole('link', { name: 'Ajustes operativos' })).not.toBeVisible()

    // El enlace esta oculto, pero la URL sigue existiendo: quien la escribe a
    // mano no llega a la pantalla, y la guarda manda a la unica seccion que
    // alcanza un auditor -la exportacion para la Inspeccion- (regla dura 18,
    // la autorizacion real es del servidor).
    await page.goto('/settings')
    await expect(page).toHaveURL(/\/reports\/legal-export$/)
    await expect(
      page.getByRole('heading', { level: 1, name: 'Ajustes operativos' }),
    ).not.toBeVisible()
  },
)

test(
  'cambiar la ventana anti-rebote la guarda y avisa de que afecta a las horas calculadas',
  { tag: ['@RF-PD-01'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    await page.getByRole('link', { name: 'Ajustes operativos' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Ajustes operativos' })).toBeVisible()

    await expect(page.getByTestId('debounce-seconds')).toHaveValue('60')
    await expect(page.getByTestId('affects-worked-hours-warning')).not.toBeVisible()

    await page.getByTestId('debounce-seconds').fill('90')
    await expect(page.getByTestId('affects-worked-hours-warning')).toBeVisible()

    await page.getByTestId('save').click()

    await expect(page.getByTestId('saved')).toBeVisible()
    await expect(page.getByTestId('debounce-seconds')).toHaveValue('90')

    // El cambio persiste tras recargar: lo guardo el servidor, no solo el formulario.
    await page.reload()
    await expect(page.getByTestId('debounce-seconds')).toHaveValue('90')
  },
)

test(
  'un umbral fuera de rango se rechaza con el mensaje del servidor, sin perder lo escrito',
  { tag: ['@RF-PD-01'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    await page.goto('/settings')

    await page.getByTestId('max-shift-hours').fill('30')
    await page.getByTestId('save').click()

    await expect(page.getByRole('alert')).toContainText('Duración máxima de un tramo (h)')
    // Lo escrito NO se pierde: sigue en el campo para poder corregirlo.
    await expect(page.getByTestId('max-shift-hours')).toHaveValue('30')
  },
)

test(
  'el idioma por defecto no se puede desmarcar de los disponibles',
  { tag: ['@RF-PD-01'] },
  async ({ page }) => {
    await stubManagementApi(page, {
      role: 'admin',
      operationalSettings: { localeAvailable: ['es'] },
    })
    await logInAsAdmin(page)

    await page.goto('/settings')

    // «es» es el idioma por defecto: la casilla esta deshabilitada, no se
    // puede dejar la instalacion sin ningun idioma disponible ni con el
    // idioma por defecto fuera de la lista.
    await expect(page.getByTestId('locale-available-es')).toBeChecked()
    await expect(page.getByTestId('locale-available-es')).toBeDisabled()
  },
)

test(
  'añadir el ingles a los idiomas disponibles y guardarlo se refleja al recargar',
  { tag: ['@RF-PD-01'] },
  async ({ page }) => {
    await stubManagementApi(page, {
      role: 'admin',
      operationalSettings: { localeAvailable: ['es'] },
    })
    await logInAsAdmin(page)

    await page.goto('/settings')

    await page.getByTestId('locale-available-en').check()
    await page.getByTestId('save').click()

    await expect(page.getByTestId('saved')).toBeVisible()

    await page.reload()
    await expect(page.getByTestId('locale-available-en')).toBeChecked()
  },
)

// --- Codigo de servicio del quiosco (RF-KI-08, tarea 3.3) -------------------
//
// Abre la pantalla de diagnostico de la tablet (`frontend-kiosk`); aqui solo
// se prueba el lado del panel: que se guarda, que vacio sigue siendo valido y
// que una forma invalida no llega a mandarse al servidor.

test(
  'guardar el codigo de servicio del quiosco persiste tras recargar',
  { tag: ['@RF-KI-08'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    await page.goto('/settings')

    await page.getByTestId('kiosk-service-code').fill('48392017')
    await page.getByTestId('save').click()

    await expect(page.getByTestId('saved')).toBeVisible()

    await page.reload()
    await expect(page.getByTestId('kiosk-service-code')).toHaveValue('48392017')
  },
)

test(
  'un codigo con letras o fuera de 8-12 cifras no deja guardar, sin llegar al servidor',
  { tag: ['@RF-KI-08'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    await page.goto('/settings')

    await page.getByTestId('kiosk-service-code').fill('12A45')

    await expect(page.getByText('Escribe un código de 8 a 12 cifras')).toBeVisible()
    await expect(page.getByTestId('save')).toBeDisabled()
  },
)

test(
  'vaciar el codigo de servicio del quiosco es un cambio valido: la pantalla se abre sin el',
  { tag: ['@RF-KI-08'] },
  async ({ page }) => {
    await stubManagementApi(page, {
      role: 'admin',
      operationalSettings: { kioskServiceCode: '48392017' },
    })
    await logInAsAdmin(page)

    await page.goto('/settings')

    await expect(page.getByTestId('kiosk-service-code')).toHaveValue('48392017')

    await page.getByTestId('kiosk-service-code').fill('')
    await page.getByTestId('save').click()

    await expect(page.getByTestId('saved')).toBeVisible()

    await page.reload()
    await expect(page.getByTestId('kiosk-service-code')).toHaveValue('')
  },
)
