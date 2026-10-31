// Baja programada, readmision y correccion de la fecha de cese desde el panel
// (RF-GP-03, RN-14, RN-27, ADR-062; tareas 25.6 y 25.7 de la 2.3.0).
//
// LOS CINCO RECORRIDOS:
//  1. programar una baja (y aplicarla ya) y anularla;
//  2. dar de alta de nuevo a una persona, con PIN nuevo y su tarjeta;
//  3. dar de alta de nuevo en lote, todo o nada;
//  4. importar un CSV que readmite;
//  5. corregir la fecha de cese.
//
// El backend no participa: `support/employment.ts` reproduce las reglas del
// contrato que la interfaz tiene que respetar (la tabla de la baja, los `409` por
// causa, el todo-o-nada, los limites de la correccion), y `support/setupWizard.ts`
// el asistente con su importacion. Que la tarea diaria aplique la baja, que la
// readmision abra el periodo y acuñe la tarjeta o que la correccion deje su asiento
// lo prueba el backend. Este fichero prueba el recorrido: que cada boton llama a su
// endpoint con lo que se ha pedido, que el panel dice antes de confirmar que va a
// pasar y que cada error cuenta que hacer.
import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { EMPLOYEE_UUID, logIn, stubManagementApi } from './support/admin'
import { expectNoBlockingViolations } from './support/axe'
import {
  NEW_PIN,
  type EmploymentStub,
  activePerson,
  addDays,
  formerPerson,
  stubEmploymentApi,
  todayInCenter,
} from './support/employment'
import { IMPORT_REHIRE_DIGEST, reachImportStep, stubOnboardingApi } from './support/setupWizard'

const A = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b01'
const B = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b02'
const C = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b03'

const offboardRequests = (api: EmploymentStub, suffix: string) =>
  api.requests.filter((request) => request.method === 'POST' && request.path.endsWith(suffix))

async function openEmployee(page: Page, uuid: string): Promise<void> {
  await page.goto(`/employees/${uuid}`)
  await expect(page.getByTestId('employee-name')).toBeVisible()
}

test(
  'programa una baja con fecha futura, la ve como «baja programada» y la anula',
  { tag: ['@RF-GP-03', '@RN-14'] },
  async ({ page }) => {
    await stubManagementApi(page)
    const api = await stubEmploymentApi(page, [
      activePerson(EMPLOYEE_UUID, 'Youssef', '2026-08-14'),
    ])
    await logIn(page)
    await openEmployee(page, EMPLOYEE_UUID)

    await page.getByTestId('offboard-open').click()

    const dialog = page.getByRole('dialog', { name: 'Baja' })

    await dialog.getByLabel(/Fecha de cese/).fill(addDays(todayInCenter(), 30))
    await dialog.getByLabel(/Motivo del cese/).selectOption('endOfContract')

    // Antes de confirmar dice que va a pasar: programada, sigue fichando, se puede anular.
    await expect(dialog.getByTestId('offboard-outcome-scheduled')).toContainText('seguirá fichando')
    await expect(dialog.getByRole('table')).toContainText('Baja programada')
    // «Aplicar ya» no tiene efecto con una fecha futura: no se ofrece.
    await expect(dialog.getByTestId('apply-now')).toHaveCount(0)
    await expectNoBlockingViolations(page, { include: '[role="dialog"]' })

    await dialog.getByRole('button', { name: 'Programar la baja' }).click()
    await expect(dialog).toBeHidden()

    await expect(page.getByTestId('employee-status')).toHaveText('Baja programada')
    await expect(page.getByTestId('offboard-scheduled')).toContainText('la persona ficha hasta el')
    await expect(page.getByTestId('offboard-open')).toHaveCount(0)
    await expectNoBlockingViolations(page)

    // La peticion no lleva «Aplicar ya».
    const [scheduledRequest] = offboardRequests(api, '/offboard')

    expect(scheduledRequest?.body).toMatchObject({
      terminated_at: addDays(todayInCenter(), 30),
      reason: 'Fin de contrato',
    })
    expect(scheduledRequest?.body).not.toHaveProperty('apply_now')

    // Anular: enseña desde que estado y hacia cual, y no cambia tarjeta ni PIN.
    await page.getByTestId('offboard-cancel').click()

    const cancel = page.getByRole('dialog', { name: 'Anular la baja programada' })

    await expect(cancel.getByRole('table')).toContainText('Baja programada')
    await expect(cancel.getByRole('table')).toContainText('En alta')
    await expect(cancel).toContainText('No hay nada que reactivar')
    await cancel.getByTestId('confirm-dialog-confirm').click()
    await expect(cancel).toBeHidden()

    await expect(page.getByTestId('employee-status')).toHaveText('En alta')
    await expect(page.getByTestId('offboard-scheduled')).toHaveCount(0)
    await expect(page.getByTestId('offboard-open')).toBeVisible()
    expect(offboardRequests(api, '/offboard/cancel')).toHaveLength(1)
  },
)

