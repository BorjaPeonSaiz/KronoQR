// Vinculacion de quiosco por codigo de emparejamiento (RF-PD-06, tarea 5.6).
//
// Dos recorridos, como pide la ficha: desde la pantalla «Quioscos» del panel
// —que solo ve `admin`, ambito `settings:*`— y desde el paso del asistente,
// que ya cubre `setup-wizard.spec.ts`. Aqui va el primero, mas la
// desvinculacion y el rechazo generico del codigo.
import { expect, test } from '@playwright/test'
import {
  DEVICE,
  PAIRING_CODE,
  logInAsAdmin,
  logInAsManager,
  stubManagementApi,
} from './support/admin'

test(
  'vincula un quiosco desde «Quioscos» y lo enseña en la lista',
  { tag: ['@RF-PD-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin', devices: { devices: [] } })

    await logInAsAdmin(page)
    await page.goto('/devices')

    await expect(page.getByRole('heading', { level: 1, name: 'Quioscos' })).toBeVisible()
    await expect(page.getByText('Todavía no hay ningún quiosco vinculado')).toBeVisible()

    await page.getByRole('button', { name: 'Vincular quiosco' }).click()
    await expect(page.getByRole('dialog', { name: 'Vincular quiosco' })).toBeVisible()

    await page.getByLabel('Código de emparejamiento').fill(PAIRING_CODE)
    await page.getByLabel('Nombre del quiosco').fill('Recepción')
    await page.getByRole('button', { name: 'Vincular', exact: true }).click()

    // El dialogo NO se cierra solo: enseña el resumen —version de la app,
    // hora en que la tablet pidio el codigo— para contrastarlo con la tablet
    // delante antes de darlo por bueno.
    const dialog = page.getByRole('dialog', { name: 'Vincular quiosco' })
    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText('Se ha vinculado el quiosco «Recepción».')
    await expect(dialog).toContainText('1.4.3')
    // La region viva del marco (`aria-live`) es distinta del propio resumen
    // visible del dialogo, que TAMBIEN lleva `role="status"`.
    await expect(page.locator('[aria-live="polite"]')).toContainText(
      'Se ha vinculado el quiosco Recepción.',
    )

    await page.getByRole('button', { name: 'Cerrar', exact: true }).click()

    await expect(page.getByRole('dialog')).not.toBeVisible()
    await expect(page.getByRole('table')).toContainText('Recepción')
  },
)

test(
  'un codigo caducado o ya usado se rechaza con el mismo aviso generico',
  { tag: ['@RF-PD-06', '@RS-03'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin', devices: { devices: [] } })

    await logInAsAdmin(page)
    await page.goto('/devices')

    await page.getByRole('button', { name: 'Vincular quiosco' }).click()
    await page.getByLabel('Código de emparejamiento').fill('000000')
    await page.getByLabel('Nombre del quiosco').fill('Recepción')
    await page.getByRole('button', { name: 'Vincular', exact: true }).click()

    await expect(
      page.getByText('El código no es válido o ha caducado: pide a la tablet que genere otro.'),
    ).toBeVisible()
    // El dialogo sigue abierto: un codigo mal tecleado no obliga a empezar de
    // cero (el `confirm` fallido no consume la solicitud).
    await expect(page.getByRole('dialog', { name: 'Vincular quiosco' })).toBeVisible()
  },
)

test(
  'desvincular pide confirmacion y revoca el quiosco',
  { tag: ['@RF-PD-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })

    await logInAsAdmin(page)
    await page.goto('/devices')

    await expect(page.getByRole('table')).toContainText(DEVICE.name)
    await page.getByRole('button', { name: 'Desvincular' }).click()

    const dialog = page.getByRole('dialog', { name: 'Desvincular quiosco' })
    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText(DEVICE.name)

    await dialog.getByRole('button', { name: 'Desvincular' }).click()

    await expect(page.getByRole('dialog')).not.toBeVisible()
    await expect(page.getByRole('status')).toContainText('Se ha desvinculado el quiosco Recepción.')
  },
)

test(
  'una cola pendiente destaca el aviso de que los fichajes solo llegan si se reempareja la misma tablet',
  { tag: ['@RF-PD-06', '@RL-04'] },
  async ({ page }) => {
    await stubManagementApi(page, {
      role: 'admin',
      devices: { devices: [{ ...DEVICE, pending_queue_size: 37 }] },
    })

    await logInAsAdmin(page)
    await page.goto('/devices')

    await page.getByRole('button', { name: 'Desvincular' }).click()

    const dialog = page.getByRole('dialog', { name: 'Desvincular quiosco' })
    const warning = dialog.getByRole('alert')

    await expect(warning).toBeVisible()
    await expect(warning).toContainText('37')
    await expect(warning).toContainText('art. 34.9 ET')
  },
)

test(
  'vincular con el nombre de un quiosco revocado avisa antes de enviar que lo reactivara',
  { tag: ['@RF-PD-06'] },
  async ({ page }) => {
    await stubManagementApi(page, {
      role: 'admin',
      devices: { devices: [{ ...DEVICE, name: 'Almacén', status: 'revoked' }] },
    })

    await logInAsAdmin(page)
    await page.goto('/devices')

    await page.getByRole('button', { name: 'Vincular quiosco' }).click()
    await page.getByLabel('Nombre del quiosco').fill('Almacén')

    await expect(
      page.getByText(
        'Este nombre corresponde a un quiosco desvinculado: se reactivará con el mismo identificador.',
      ),
    ).toBeVisible()
  },
)

test(
  'un responsable de departamento no ve «Quioscos» en la navegacion',
  { tag: ['@RF-PD-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'manager' })

    await logInAsManager(page)

    await expect(page.getByRole('link', { name: 'Quioscos' })).toHaveCount(0)
  },
)
