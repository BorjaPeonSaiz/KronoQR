// Avance de la reimpresion durante una rotacion de la clave de firma
// (RF-QR-07, doc 02 §5.3, tarea 2.12).
//
// Lo que el panel hace en una rotacion es **mirar**: la rotacion se ejecuta en
// el servidor con `php artisan credentials:rotate-key` y no tiene endpoint a
// proposito, porque es un acto operativo con semanas de logistica de
// reimpresion detras. Esta prueba recorre exactamente eso: ver cuanto falta y
// pedir la lista de quien todavia ficha con la clave vieja.
//
// La prueba de campo —escanear en el quiosco una tarjeta reimpresa con la clave
// nueva— es de hardware y no cabe aqui: queda como puerta manual del criterio
// de terminado de credenciales (doc 03 §6.3).
import { expect, test } from '@playwright/test'
import {
  CREDENTIAL_BOARD_IN_ROTATION,
  CREDENTIAL_BOARD_PENDING_REPRINT,
  RETIRING_KEY_ID,
  logIn,
  stubManagementApi,
} from './support/admin'

test(
  'el panel enseña el avance de la reimpresion y pide al servidor quien falta',
  { tag: ['@RF-QR-07'] },
  async ({ page }) => {
    const api = await stubManagementApi(page, {
      credentialBoard: CREDENTIAL_BOARD_IN_ROTATION,
      credentialBoardByKey: CREDENTIAL_BOARD_PENDING_REPRINT,
    })

    await logIn(page)
    await page.goto('/credentials')

    const rotation = page.getByRole('region', { name: 'Rotación de la clave de firma en curso' })

    await expect(rotation).toBeVisible()
    // 48 de 60 reimpresas: el avance se mide contra la plantilla entera, no
    // contra las filas que se estén viendo.
    await expect(rotation).toContainText('48 de 60')
    await expect(rotation).toContainText(RETIRING_KEY_ID)
    await expect(rotation.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '80')

    // Y el mensaje que importa: durante el solape nadie se queda sin fichar.
    await expect(rotation).toContainText('nadie se queda fuera')

    await rotation.getByRole('button', { name: 'Ver a quién le falta (12)' }).click()

    // El filtro va al SERVIDOR: es lo que permite confirmar que no queda nadie
    // antes de retirar la clave anterior.
    await expect
      .poll(() =>
        api.requests.some(
          (request) =>
            request.path === '/api/v1/credentials/status' &&
            request.query.includes(`key_id=${RETIRING_KEY_ID}`),
        ),
      )
      .toBe(true)

    await expect(page.getByRole('table')).toContainText('Lucia Martinez Prieto')
    await expect(rotation.getByRole('button', { name: 'Ver toda la plantilla' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  },
)

test(
  'sin rotacion abierta el panel no habla de claves',
  { tag: ['@RF-QR-07', '@RF-QR-08'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)
    await page.goto('/credentials')

    await expect(page.getByRole('heading', { level: 1, name: 'Credenciales' })).toBeVisible()
    await expect(
      page.getByRole('region', { name: 'Rotación de la clave de firma en curso' }),
    ).toHaveCount(0)
  },
)
