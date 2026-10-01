// Contratos de una persona desde su ficha (RF-GP-02).
//
// El backend no participa (regla dura 18): se prueba el recorrido por el panel
// con la API simulada con estado de `support/admin.ts`, que cierra el contrato
// vigente el dia anterior al inicio del nuevo, como el servidor. La restriccion
// de no solapar vigencias y la autorizacion real se prueban en el backend.
import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import type { EmploymentContract } from '@/shared/api/types'
import { EMPLOYEE_UUID, logIn, stubManagementApi } from './support/admin'

const CURRENT_CONTRACT: EmploymentContract = {
  id: 58,
  employee_uuid: EMPLOYEE_UUID,
  weekly_hours: 40,
  annual_hours: 1780,
  schedule_type: 'turnos',
  valid_from: '2026-03-16',
  valid_to: null,
  is_current: true,
}

async function openContractsSection(page: Page): Promise<void> {
  await page.goto(`/employees/${EMPLOYEE_UUID}`)
  await expect(page.getByRole('heading', { name: 'Contratos', level: 2 })).toBeVisible()
}

async function fillContract(
  page: Page,
  values: { weekly: string; schedule: string; from: string },
): Promise<void> {
  const dialog = page.getByRole('dialog', { name: 'Registrar contrato' })

  await dialog.getByLabel(/Horas por semana/).fill(values.weekly)
  await dialog.getByLabel(/Tipo de jornada/).selectOption(values.schedule)
  await dialog.getByLabel(/Vigente desde/).fill(values.from)
}

test(
  'una persona sin contratos muestra el estado vacio y RRHH registra el primero',
  { tag: ['@RF-GP-02'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)
    await openContractsSection(page)

    await expect(page.getByText('Sin contratos registrados')).toBeVisible()

    await page.getByTestId('contract-register-open').click()
    const dialog = page.getByRole('dialog', { name: 'Registrar contrato' })

    // Sin los datos obligatorios no se puede enviar.
    await expect(dialog.getByTestId('contract-submit')).toBeDisabled()

    await fillContract(page, { weekly: '37,5', schedule: 'continua', from: '2026-09-15' })
    await dialog.getByTestId('contract-submit').click()

    await expect(dialog).toBeHidden()

    const rows = page.getByTestId('contract-row')

    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('37,5 h')
    await expect(rows.first()).toContainText('Vigente')
    await expect(rows.first()).toContainText('Continua')
  },
)

test(
  'registrar un contrato nuevo enseña el valor vigente, lo cierra el dia antes y conserva la serie',
  { tag: ['@RF-GP-02'] },
  async ({ page }) => {
    await stubManagementApi(page, { contracts: [CURRENT_CONTRACT] })
    await logIn(page)
    await openContractsSection(page)

    await page.getByTestId('contract-register-open').click()
    const dialog = page.getByRole('dialog', { name: 'Registrar contrato' })

    await fillContract(page, { weekly: '30', schedule: 'partida', from: '2026-09-01' })

    // Antes de confirmar: DESDE 40 h HACIA 30 h, y el aviso del cierre del anterior.
    const preview = dialog.getByRole('table')

    await expect(preview.getByRole('row', { name: /Horas por semana/ })).toContainText('40 h')
    await expect(preview.getByRole('row', { name: /Horas por semana/ })).toContainText('30 h')
    await expect(dialog.getByTestId('closes-note')).toBeVisible()

    await dialog.getByTestId('contract-submit').click()
    await expect(dialog).toBeHidden()

    // La serie conserva el anterior, ya cerrado el 31 de agosto, y suma el nuevo.
    const rows = page.getByTestId('contract-row')

    await expect(rows).toHaveCount(2)
    await expect(rows.nth(0)).toContainText('40 h')
    await expect(rows.nth(0)).not.toContainText('Vigente')
    await expect(rows.nth(0)).toContainText('31 ago 2026')
    await expect(rows.nth(1)).toContainText('30 h')
    await expect(rows.nth(1)).toContainText('Vigente')
  },
)

test(
  'ante un 409 el panel relee los contratos, avisa y deja corregir la fecha sin reescribir el formulario',
  { tag: ['@RF-GP-02'] },
  async ({ page }) => {
    await stubManagementApi(page, { contracts: [CURRENT_CONTRACT], contractOutcome: 'conflict' })
    await logIn(page)
    await openContractsSection(page)

    await page.getByTestId('contract-register-open').click()
    const dialog = page.getByRole('dialog', { name: 'Registrar contrato' })

    await fillContract(page, { weekly: '35', schedule: 'continua', from: '2026-08-01' })
    await dialog.getByTestId('contract-submit').click()

    // El aviso es una alerta y el dialogo sigue abierto con lo que se habia escrito.
    await expect(dialog.getByTestId('contract-conflict')).toBeVisible()
    await expect(dialog.getByLabel(/Horas por semana/)).toHaveValue('35')

    // La serie de debajo se ha releido: ya trae el contrato que registro otra persona.
    await expect(page.getByTestId('contract-row')).toHaveCount(2)

    // Con la fecha corregida, el alta sale adelante.
    await dialog.getByLabel(/Vigente desde/).fill('2026-10-01')
    await dialog.getByTestId('contract-submit').click()

    await expect(dialog).toBeHidden()
    await expect(page.getByTestId('contract-row')).toHaveCount(3)
  },
)

test(
  'el dialogo de alta de contrato se abre y se cierra con el teclado y devuelve el foco al boton',
  { tag: ['@RF-GP-02'] },
  async ({ page }) => {
    await stubManagementApi(page, { contracts: [CURRENT_CONTRACT] })
    await logIn(page)
    await openContractsSection(page)

    const opener = page.getByTestId('contract-register-open')

    await opener.focus()
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('dialog', { name: 'Registrar contrato' })

    await expect(dialog).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(opener).toBeFocused()
  },
)
