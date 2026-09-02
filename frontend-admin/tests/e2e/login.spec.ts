// Acceso al panel de gestion (RF-ID-01) y cierre de sesion.
//
// Lo que se comprueba es lo que le pasa a una persona: entra, ve su nombre,
// sale y ya no puede volver sin entrar de nuevo. El bloqueo por intentos, la
// politica de contrasena y el rastro de auditoria son del backend y alli se
// prueban (RS-13).

import { expect, test } from '@playwright/test'
import { logIn, SESSION_STORAGE_KEY, SESSION_TOKEN, stubManagementApi, USER } from './support/admin'

test(
  'sin sesion, una pantalla protegida manda al acceso y recuerda a donde iba',
  { tag: ['@RF-ID-01'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await page.goto('/employees')

    // La guarda deja el `redirect` tal cual (`/employees`); el navegador puede
    // codificarlo o no segun como lo reciba.
    await expect(page).toHaveURL(/\/login\?redirect=(?:%2F|\/)employees$/)
    await expect(page.getByRole('heading', { name: 'Acceso al panel de gestión' })).toBeVisible()
  },
)

test(
  'entra con correo y contrasena y llega a la plantilla con su nombre en la cabecera',
  { tag: ['@RF-ID-01', '@RF-ID-02'] },
  async ({ page }) => {
    const api = await stubManagementApi(page)

    await logIn(page)

    await expect(page.getByRole('heading', { level: 1, name: 'Plantilla' })).toBeVisible()
    await expect(page.getByText(`${USER.name} (RRHH)`)).toBeVisible()

    // El token vive en `sessionStorage`, no en `localStorage`: la sesion de
    // gestion muere con la pestaña del ordenador compartido de recepcion.
    const stored = await page.evaluate(
      (key) => ({
        session: globalThis.sessionStorage.getItem(key),
        local: globalThis.localStorage.getItem(key),
      }),
      SESSION_STORAGE_KEY,
    )
    expect(stored.local).toBeNull()
    expect(stored.session).toContain(SESSION_TOKEN)

    // Y cada peticion posterior al acceso lleva la cabecera. Es la clase de
    // fallo que las pruebas de componente no ven: el cliente HTTP se crea con
    // el token y nadie lo comprueba en el recorrido completo.
    //
    // `GET /setup/status` queda fuera a proposito: es publica (RF-PD-03), la
    // guarda de rutas la consulta ANTES de saber si hay sesion, y por eso
    // nunca lleva `Authorization`.
    const afterLogin = api.requests.filter(
      (request) => request.path !== '/api/v1/auth/login' && request.path !== '/api/v1/setup/status',
    )
    expect(afterLogin.length).toBeGreaterThan(0)
    for (const request of afterLogin) {
      expect(request.authorization, `${request.method} ${request.path}`).toBe(
        `Bearer ${SESSION_TOKEN}`,
      )
    }
  },
)

test(
  'unas credenciales rechazadas dejan a la persona en el acceso, con aviso y sin sesion',
  { tag: ['@RF-ID-01'] },
  async ({ page }) => {
    await stubManagementApi(page, { loginOutcome: 'invalid' })

    await page.goto('/login')
    await page.getByLabel(/Correo electrónico/).fill(USER.email)
    await page.getByLabel(/Contraseña/).fill('esta-no-es')
    await page.getByRole('button', { name: 'Entrar' }).click()

    await expect(page.getByRole('alert')).toBeVisible()
    await expect(page).toHaveURL(/\/login$/)
    // La contrasena se vacia; el correo se conserva para no teclearlo otra vez.
    await expect(page.getByLabel(/Contraseña/)).toHaveValue('')
    await expect(page.getByLabel(/Correo electrónico/)).toHaveValue(USER.email)

    const stored = await page.evaluate(
      (key) => globalThis.sessionStorage.getItem(key),
      SESSION_STORAGE_KEY,
    )
    expect(stored).toBeNull()
  },
)

test(
  'cerrar sesion avisa al servidor, borra la sesion local y vuelve al acceso',
  { tag: ['@RF-ID-01'] },
  async ({ page }) => {
    const api = await stubManagementApi(page)
    await logIn(page)

    await page.getByRole('button', { name: 'Cerrar sesión' }).click()

    await expect(page).toHaveURL(/\/login$/)
    expect(api.requests.some((request) => request.path === '/api/v1/auth/logout')).toBe(true)

    const stored = await page.evaluate(
      (key) => globalThis.sessionStorage.getItem(key),
      SESSION_STORAGE_KEY,
    )
    expect(stored).toBeNull()

    // Y la pantalla protegida vuelve a exigir el acceso.
    await page.goto('/employees')
    // La guarda deja el `redirect` tal cual (`/employees`); el navegador puede
    // codificarlo o no segun como lo reciba.
    await expect(page).toHaveURL(/\/login\?redirect=(?:%2F|\/)employees$/)
  },
)
