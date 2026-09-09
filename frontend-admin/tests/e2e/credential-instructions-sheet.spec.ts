// Hoja de instrucciones que se entrega con la tarjeta (tarea 5.11b, RL-05).
//
// Dos cosas se prueban aqui, no el contenido del PDF (eso es del backend):
//  1. El tablero de credenciales ofrece un boton por cada idioma ACTIVO de la
//     instalacion (`LOCALE_AVAILABLE`, via `GET /branding`), y pulsarlo pide
//     el `locale` correcto a `GET /credentials/instructions-sheet`.
//  2. El dialogo de «Registrar entrega» recuerda que en ese mismo acto se
//     entregan la tarjeta, el PIN y la hoja (decision 10 de la ficha).
import { expect, test } from '@playwright/test'
import type { CredentialStatusBoard } from '@/shared/api/types'
import { EMPLOYEE_UUID, logIn, stubManagementApi } from './support/admin'

/** Una persona con tarjeta impresa, esperando a que se le entregue: la unica
 *  fila donde aparece el boton «Registrar la entrega». */
const BOARD_PENDING_DELIVERY: CredentialStatusBoard = {
  data: [
    {
      employee_uuid: EMPLOYEE_UUID,
      employee_code: 'E7QK2MXPR',
      full_name: 'Youssef Amrani',
      department_name: 'Recepción',
      status: 'pending_delivery',
      credential: {
        uuid: '0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c02',
        employee_uuid: EMPLOYEE_UUID,
        key_id: 'a1',
        issued_at: '2026-09-01T06:00:00.000000Z',
        printed_at: '2026-09-01T09:00:00.000000Z',
        delivered_at: null,
        revoked_at: null,
        revoked_reason: null,
        status: 'active',
      },
    },
  ],
  summary: {
    employees: 1,
    pending_print: 0,
    without_delivered_credential: 1,
    retiring_key_id: null,
    pending_reprint: 0,
    active_unknown_key: 0,
  },
}

test(
  'hay un boton de la hoja de instrucciones por cada idioma activo de la instalacion',
  { tag: ['@RL-05'] },
  async ({ page }) => {
    // `PRODUCT_BRANDING` (por omision del doble) trae `locales.available: ['es', 'en']`.
    await stubManagementApi(page)
    await logIn(page)
    await page.goto('/credentials')

    const section = page.getByRole('region', { name: 'Hoja de instrucciones' })

    await expect(section).toBeVisible()
    await expect(section.getByTestId('instructions-sheet-button-es')).toBeVisible()
    await expect(section.getByTestId('instructions-sheet-button-en')).toBeVisible()
    await expect(section.getByTestId('instructions-sheet-help')).toContainText(
      'Se entrega con la tarjeta y el PIN, en el mismo acto.',
    )
  },
)

test(
  'descargar la hoja en un idioma pide ese locale al servidor',
  { tag: ['@RL-05'] },
  async ({ page }) => {
    const api = await stubManagementApi(page)
    await logIn(page)
    await page.goto('/credentials')

    const download = page.waitForEvent('download')

    await page.getByTestId('instructions-sheet-button-en').click()

    const file = await download

    // El nombre lo fija el contrato (`docs/api/openapi.yaml`): `hoja-empleado-<locale>.pdf`.
    expect(file.suggestedFilename()).toBe('hoja-empleado-en.pdf')

    const request = api.requests.find((it) => it.path === '/api/v1/credentials/instructions-sheet')

    expect(request).toBeDefined()
    expect(request?.query).toBe('locale=en')

    // No queda deshabilitado el boton del otro idioma para siempre: solo
    // mientras la descarga en curso lo estuviera (RN triv.: no hay dos
    // descargas simultaneas del mismo lote).
    await expect(page.getByTestId('instructions-sheet-button-es')).toBeEnabled()
  },
)

test(
  'el dialogo de registrar entrega recuerda la tarjeta, el PIN y la hoja',
  { tag: ['@RL-05'] },
  async ({ page }) => {
    await stubManagementApi(page, { credentialBoard: BOARD_PENDING_DELIVERY })
    await logIn(page)
    await page.goto('/credentials')

    await page.getByRole('button', { name: 'Registrar la entrega' }).click()

    const dialog = page.getByRole('dialog', { name: 'Registrar la entrega de la tarjeta' })

    await expect(dialog).toBeVisible()
    await expect(dialog.getByTestId('deliver-sheet-reminder')).toContainText(
      'En este mismo acto se entregan la tarjeta, el PIN y la hoja de instrucciones.',
    )
  },
)