test(
  'con la fecha de hoy la baja se programa, y «Aplicar ya» la hace efectiva al confirmar',
  { tag: ['@RF-GP-03', '@RN-14'] },
  async ({ page }) => {
    await stubManagementApi(page)
    const api = await stubEmploymentApi(page, [
      activePerson(EMPLOYEE_UUID, 'Youssef', '2026-08-14'),
    ])
    await logIn(page)
    await openEmployee(page, EMPLOYEE_UUID)

    await page.getByTestId('offboard-open').click()

    const dialog = page.getByRole('dialog', { name: 'Baja' })
    const date = dialog.getByLabel(/Fecha de cese/)

    // Por omision: hoy, y por tanto programada.
    await expect(date).toHaveValue(todayInCenter())
    await dialog.getByLabel(/Motivo del cese/).selectOption('dismissal')
    await expect(dialog.getByTestId('offboard-outcome-scheduled')).toBeVisible()

    // Una fecha anterior al alta se rechaza en el campo antes de enviar.
    await date.fill('2026-08-01')
    await expect(date).toHaveAttribute('aria-invalid', 'true')
    await expect(dialog.getByTestId('confirm-dialog-confirm')).toBeDisabled()
    await date.fill(todayInCenter())

    await dialog.getByLabel(/Aplicar ya/).check()
    await expect(dialog.getByTestId('offboard-outcome-immediate')).toContainText('No se deshace')
    await expectNoBlockingViolations(page, { include: '[role="dialog"]' })

    await dialog.getByRole('button', { name: 'Confirmar la baja' }).click()
    await expect(dialog).toBeHidden()

    await expect(page.getByText(/Esta persona está de baja desde/)).toBeVisible()
    await expect(page.getByTestId('employee-status')).toHaveText('De baja')
    expect(offboardRequests(api, '/offboard')[0]?.body).toMatchObject({ apply_now: true })
  },
)

