// Plantilla desde el panel: contratos de una persona (RF-GP-02) y marca
// informativa de teletrabajo (RF-GP-01).
//
// El backend no participa (regla dura 18): se prueba el recorrido por el panel
// con la API simulada con estado de `support/admin.ts`, que cierra el contrato
// vigente el dia anterior al inicio del nuevo, como el servidor. La restriccion
// de no solapar vigencias y la autorizacion real se prueban en el backend.
import type { Page } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import type { Employee, EmploymentContract } from '@/shared/api/types'
import { EMPLOYEE, EMPLOYEE_UUID, logIn, logInAsManager, stubManagementApi } from './support/admin'

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

const TELEWORKER: Employee = {
  ...EMPLOYEE,
  uuid: '0199f5b1-0002-7000-8000-0123456789cc',
  employee_code: 'T4NM8ZQRW',
  first_name: 'Lucia',
  last_name: 'Ferrer',
  teleworking: true,
}

const HINT = 'Solo informativo: no cambia cómo ficha ni cómo se calculan sus horas.'

test(
  'el listado enseña el teletrabajo con texto y el filtro oculta y muestra a quien corresponde',
  { tag: ['@RF-GP-01'] },
  async ({ page }) => {
    const stub = await stubManagementApi(page, { employees: [EMPLOYEE, TELEWORKER] })
    await logIn(page)
    await page.goto('/employees')

    const table = page.getByRole('table')
    const youssef = table.getByRole('row', { name: /Youssef Amrani/ })
    const lucia = table.getByRole('row', { name: /Lucia Ferrer/ })

    await expect(table.getByRole('columnheader', { name: 'Teletrabajo' })).toBeVisible()
    await expect(youssef).toContainText('No teletrabaja')
    await expect(lucia.getByTestId('teleworking-badge')).toHaveText('Teletrabaja')

    await page.getByLabel('Teletrabajo', { exact: true }).selectOption('true')
    await expect(youssef).toHaveCount(0)
    await expect(lucia).toBeVisible()
    await expect(page).toHaveURL(/teleworking=true/)

    await page.getByLabel('Teletrabajo', { exact: true }).selectOption('false')
    await expect(lucia).toHaveCount(0)
    await expect(youssef).toBeVisible()

    await page.getByLabel('Teletrabajo', { exact: true }).selectOption('')
    await expect(youssef).toBeVisible()
    await expect(lucia).toBeVisible()

    // El filtro viaja al servidor como literal, no se resuelve en el cliente.
    expect(
      stub.requests.some(
        (request) =>
          request.path === '/api/v1/employees' && request.query.includes('teleworking=true'),
      ),
    ).toBe(true)
  },
)

test(
  'el alta con teletrabajo envia el campo, explica que es informativo y la fila lo refleja',
  { tag: ['@RF-GP-01'] },
  async ({ page }) => {
    const stub = await stubManagementApi(page)
    await logIn(page)
    await page.goto('/employees')

    await page.getByRole('button', { name: 'Dar de alta' }).click()

    const dialog = page.getByRole('dialog', { name: 'Alta de empleado' })

    await dialog.getByLabel(/^Nombre/).fill('Marta')
    await dialog.getByLabel(/^Apellidos/).fill('Soler')

    const checkbox = dialog.getByLabel('Esta persona teletrabaja')

    await expect(checkbox).not.toBeChecked()
    await expect(checkbox).toHaveAccessibleDescription(HINT)
    await checkbox.check()
    await dialog.getByRole('button', { name: 'Dar de alta' }).click()

    await page.getByRole('button', { name: 'Ya lo he anotado, lo entregaré después' }).click()

    const created = stub.requests.find(
      (request) => request.method === 'POST' && request.path === '/api/v1/employees',
    )

    expect(created?.body).toMatchObject({ teleworking: true })
    await expect(page.getByRole('row', { name: /Marta Soler/ })).toContainText('Teletrabaja')
  },
)

