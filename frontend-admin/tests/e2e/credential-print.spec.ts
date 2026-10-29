// Imprimir tarjetas desde el panel: una persona y el lote de pendientes (RF-QR-04;
// V1-QA-3 de la verificacion de la 2.2.0, tarea 22.2 de la 2.3.0).
//
// El PDF de verdad —formato tarjeta de credito, hoja A4, el QR que se acuña al
// imprimir— lo prueban el backend (`CredentialPrintTest`) y el contrato
// (`CredentialPrintContractTest`). Lo que faltaba es el RECORRIDO: que el boton
// de la fila y el del lote llaman a su endpoint, que el panel entrega el PDF como
// descarga con el nombre que da el servidor y que dice lo que ha pasado. Sin
// esto, un boton que dejara de llamar o una descarga que no saliera pasarian la
// CI en verde.
//
// El backend no participa: dobles de `support/admin.ts`, y las dos rutas de
// impresion, que solo existen en esta prueba.
import { expect, test } from '@playwright/test'
import type { Page, Request } from '@playwright/test'
import type { CredentialStatusBoard } from '@/shared/api/types'
import { EMPLOYEE_UUID, logIn, stubManagementApi } from './support/admin'

const CREDENTIAL_UUID = '0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c11'

/** Una persona con su credencial emitida y sin imprimir: la fila del boton «Imprimir». */
const BOARD_PENDING_PRINT: CredentialStatusBoard = {
  data: [
    {
      employee_uuid: EMPLOYEE_UUID,
      employee_code: 'E7QK2MXPR',
      full_name: 'Youssef Amrani',
      department_name: 'Recepción',
      status: 'pending_print',
      credential: {
        uuid: CREDENTIAL_UUID,
        employee_uuid: EMPLOYEE_UUID,
        key_id: 'a1',
        issued_at: '2026-09-01T06:00:00.000000Z',
        printed_at: null,
        delivered_at: null,
        revoked_at: null,
        revoked_reason: null,
        status: 'active',
      },
    },
  ],
  meta: { page: 1, per_page: 50, total: 1, total_pages: 1 },
  summary: {
    employees: 3,
    pending_print: 3,
    without_delivered_credential: 3,
    retiring_key_id: null,
    pending_reprint: 0,
    active_unknown_key: 0,
  },
}

/**
 * Responde a `POST {path}` con un PDF y apunta cada peticion. Se registra
 * despues de `stubManagementApi`, asi que tiene preferencia sobre su 404 de
 * ruta sin doble.
 */
async function stubPrint(
  page: Page,
  path: string,
  headers: Record<string, string>,
): Promise<Request[]> {
  const seen: Request[] = []

  await page.route(`**${path}`, async (route) => {
    seen.push(route.request())
    await route.fulfill({
      status: 200,
      contentType: 'application/pdf',
      headers: { 'Cache-Control': 'no-store, private', ...headers },
      body: Buffer.from('%PDF-1.7 tarjeta de prueba'),
    })
  })

  return seen
}

test(
  'imprime la tarjeta de una persona desde su fila y la descarga',
  { tag: ['@RF-QR-04'] },
  async ({ page }) => {
    await stubManagementApi(page, { credentialBoard: BOARD_PENDING_PRINT })
    const printed = await stubPrint(page, `/api/v1/credentials/${CREDENTIAL_UUID}/print`, {
      'Content-Disposition': 'attachment; filename="credencial.pdf"',
    })
    await logIn(page)
    await page.goto('/credentials')

    await page
      .getByRole('row', { name: /Youssef Amrani/ })
      .getByRole('button', { name: 'Imprimir la tarjeta' })
      .click()
    const download = page.waitForEvent('download')
    await page.getByRole('button', { name: 'Generar el PDF' }).click()

    expect((await download).suggestedFilename()).toBe('credencial.pdf')
    expect(printed).toHaveLength(1)
    expect(printed[0]?.method()).toBe('POST')
    expect(printed[0]?.headers()['accept']).toContain('application/pdf')
    await expect(
      page.getByText('Tarjeta de Youssef Amrani generada. Ya puede fichar cuando la reciba.'),
    ).toBeAttached()
  },
)

test(
  'imprime en una hoja A4 todas las pendientes y dice cuantas lleva',
  { tag: ['@RF-QR-04'] },
  async ({ page }) => {
    await stubManagementApi(page, { credentialBoard: BOARD_PENDING_PRINT })
    const printed = await stubPrint(page, '/api/v1/credentials/print-batch', {
      'Content-Disposition': 'attachment; filename="credenciales.pdf"',
      'X-Kronoqr-Printed-Count': '3',
    })
    await logIn(page)
    await page.goto('/credentials')

    await page.getByRole('button', { name: 'Imprimir las pendientes' }).click()
    const download = page.waitForEvent('download')
    await page.getByRole('button', { name: 'Generar la hoja A4' }).click()

    expect((await download).suggestedFilename()).toBe('credenciales.pdf')
    expect(printed).toHaveLength(1)
    expect(printed[0]?.method()).toBe('POST')
    await expect(page.getByText('Hoja generada. Tarjetas incluidas: 3.')).toBeAttached()
  },
)
