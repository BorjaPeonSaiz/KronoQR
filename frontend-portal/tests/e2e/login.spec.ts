// Acceso al portal personal (RF-ID-06, RF-ID-07, RL-05, ADR-015).
//
// Lo que se comprueba es lo que le pasa a una persona empleada: entra con su
// codigo y su PIN -nunca correo ni contrasena-, ve su propio nombre, y si se
// equivoca o si el portal esta protegiendo la cuenta de un ataque por fuerza
// bruta, recibe un aviso que no distingue una cosa de la otra (RS-03). El
// bloqueo por intentos en si -el contador, sus umbrales crecientes- es del
// backend y alli se prueba (RS-12, §7.5); lo que se prueba aqui es que el
// portal, ante el `429` que el servidor manda cuando el bloqueo esta activo,
// muestra un aviso generico y no confirma nada sobre el codigo de empleado.
import { expect, test } from '@playwright/test'
import {
  PORTAL_EMPLOYEE_CODE,
  PORTAL_PIN,
  PORTAL_SESSION_TOKEN,
  SESSION_STORAGE_KEY,
  logInToPortal,
  stubPortalApi,
  submitLoginForm,
} from './support/portal'

test(
  'sin sesion, una pantalla protegida manda al acceso y recuerda a donde iba',
  { tag: ['@RL-05', '@RF-ID-06'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    await page.goto('/export')

    await expect(page).toHaveURL(/\/login\?redirect=(?:%2F|\/)export$/)
    await expect(page.getByRole('heading', { name: 'Acceso al portal' })).toBeVisible()
  },
)

test(
  'entra con su codigo y su PIN y llega a su registro con su nombre',
  { tag: ['@RL-05', '@RF-ID-06', '@RF-ID-07'] },
  async ({ page }) => {
    const api = await stubPortalApi(page, { locale: 'es' })

    await logInToPortal(page)

    await expect(page.getByRole('heading', { level: 1, name: 'Mi registro horario' })).toBeVisible()
    // Nombre exacto: la cabecera del panel tambien dice «Hola, Youssef Amrani».
    await expect(page.getByText('Youssef Amrani', { exact: true })).toBeVisible()
    await expect(page.getByText(PORTAL_EMPLOYEE_CODE)).toBeVisible()

    // El token vive en `sessionStorage`, no en `localStorage`: el portal se
    // abre a menudo desde el ordenador compartido del centro, y una sesion
    // que sobreviviera a cerrar la pestaña seria el turno de otra persona
    // leyendo estas horas.
    const stored = await page.evaluate(
      (key) => ({
        session: globalThis.sessionStorage.getItem(key),
        local: globalThis.localStorage.getItem(key),
      }),
      SESSION_STORAGE_KEY,
    )
    expect(stored.local).toBeNull()
    expect(stored.session).toContain(PORTAL_SESSION_TOKEN)

    // Cada peticion posterior al acceso lleva el token, salvo la unica ruta
    // publica del portal (`GET /branding`, pedida sin esperar sesion).
    const PUBLIC_PATHS = ['/api/v1/me/login', '/api/v1/branding', '/api/v1/branding/logo']
    const afterLogin = api.requests.filter((request) => !PUBLIC_PATHS.includes(request.path))
    expect(afterLogin.length).toBeGreaterThan(0)
    for (const request of afterLogin) {
      expect(request.authorization, `${request.method} ${request.path}`).toBe(
        `Bearer ${PORTAL_SESSION_TOKEN}`,
      )
    }
  },
)

test(
  'un PIN incorrecto deja en el acceso con un aviso generico, sin decir que ha fallado exactamente',
  { tag: ['@RL-05', '@RF-ID-06'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es', loginOutcome: 'invalid' })

    await submitLoginForm(page, { employeeCode: PORTAL_EMPLOYEE_CODE, pin: '000000' })

    await expect(page.getByRole('alert')).toBeVisible()
    await expect(page.getByRole('alert')).toContainText(
      'El código de empleado o el PIN no son correctos',
    )
    await expect(page).toHaveURL(/\/login$/)

    // El PIN nunca se deja escrito, acierte o falle; el codigo si, para no
    // volver a teclearlo.
    await expect(page.locator('input[name="pin"]')).toHaveValue('')
    await expect(page.locator('input[name="employee_code"]')).toHaveValue(PORTAL_EMPLOYEE_CODE)

    const stored = await page.evaluate(
      (key) => globalThis.sessionStorage.getItem(key),
      SESSION_STORAGE_KEY,
    )
    expect(stored).toBeNull()
  },
)

test(
  'el bloqueo por intentos fallidos avisa de "demasiados intentos", nunca confirma que la cuenta existe',
  { tag: ['@RL-05', '@RF-ID-06'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es', loginOutcome: 'rateLimited' })

    await submitLoginForm(page)

    const alert = page.getByRole('alert')
    await expect(alert).toBeVisible()
    await expect(alert).toContainText('Demasiados intentos seguidos')
    // Ni una palabra sobre bloqueo, PIN o codigo: es un limite de trafico
    // generico, exactamente como el de un PIN incorrecto (RS-03).
    await expect(alert).not.toContainText('bloque')
    await expect(page).toHaveURL(/\/login$/)

    const stored = await page.evaluate(
      (key) => globalThis.sessionStorage.getItem(key),
      SESSION_STORAGE_KEY,
    )
    expect(stored).toBeNull()
  },
)

test(
  'salir borra la sesion local y una pantalla protegida vuelve a exigir el acceso',
  { tag: ['@RL-05', '@RF-ID-06'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    await logInToPortal(page)

    await page.getByRole('button', { name: 'Salir' }).click()

    await expect(page).toHaveURL(/\/login$/)

    const stored = await page.evaluate(
      (key) => globalThis.sessionStorage.getItem(key),
      SESSION_STORAGE_KEY,
    )
    expect(stored).toBeNull()

    await page.goto('/records')
    await expect(page).toHaveURL(/\/login\?redirect=(?:%2F|\/)records$/)
  },
)

test(
  'el PIN nunca viaja en la URL ni queda como valor de un campo tipo texto',
  { tag: ['@RL-05', '@RF-ID-06'] },
  async ({ page }) => {
    await stubPortalApi(page, { locale: 'es' })
    await page.goto('/login')

    const pinField = page.locator('input[name="pin"]')
    await expect(pinField).toHaveAttribute('type', 'password')
    await pinField.fill(PORTAL_PIN)

    await expect(page).not.toHaveURL(new RegExp(PORTAL_PIN))
  },
)