test(
  'la edicion de la ficha enseña desde-hacia y envia solo el campo de teletrabajo cuando es lo unico que cambia',
  { tag: ['@RF-GP-01'] },
  async ({ page }) => {
    const stub = await stubManagementApi(page)
    await logIn(page)
    await page.goto(`/employees/${EMPLOYEE_UUID}`)

    await page.getByRole('button', { name: 'Editar' }).click()

    const review = page.getByRole('button', { name: 'Revisar los cambios' })

    await expect(review).toBeDisabled()
    await page.getByLabel('Esta persona teletrabaja').check()
    await review.click()

    const dialog = page.getByRole('dialog', { name: 'Confirmar los cambios de la ficha' })
    const row = dialog.getByRole('row', { name: /Teletrabajo/ })

    await expect(row).toContainText('No teletrabaja')
    await expect(row).toContainText('Teletrabaja')

    await dialog.getByTestId('confirm-dialog-confirm').click()
    await expect(dialog).toBeHidden()

    const patch = stub.requests.find(
      (request) =>
        request.method === 'PATCH' && request.path === `/api/v1/employees/${EMPLOYEE_UUID}`,
    )

    expect(patch?.body).toEqual({ teleworking: true })
    await expect(page.getByTestId('teleworking-badge')).toHaveText('Teletrabaja')
  },
)

test(
  'si el servidor deniega la modificacion el panel lo cuenta y no da el cambio por hecho',
  { tag: ['@RF-GP-01'] },
  async ({ page }) => {
    await stubManagementApi(page, { employeeUpdateOutcome: 'forbidden' })
    await logIn(page)
    await page.goto(`/employees/${EMPLOYEE_UUID}`)

    await page.getByRole('button', { name: 'Editar' }).click()
    await page.getByLabel('Esta persona teletrabaja').check()
    await page.getByRole('button', { name: 'Revisar los cambios' }).click()

    const dialog = page.getByRole('dialog', { name: 'Confirmar los cambios de la ficha' })

    await dialog.getByRole('button', { name: 'Confirmar y guardar' }).click()

    await expect(dialog.getByRole('alert')).toBeVisible()
    await expect(dialog).toBeVisible()
  },
)

test(
  'quien no gestiona la plantilla no llega a la ficha ni a la casilla de teletrabajo',
  { tag: ['@RF-GP-01', '@RF-ID-03'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'manager' })
    await logInAsManager(page)
    await page.goto(`/employees/${EMPLOYEE_UUID}`)

    await expect(page).not.toHaveURL(/employees/)
    await expect(page.getByTestId('teleworking-checkbox')).toHaveCount(0)
    await expect(page.getByLabel('Esta persona teletrabaja')).toHaveCount(0)
  },
)

test(
  'la baja con fecha de cese futura se rechaza en el campo y con la fecha de hoy se confirma',
  { tag: ['@RF-GP-03', '@RN-14'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)
    await page.goto(`/employees/${EMPLOYEE_UUID}`)

    await page.getByRole('button', { name: 'Dar de baja' }).click()

    const dialog = page.getByRole('dialog', { name: 'Baja' })
    const date = dialog.getByLabel(/Fecha de cese/)

    await expect(dialog.getByText(/efectiva al confirmarla/)).toBeVisible()
    await dialog.getByLabel(/Motivo del cese/).selectOption('endOfContract')

    // Una fecha futura (en el centro) no se admite: el campo lo dice y no se puede confirmar.
    await date.fill('2999-12-31')
    await expect(date).toHaveAttribute('aria-invalid', 'true')
    await expect(dialog.getByText(/no puede ser posterior a hoy/).first()).toBeVisible()
    await expect(dialog.getByRole('button', { name: 'Confirmar la baja' })).toBeDisabled()

    const results = await new AxeBuilder({ page })
      .include('[role="dialog"]')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze()
    const blocking = results.violations.filter(
      (violation) => violation.impact === 'critical' || violation.impact === 'serious',
    )

    expect(
      blocking,
      blocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
    ).toEqual([])

    // Con la fecha de hoy (la del centro, que es el `max` del campo) la baja se confirma.
    const today = await date.getAttribute('max')

    expect(today).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    await date.fill(today ?? '')
    await expect(date).not.toHaveAttribute('aria-invalid', 'true')
    await dialog.getByRole('button', { name: 'Confirmar la baja' }).click()

    await expect(dialog).toBeHidden()
    await expect(page.getByText(/Esta persona está de baja desde/)).toBeVisible()
  },
)