test(
  'da de alta de nuevo a una persona con un solo dato, con PIN nuevo y su tarjeta',
  { tag: ['@RF-GP-03', '@RN-27', '@RF-ID-09', '@RF-QR-04'] },
  async ({ page }) => {
    await stubManagementApi(page)
    const api = await stubEmploymentApi(page, [formerPerson(EMPLOYEE_UUID, 'Lucia')])
    await logIn(page)
    await openEmployee(page, EMPLOYEE_UUID)

    await expect(page.getByTestId('employee-status')).toHaveText('De baja')
    await page.getByTestId('rehire-open').click()

    const dialog = page.getByRole('dialog', { name: 'Dar de alta de nuevo' })

    // Un solo dato obligatorio, hoy por defecto; no vuelve a pedir DNI, nombre ni codigo.
    await expect(dialog.getByLabel(/Fecha de alta/)).toHaveValue(todayInCenter())
    await expect(dialog.getByLabel(/Documento de identidad/)).toHaveCount(0)
    await expect(dialog.getByLabel('Nombre', { exact: true })).toHaveCount(0)
    await expect(dialog.getByRole('table')).toContainText('De baja')
    await expect(dialog.getByRole('table')).toContainText('Pendiente de imprimir')

    // Aviso de fecha futura (decision del propietario, 31-10-2026): es un aviso, no un bloqueo.
    await dialog.getByLabel(/Fecha de alta/).fill(addDays(todayInCenter(), 20))
    await expect(dialog.getByTestId('rehire-future-warning')).toContainText(
      'los fichajes anteriores a esa fecha se rechazan',
    )
    await expect(dialog.getByTestId('confirm-dialog-confirm')).toBeEnabled()
    await dialog.getByLabel(/Fecha de alta/).fill(todayInCenter())
    await expect(dialog.getByTestId('rehire-future-warning')).toHaveCount(0)

    await dialog.getByTestId('rehire-reset-pin').check()
    await expectNoBlockingViolations(page, { include: '[role="dialog"]' })
    await dialog.getByTestId('confirm-dialog-confirm').click()

    // 1. El PIN nuevo, una sola vez.
    const reveal = page.getByRole('dialog', { name: 'PIN emitido' })

    await expect(reveal.getByTestId('pin-value')).toHaveText(NEW_PIN)
    await expectNoBlockingViolations(page, { include: '[role="dialog"]' })
    await reveal.getByRole('button', { name: /Ya lo he anotado/ }).click()
    await expect(page.getByText(NEW_PIN)).toHaveCount(0)

    // 2. La tarjeta nueva, ofrecida para imprimir en el acto.
    const card = page.getByRole('dialog', { name: 'Tarjeta nueva' })

    await expect(card).toContainText('pendiente de imprimir')
    await expectNoBlockingViolations(page, { include: '[role="dialog"]' })

    const download = page.waitForEvent('download')

    await card.getByTestId('rehire-card-print').click()

    expect((await download).suggestedFilename()).toBe('credencial.pdf')
    await expect(card.getByTestId('rehire-card-printed')).toBeVisible()
    await card.getByTestId('rehire-card-close').click()
    await expect(card).toBeHidden()

    // La ficha vuelve a estar en alta y conserva su historia de periodos.
    await expect(page.getByTestId('employee-status')).toHaveText('En alta')
    await expect(page.getByTestId('employment-period')).toHaveCount(2)
    await expect(page.getByText(NEW_PIN)).toHaveCount(0)
    expect(api.requests.filter((request) => request.path.endsWith('/rehire'))[0]?.body).toEqual({
      hired_at: todayInCenter(),
      reset_pin: true,
    })
  },
)

