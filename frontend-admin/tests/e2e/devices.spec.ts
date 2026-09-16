// Vinculacion de quiosco por codigo de emparejamiento (RF-PD-06, tarea 5.6) y
// panel de SALUD de la flota (RF-PA-07, tarea 3.3).
//
// Dos recorridos, como pide la ficha: desde la pantalla «Quioscos» del panel
// —que solo ve `admin`, ambito `settings:*`— y desde el paso del asistente,
// que ya cubre `setup-wizard.spec.ts`. Aqui va el primero, mas la
// desvinculacion, el rechazo generico del codigo y la salud de la flota.
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

// --- Salud de la flota (RF-PA-07, tarea 3.3) --------------------------------
//
// El veredicto lo calcula el SERVIDOR (`health.verdict`/`health.reason`): el
// doble solo declara los datos, igual que hace el contrato.

test(
  'un quiosco sin latido aparece marcado, con lo que hay que hacer y el runbook',
  { tag: ['@RF-PA-07'] },
  async ({ page }) => {
    await stubManagementApi(page, {
      role: 'admin',
      devices: {
        devices: [
          { ...DEVICE, name: 'Recepción' },
          {
            ...DEVICE,
            uuid: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a82',
            name: 'Cocina',
            last_seen_at: '2026-09-07T09:45:00.000000Z',
            health: { verdict: 'failure', reason: 'silent', seconds_since_last_seen: 900 },
          },
          {
            ...DEVICE,
            uuid: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a83',
            name: 'Almacén',
            battery_level: 8,
            battery_charging: false,
            health: { verdict: 'warning', reason: 'battery_low', seconds_since_last_seen: 30 },
          },
        ],
        meta: {
          thresholds: {
            fresh_within_seconds: 120,
            silent_after_seconds: 600,
            battery_low_percent: 15,
          },
        },
      },
    })

    await logInAsAdmin(page)
    await page.goto('/devices')

    const kitchenRow = page.getByRole('row', { name: /Cocina/ })

    await expect(kitchenRow).toContainText('Fallo')
    await expect(kitchenRow).toHaveAttribute('data-verdict', 'failure')

    const warehouseRow = page.getByRole('row', { name: /Almacén/ })

    await expect(warehouseRow).toContainText('Aviso')
    await expect(warehouseRow).toContainText('Batería baja')

    const receptionRow = page.getByRole('row', { name: /Recepción/ })

    await expect(receptionRow).toContainText('Al día')

    // La leyenda enseña los umbrales REALES de la instalacion, no unos supuestos.
    await expect(page.getByTestId('thresholds-legend')).toContainText('2 min')
    await expect(page.getByTestId('thresholds-legend')).toContainText('10 min')
    await expect(page.getByTestId('thresholds-legend')).toContainText('15 %')

    // El bloque «que hacer» del quiosco en fallo nombra el runbook y el
    // comando de consola (regla dura 16: el runbook no viaja al navegador).
    await expect(page.getByText(/quiosco-no-responde\.md/).first()).toBeVisible()
    await expect(page.getByText(/kiosk:health/).first()).toBeVisible()

    // Recepción (`ok`) no tiene ninguna accion que ofrecer: solo Cocina
    // (fallo) y Almacén (aviso) llevan el bloque «Que hacer» (correccion de
    // `ui-ux`, segunda vuelta de la tarea 3.3).
    await expect(page.getByText('Qué hacer')).toHaveCount(2)
  },
)
