// La marca de la instalacion (RF-PD-08, tarea 5.8): solo `admin` la ve
// (ambito `settings:*`, igual que el perfil de cumplimiento y los quioscos).
//
// Lo que se comprueba aqui es lo que le pasa a una persona: escribe un color
// que no se lee bien y la pantalla lo dice sin impedir guardar; cambia el
// nombre, lo guarda, y la cabecera del panel —y el titulo de la pestaña—
// enseñan la marca nueva sin recargar.
import { expect, test } from '@playwright/test'
import {
  LICENSE_WITHOUT_WHITE_LABEL,
  logIn,
  logInAsAdmin,
  stubManagementApi,
} from './support/admin'

test(
  'quien no es admin no ve «Marca» en la navegacion',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)

    await expect(page.getByRole('link', { name: 'Marca' })).not.toBeVisible()
  },
)

test(
  'un color sin contraste avisa en vivo y no se guarda sin confirmar (MB2)',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    const patches: string[] = []
    page.on('request', (request) => {
      if (request.method() === 'PATCH' && request.url().includes('/api/v1/settings')) {
        patches.push(request.postData() ?? '')
      }
    })

    await page.getByRole('link', { name: 'Marca' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Marca' })).toBeVisible()

    // El color de serie cumple: lo dice y no pide nada.
    await expect(page.getByTestId('accent-contrast')).toContainText('Cumple el mínimo')
    await expect(page.getByTestId('accent-confirm')).not.toBeVisible()

    await page.getByLabel('Color de acento', { exact: true }).fill('#f5e663')

    await expect(page.getByTestId('accent-contrast')).toBeVisible()
    await expect(page.getByTestId('accent-contrast')).toContainText('WCAG 2.2 AA')
    await expect(page.getByTestId('accent-contrast')).toContainText('No llega al mínimo')
    await expect(page.getByTestId('accent-contrast')).toContainText('versión más oscura')

    // Sin confirmar no se puede guardar: ni con el boton ni con Intro.
    await expect(page.getByTestId('save')).toBeDisabled()
    await page.getByLabel('Color de acento', { exact: true }).press('Enter')
    expect(patches).toEqual([])

    await page.getByTestId('accent-confirm').check()
    await expect(page.getByTestId('save')).toBeEnabled()
    await page.getByTestId('save').click()

    await expect(page.getByTestId('saved')).toBeVisible()
    expect(patches).toHaveLength(1)
    expect(patches[0]).toContain('#f5e663')
  },
)

test(
  'cambiar el nombre y guardar actualiza la cabecera y el titulo de la pestaña en el acto',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    // Antes de guardar, la cabecera y la pestaña siguen con el producto.
    await expect(page.getByRole('banner')).toContainText('KronoQR')
    await expect(page).toHaveTitle('KronoQR')

    await page.getByRole('link', { name: 'Marca' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Marca' })).toBeVisible()

    await page.getByLabel('Nombre de la aplicación').fill('Hotel Marina')
    await page.getByTestId('save').click()

    await expect(page.getByTestId('saved')).toBeVisible()

    // El doble de `GET /api/v1/settings` refleja el `PATCH`, y
    // `branding.store.load()` vuelve a pedir `GET /api/v1/branding` tras
    // guardar: la cabecera y el titulo cambian sin recargar la pagina.
    await expect(page.getByRole('banner')).toContainText('Hotel Marina')
    await expect(page).toHaveTitle('Hotel Marina')
  },
)

test(
  '«Volver al color del producto» escribe el hexadecimal de serie',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)

    await page.getByRole('link', { name: 'Marca' }).click()
    await page.getByLabel('Color de acento', { exact: true }).fill('#0f5c8c')

    await page.getByTestId('reset-accent').click()

    await expect(page.getByLabel('Color de acento', { exact: true })).toHaveValue('#b8542a')
  },
)

test(
  'con la marca propia fuera del plan contratado, avisa sin bloquear el guardado (ADR-023)',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin', license: LICENSE_WITHOUT_WHITE_LABEL })
    await logInAsAdmin(page)

    await page.getByRole('link', { name: 'Marca' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Marca' })).toBeVisible()

    const notice = page.getByTestId('license-restriction')
    await expect(notice).toBeVisible()
    await expect(notice).toContainText('no está incluida en el plan contratado')
    await expect(notice).toContainText('se conserva y se aplicará')

    // El enlace lleva a la pantalla de licencia, no a un boton muerto.
    await notice.getByRole('link', { name: 'Ver la licencia' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Licencia' })).toBeVisible()

    // Volviendo a «Marca»: sigue permitiendo guardar, con normalidad.
    await page.getByRole('link', { name: 'Marca' }).click()
    await page.getByLabel('Nombre de la aplicación').fill('Hotel Marina')
    await page.getByTestId('save').click()
    await expect(page.getByTestId('saved')).toBeVisible()
  },
)