test(
  'si la baja es de hoy avisa de esperar a que los quioscos esten sincronizados',
  { tag: ['@RN-27', '@RN-14'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await stubEmploymentApi(page, [
      // Cese de ayer y baja efectiva hoy: lo que avisa es `offboarded_at`, no el cese.
      formerPerson(EMPLOYEE_UUID, 'Lucia', {
        terminatedAt: addDays(todayInCenter(), -1),
        offboardedAt: new Date().toISOString(),
      }),
    ])
    await logIn(page)
    await openEmployee(page, EMPLOYEE_UUID)

    await page.getByTestId('rehire-open').click()

    const dialog = page.getByRole('dialog', { name: 'Dar de alta de nuevo' })

    await expect(dialog.getByTestId('rehire-recent-warning')).toContainText(
      'espera a que los quioscos estén sincronizados',
    )
    await expectNoBlockingViolations(page, { include: '[role="dialog"]' })
  },
)

test(
  'da de alta de nuevo a varias personas a la vez: todo o nada, y el lote de tarjetas',
  { tag: ['@RN-27', '@RF-GP-03', '@RF-QR-04'] },
  async ({ page }) => {
    const rejected = formerPerson(C, 'Carla')

    rejected.rejectInBatch = 'Esta persona ya no se puede dar de alta de nuevo.'

    await stubManagementApi(page)
    const api = await stubEmploymentApi(page, [
      formerPerson(A, 'Ana'),
      formerPerson(B, 'Beto'),
      rejected,
    ])
    await logIn(page)
    await page.goto('/employees?status=terminated')

    await expect(page.getByTestId('employee-row')).toHaveCount(3)
    // Sin nadie marcado no hay nada que hacer: el boton del lote aparece con la primera casilla.
    await expect(page.getByTestId('rehire-selected')).toHaveCount(0)

    // Un boton por fila y seleccion multiple con casilla de cabecera.
    await expect(page.getByTestId('rehire-row')).toHaveCount(3)
    await page.getByLabel('Seleccionar a todas las personas de baja de esta página').check()
    await expect(page.getByTestId('selection-summary')).toHaveText('3 personas seleccionadas')
    await expectNoBlockingViolations(page)

    await page.getByTestId('rehire-selected').click()

    const dialog = page.getByRole('dialog', { name: 'Dar de alta de nuevo a varias personas' })

    await expect(dialog.getByLabel(/Fecha de alta/)).toHaveValue(todayInCenter())
    await dialog.getByTestId('confirm-dialog-confirm').click()

    // Todo o nada: el error por posicion dice a quien, y no se ha escrito nada.
    const errors = dialog.getByTestId('rehire-batch-errors')

    await expect(errors).toContainText('Carla Prueba')
    await expect(errors).toContainText('Esta persona ya no se puede dar de alta de nuevo.')
    await expect(errors).toContainText('no se ha escrito nada')
    expect(api.person(A).employee.status).toBe('terminated')
    expect(api.person(B).employee.status).toBe('terminated')
    await expectNoBlockingViolations(page, { include: '[role="dialog"]' })

    // Se quita a quien lo impide y se repite.
    await dialog.getByTestId('confirm-dialog-cancel').click()
    await expect(dialog).toBeHidden()
    await page.getByLabel('Seleccionar a Carla Prueba').uncheck()
    await expect(page.getByTestId('selection-summary')).toHaveText('2 personas seleccionadas')
    await page.getByTestId('rehire-selected').click()
    await dialog.getByTestId('confirm-dialog-confirm').click()

    // Lote de tarjetas para imprimir.
    const cards = page.getByRole('dialog', { name: 'Tarjeta nueva' })

    await expect(cards.getByTestId('rehire-card-list')).toContainText('Ana Prueba')
    await expect(cards.getByTestId('rehire-card-list')).toContainText('Beto Prueba')
    await expect(cards.getByTestId('rehire-card-batch-scope')).toBeVisible()

    const download = page.waitForEvent('download')

    await cards.getByTestId('rehire-card-print').click()

    expect((await download).suggestedFilename()).toBe('credenciales.pdf')
    await cards.getByTestId('rehire-card-close').click()

    expect(api.person(A).employee.status).toBe('active')
    expect(api.person(B).employee.status).toBe('active')
    expect(api.person(C).employee.status).toBe('terminated')
    // La lista se ha releido: solo queda de baja quien no se pudo dar de alta de nuevo.
    await expect(page.getByTestId('employee-row')).toHaveCount(1)
  },
)

test(
  'importa un CSV que readmite: lo propone en el resumen, y si cambia a quien se readmitiria obliga a validar otra vez',
  { tag: ['@RN-27', '@RF-GP-05'] },
  async ({ page }) => {
    await stubOnboardingApi(page, { importOutcome: 'rehireStale' })
    const applied: string[] = []

    page.on('request', (request) => {
      if (request.method() === 'POST' && request.url().endsWith('/api/v1/employees/import')) {
        applied.push(request.postData() ?? '')
      }
    })

    await reachImportStep(page)

    await page.getByTestId('import-file').setInputFiles({
      name: 'plantilla.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from(
        'nombre,apellidos,dni,fecha_alta\nLucia,Ferrer,12345678Z,2027-04-01\nMarta,Vidal,,2027-04-01\n',
      ),
    })
    await page.getByTestId('validate').click()

    // La fila que readmite, con su aviso y su «que hacer», y la rechazada por identidad.
    await expect(page.getByTestId('import-row-2')).toContainText('Dar de alta de nuevo')
    await expect(page.getByTestId('import-row-2')).toContainText(
      'Comprueba que es la persona correcta',
    )
    await expect(page.getByTestId('import-row-3')).toContainText('Rechazada')
    await expect(page.getByTestId('import-row-3')).toContainText(
      'Corrige el documento o el nombre en el fichero',
    )
    await expect(page.getByTestId('rehire-notice')).toContainText('1 persona de baja')
    await expect(page.getByTestId('rehire-notice')).toContainText(
      'los fichajes anteriores a esa fecha se rechazan',
    )
    await expect(page.getByTestId('report-status')).toContainText('1 altas de nuevo')
    await expectNoBlockingViolations(page)

    // Primera aplicacion: el servidor dice que lo revisado ya no es lo que se aplicaria.
    await page.getByTestId('apply').click()

    await expect(page.getByTestId('import-stale')).toContainText('Pulsa «Validar» otra vez')
    await expect(page.getByTestId('apply')).toHaveCount(0)
    await expectNoBlockingViolations(page)

    // Se valida otra vez y se aplica con el resumen nuevo.
    await page.getByTestId('validate').click()
    await expect(page.getByTestId('import-stale')).toHaveCount(0)
    await page.getByTestId('apply').click()

    await expect(page.getByTestId('rehired-notice')).toContainText('tarjeta nueva pendiente')
    expect(applied.filter((body) => body.includes('name="mode"\r\n\r\napply'))).toHaveLength(2)
    expect(applied.at(-1)).toContain(IMPORT_REHIRE_DIGEST)
    await expectNoBlockingViolations(page)
  },
)

