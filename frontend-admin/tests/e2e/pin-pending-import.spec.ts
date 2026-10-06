// Las altas de una importacion masiva nacen sin PIN (RF-GP-05, RF-ID-09,
// bloque 12b de la 2.2.0).
//
// EL RECORRIDO QUE IMPORTA: RRHH importa la plantilla, el asistente le avisa de
// que esas personas no tienen PIN y, al terminar, le lleva al listado de
// pendientes; desde la ficha de cada una emite el PIN al entregarle la
// tarjeta, lo ve una sola vez y registra la entrega.
//
// El backend no participa: la API va simulada con las formas del contrato
// (`support/setupWizard.ts` y `support/admin.ts`). Que la importacion no emita
// PIN, que la primera emision deje `pin.issued` y no cuente como
// restablecimiento lo prueba el backend (`EmployeeImportTest`,
// `PinIssuanceEndpointsTest`).
import { expect, type Page, test } from '@playwright/test'
import { EMPLOYEE, EMPLOYEE_UUID, logIn, stubManagementApi } from './support/admin'
import { SITE, stubOnboardingApi, TOTP_CODE } from './support/setupWizard'

async function reachImportStep(page: Page): Promise<void> {
  await page.goto('/setup')

  await page.getByLabel('Nombre').fill('Dirección del hotel')
  await page.getByLabel('Correo electrónico').fill('direccion@hotel.example')
  await page.getByLabel('Contraseña').fill('una-contrasena-larga-y-propia-1!')
  await page.getByRole('button', { name: 'Crear la cuenta' }).click()
  await expect(page.getByTestId('two-factor-secret')).toBeVisible()
  await page.getByLabel(/Código del autenticador/).fill(TOTP_CODE)
  await page.getByRole('button', { name: 'Activar y entrar' }).click()

  await expect(page.getByRole('heading', { name: 'Organización' })).toBeVisible()
  await page.getByLabel('Nombre del establecimiento').fill('Hotel Marina')
  await page.getByRole('button', { name: 'Continuar' }).click()

  await expect(page.getByRole('heading', { name: 'Centro de trabajo' })).toBeVisible()
  await page.getByLabel('Nombre del centro').fill(SITE.name)
  await page.getByRole('button', { name: 'Continuar' }).click()

  await expect(page.getByRole('heading', { name: 'Departamentos' })).toBeVisible()
  await page.getByTestId('skip').click()

  await expect(page.getByRole('heading', { name: 'Perfil de convenio' })).toBeVisible()
  await page.getByTestId('confirm-compliance-profile').click()

  await expect(page.getByRole('heading', { name: 'Plantilla' })).toBeVisible()
}

async function importOnePerson(page: Page): Promise<void> {
  await page.getByTestId('import-file').setInputFiles({
    name: 'plantilla.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from('nombre,apellidos,dni,fecha_alta\nYoussef,Amrani,12345678Z,2026-01-15\n'),
  })
  await page.getByTestId('validate').click()
  await expect(page.getByTestId('import-row-2')).toContainText('Youssef Amrani')
  await page.getByTestId('apply').click()
  await expect(page.getByTestId('apply')).toHaveCount(0)
}

test(
  'tras aplicar la importacion avisa de las altas sin PIN y no antes',
  { tag: ['@RF-GP-05', '@RF-ID-09'] },
  async ({ page }) => {
    await stubOnboardingApi(page)
    await reachImportStep(page)

    await page.getByTestId('import-file').setInputFiles({
      name: 'plantilla.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from('nombre,apellidos,dni,fecha_alta\nYoussef,Amrani,12345678Z,2026-01-15\n'),
    })
    await page.getByTestId('validate').click()
    await expect(page.getByTestId('import-row-2')).toContainText('Youssef Amrani')

    // La simulacion no da de alta a nadie: todavia no hay nadie sin PIN.
    await expect(page.getByTestId('no-pin-notice')).toHaveCount(0)

    await page.getByTestId('apply').click()

    await expect(page.getByTestId('no-pin-notice')).toContainText(
      'Se ha dado de alta 1 persona sin PIN.',
    )
  },
)

test(
  'al terminar la puesta en marcha enlaza al listado de personas sin PIN',
  { tag: ['@RF-GP-05', '@RF-ID-09', '@RF-PD-03'] },
  async ({ page }) => {
    // Mientras el asistente esta abierto la guarda de rutas lleva todo a
    // `/setup`: el enlace solo puede funcionar en el resumen final.
    await stubOnboardingApi(page)
    await reachImportStep(page)
    await importOnePerson(page)
    await page.getByTestId('continue').click()

    await expect(page.getByRole('heading', { name: 'Licencia', level: 2 })).toBeVisible()
    await page.getByTestId('skip').click()
    await expect(page.getByRole('heading', { name: 'Primer quiosco' })).toBeVisible()
    await page.getByTestId('skip').click()
    await page.getByTestId('complete-setup').click()
    await expect(page.getByRole('heading', { name: 'Puesta en marcha completada' })).toBeVisible()

    await expect(page.getByTestId('no-pin-summary')).toContainText('1 persona sin PIN')
    await page.getByTestId('no-pin-link').click()

    await expect(page).toHaveURL(/\/employees\?pin_status=pending$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Plantilla' })).toBeVisible()
  },
)

test(
  'desde el listado de pendientes RRHH emite el PIN, lo ve una vez y registra la entrega',
  { tag: ['@RF-ID-09', '@RF-GP-05', '@RL-05'] },
  async ({ page }) => {
    const api = await stubManagementApi(page, {
      employees: [{ ...EMPLOYEE, pin_status: 'pending' }],
    })
    await logIn(page)

    await page.goto('/employees?pin_status=pending')

    await expect(page.getByLabel('Estado del PIN')).toHaveValue('pending')
    const row = page.getByRole('row', { name: /Youssef Amrani/ })
    await expect(row).toContainText('Sin emitir')
    expect(
      api.requests.some(
        (request) =>
          request.method === 'GET' &&
          request.path === '/api/v1/employees' &&
          request.query.includes('pin_status=pending'),
      ),
    ).toBe(true)

    await row.getByRole('link', { name: 'Youssef Amrani' }).click()
    await expect(page).toHaveURL(new RegExp(`/employees/${EMPLOYEE_UUID}$`))

    // Sin PIN no hay nada que restablecer: la accion se rotula como emision y
    // todavia no se puede registrar ninguna entrega.
    await expect(page.getByRole('button', { name: 'Restablecer el PIN' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Registrar la entrega del PIN' })).toHaveCount(0)
    await page.getByRole('button', { name: 'Emitir el PIN' }).click()
    await page.getByRole('button', { name: 'Emitir y mostrar el PIN' }).click()

    const reveal = page.getByRole('dialog', { name: 'PIN emitido' })
    await expect(reveal.getByTestId('pin-value')).toHaveText('582913')

    await reveal.getByLabel('Confirmo que he entregado este PIN en mano a Youssef Amrani.').check()
    await reveal.getByRole('button', { name: 'Registrar la entrega ahora' }).click()

    await expect(reveal).toBeHidden()
    await expect(page.getByText('Entregado', { exact: true })).toBeVisible()
    // Se muestra una sola vez: cerrado el dialogo, el PIN no esta en la pagina.
    await expect(page.getByText('582913')).toHaveCount(0)
    expect(
      api.requests.filter(
        (request) =>
          request.method === 'POST' &&
          request.path === `/api/v1/employees/${EMPLOYEE_UUID}/pin/deliver`,
      ),
    ).toHaveLength(1)
  },
)
