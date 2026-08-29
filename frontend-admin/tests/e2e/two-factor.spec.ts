// Segundo factor obligatorio del acceso de gestion (RS-06, RF-ID-01, tarea 2.1).
//
// El backend decide QUIEN necesita 2FA (`admin`, `rrhh`, `auditor`, y cualquier
// cuenta que ya lo tenga activo) y prueba esa regla y sus rechazos alli
// (`tests/Feature/Identity/TwoFactorAuthenticationTest.php`). Aqui se prueba el
// recorrido de la persona por el panel una vez que el servidor ha respondido
// `202`: la pantalla del codigo, la del alta con QR, y que un codigo
// equivocado no tira a nadie de la pantalla.

import { expect, test } from '@playwright/test'
import { logInWithTwoFactorEnrolment, stubManagementApi, TOTP_CODE, USER } from './support/admin'

test(
  'con segundo factor ya activo, pide el codigo y entra a la plantilla',
  { tag: ['@RF-ID-01', '@RS-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { twoFactor: 'verify' })

    await page.goto('/login')
    await page.getByLabel(/Correo electrónico/).fill(USER.email)
    await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
    await page.getByRole('button', { name: 'Entrar' }).click()

    // La contrasena sola no basta: sigue en el acceso, ahora pidiendo el
    // codigo, y nada de lo escrito hasta aqui ha abierto sesion todavia.
    await expect(
      page.getByRole('heading', { name: 'Introduce el código de tu aplicación de autenticación' }),
    ).toBeVisible()
    await expect(page).toHaveURL(/\/login$/)

    await page.getByLabel(/Código de verificación/).fill(TOTP_CODE)
    await page.getByRole('button', { name: 'Verificar' }).click()

    await expect(page.getByRole('heading', { level: 1, name: 'Plantilla' })).toBeVisible()
    await expect(page.getByText(`${USER.name} (RRHH)`)).toBeVisible()
  },
)

test(
  'la primera vez, da de alta el segundo factor con QR y secreto antes de entrar',
  { tag: ['@RF-ID-01', '@RS-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { twoFactor: 'enrol' })

    await logInWithTwoFactorEnrolment(page)

    await expect(page.getByRole('heading', { level: 1, name: 'Plantilla' })).toBeVisible()
  },
)

test(
  'el alta del segundo factor enseña el QR y el secreto en texto para teclearlo a mano',
  { tag: ['@RF-ID-01', '@RS-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { twoFactor: 'enrol' })

    await page.goto('/login')
    await page.getByLabel(/Correo electrónico/).fill(USER.email)
    await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
    await page.getByRole('button', { name: 'Entrar' }).click()

    await expect(page.getByRole('heading', { name: 'Configura tu segundo factor' })).toBeVisible()
    // El QR es un atajo, nunca la unica via: quien no puede escanearlo teclea
    // el secreto que sigue debajo en texto.
    await expect(page.getByRole('img', { name: /Código QR/ })).toBeVisible()
    await expect(page.getByTestId('two-factor-secret')).toHaveText('JBSWY3DPEHPK3PXP')
  },
)

test(
  'un codigo equivocado deja un aviso generico y sigue pidiendo el codigo',
  { tag: ['@RF-ID-01', '@RS-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { twoFactor: 'verify' })

    await page.goto('/login')
    await page.getByLabel(/Correo electrónico/).fill(USER.email)
    await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
    await page.getByRole('button', { name: 'Entrar' }).click()

    await page.getByLabel(/Código de verificación/).fill('000000')
    await page.getByRole('button', { name: 'Verificar' }).click()

    await expect(page.getByRole('alert')).toBeVisible()
    // Ni «caducado» ni «reto invalido»: el mismo aviso que unas credenciales
    // incorrectas, y sigue en el mismo paso, no de vuelta al primero.
    await expect(
      page.getByRole('heading', { name: 'Introduce el código de tu aplicación de autenticación' }),
    ).toBeVisible()
    await expect(page.getByLabel(/Código de verificación/)).toHaveValue('')
  },
)

test(
  '«Volver al acceso» abandona el reto sin necesidad de otra contraseña',
  { tag: ['@RF-ID-01', '@RS-06'] },
  async ({ page }) => {
    const api = await stubManagementApi(page, { twoFactor: 'verify' })

    await page.goto('/login')
    await page.getByLabel(/Correo electrónico/).fill(USER.email)
    await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
    await page.getByRole('button', { name: 'Entrar' }).click()

    await expect(page.getByLabel(/Código de verificación/)).toBeVisible()

    await page.getByRole('button', { name: 'Volver al acceso' }).click()

    await expect(page.getByRole('heading', { name: 'Acceso al panel de gestión' })).toBeVisible()
    await expect(page.getByLabel(/Correo electrónico/)).toBeVisible()
    expect(api.requests.some((request) => request.path.includes('2fa'))).toBe(false)
  },
)