test(
  'corrige la fecha de cese con motivo, enseñando la fecha anterior y la nueva',
  { tag: ['@RN-27', '@RL-04', '@RF-GP-03'] },
  async ({ page }) => {
    await stubManagementApi(page)
    const api = await stubEmploymentApi(page, [formerPerson(EMPLOYEE_UUID, 'Lucia')])
    await logIn(page)
    await openEmployee(page, EMPLOYEE_UUID)

    await expect(page.getByTestId('employment-period')).toHaveCount(1)
    await expectNoBlockingViolations(page)

    // Con el teclado: se abre desde el boton y Escape devuelve el foco a el.
    const open = page.getByTestId('correct-termination')

    await open.focus()
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('dialog', { name: 'Corregir la fecha de cese' })

    await expect(dialog).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(open).toBeFocused()

    await open.click()

    const confirm = dialog.getByTestId('confirm-dialog-confirm')

    // Sin cambiar la fecha ni dar un motivo no hay nada que registrar.
    await expect(confirm).toBeDisabled()
    await dialog.getByLabel(/Fecha de cese/).fill('2026-09-02')
    await dialog.getByLabel(/Motivo de la corrección/).fill('corto')
    // Mientras se escribe no se avisa; sale al salir del campo.
    await expect(dialog).not.toContainText('Escribe al menos 10 caracteres')
    await dialog.getByLabel(/Fecha de cese/).focus()
    await expect(dialog).toContainText('Escribe al menos 10 caracteres')
    await expect(confirm).toBeDisabled()

    // Y al intentar confirmar sin motivo valido, el aviso vuelve y el foco va al campo.
    await dialog.getByLabel(/Motivo de la corrección/).fill('')
    await dialog.getByLabel(/Fecha de cese/).focus()
    await confirm.click({ force: true })
    await expect(dialog.getByLabel(/Motivo de la corrección/)).toBeFocused()
    await dialog.getByLabel(/Motivo de la corrección/).fill('corto')

    await dialog.getByLabel(/Motivo de la corrección/).fill('Trabajó hasta el 2 según el cuadrante')

    // Desde que fecha y hacia cual, y que no se borra nada.
    await expect(dialog.getByRole('table')).toContainText('31 ago 2026')
    await expect(dialog.getByRole('table')).toContainText('2 sept 2026')
    await expect(dialog.getByTestId('correction-later')).toContainText('no se reprocesan')
    await expect(dialog).toContainText('No se borra nada')
    await expectNoBlockingViolations(page, { include: '[role="dialog"]' })

    await confirm.click()
    await expect(dialog).toBeHidden()

    // El dato anterior sigue a la vista junto al nuevo, con quien, cuando y el motivo...
    const history = page.getByTestId('termination-correction')

    await expect(history).toHaveCount(1)
    await expect(history).toContainText('De 31 ago 2026 a 2 sept 2026')
    await expect(history).toContainText('Dirección RRHH')
    await expect(history).toContainText('Motivo: Trabajó hasta el 2 según el cuadrante')
    await expect(page.getByTestId('employment-period').first()).toContainText('2 sept 2026')

    // ...y tambien tras recargar: viene en la ficha, no en la memoria de la sesion.
    await page.reload()
    await expect(page.getByTestId('employee-name')).toBeVisible()
    await expect(page.getByTestId('termination-correction')).toHaveCount(1)
    await expect(page.getByTestId('termination-correction')).toContainText(
      'De 31 ago 2026 a 2 sept 2026',
    )
    await expect(page.getByTestId('termination-correction')).toContainText(
      'Motivo: Trabajó hasta el 2 según el cuadrante',
    )
    expect(
      api.requests.filter((request) => request.path.endsWith('/termination-corrections'))[0]?.body,
    ).toEqual({ terminated_at: '2026-09-02', reason: 'Trabajó hasta el 2 según el cuadrante' })
    expect(api.person(EMPLOYEE_UUID).corrections).toHaveLength(1)
    await expectNoBlockingViolations(page)
  },
)

test(
  'la vuelta de la temporada en dos paginas: la seleccion se conserva y el rechazo de la persona 32 dice a quien',
  { tag: ['@RN-27', '@RF-GP-03'] },
  async ({ page }) => {
    const temporera = (n: number) =>
      formerPerson(
        `0199f0c2-1f4a-7c3e-9b21-${String(n).padStart(12, '0')}`,
        `Temporera${String(n).padStart(2, '0')}`,
      )
    const rechazada = {
      ...temporera(32),
      rejectInBatch: 'Esta persona ya no se puede dar de alta de nuevo.',
    }
    // 35 personas de baja: 30 en la primera pagina y 5 en la segunda; la 32, en la segunda.
    const temporada = [
      ...Array.from({ length: 31 }, (_, index) => temporera(index + 1)),
      rechazada,
      ...Array.from({ length: 3 }, (_, index) => temporera(index + 33)),
    ]

    await stubManagementApi(page)
    const api = await stubEmploymentApi(page, temporada)
    await logIn(page)
    await page.goto('/employees?status=terminated')

    await expect(page.getByTestId('employee-row')).toHaveCount(30)
    await page.getByLabel('Seleccionar a todas las personas de baja de esta página').check()
    await page.getByRole('button', { name: 'Siguiente' }).click()
    await expect(page.getByTestId('employee-row')).toHaveCount(5)
    await page.getByLabel('Seleccionar a todas las personas de baja de esta página').check()
    await expect(page.getByTestId('selection-summary')).toHaveText('35 personas seleccionadas')

    // Al volver a la primera pagina, lo marcado sigue marcado.
    await page.getByRole('button', { name: 'Anterior' }).click()
    await expect(page.getByLabel('Seleccionar a Temporera01 Prueba', { exact: true })).toBeChecked()

    await page.getByTestId('rehire-selected').click()

    const dialog = page.getByRole('dialog', { name: 'Dar de alta de nuevo a varias personas' })

    await dialog.getByTestId('confirm-dialog-confirm').click()

    // Todo o nada: el error de la posicion 31 (la persona 32) la nombra y queda a la vista.
    const errors = dialog.getByTestId('rehire-batch-errors')

    await expect(dialog.getByTestId('rehire-batch-error')).toHaveText([
      'Temporera32 Prueba: Esta persona ya no se puede dar de alta de nuevo.',
    ])
    await expect(errors).toBeInViewport()
    expect(temporada.every((person) => person.employee.status === 'terminated')).toBe(true)
    await expectNoBlockingViolations(page, { include: '[role="dialog"]' })

    // Se la quita en su pagina y se repite con las otras 34.
    await dialog.getByTestId('confirm-dialog-cancel').click()
    await expect(dialog).toBeHidden()
    await page.getByRole('button', { name: 'Siguiente' }).click()
    await page.getByLabel('Seleccionar a Temporera32 Prueba', { exact: true }).uncheck()
    await expect(page.getByTestId('selection-summary')).toHaveText('34 personas seleccionadas')
    await page.getByTestId('rehire-selected').click()
    await dialog.getByTestId('confirm-dialog-confirm').click()

    await expect(page.getByRole('dialog', { name: 'Tarjeta nueva' })).toBeVisible()

    const lote = (
      offboardRequests(api, '/employees/rehire').at(-1)?.body as { employee_uuids: string[] } | null
    )?.employee_uuids

    expect(lote).toHaveLength(34)
    expect(lote).not.toContain(rechazada.employee.uuid)
    expect(api.person(rechazada.employee.uuid).employee.status).toBe('terminated')
  },
)
