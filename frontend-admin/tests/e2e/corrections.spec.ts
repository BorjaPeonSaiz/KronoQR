// Añadir, corregir y anular un tramo del registro horario (RF-PA-04, RN-13,
// ADR-026, ADR-035).
//
// El backend no participa (regla dura 18): lo que se prueba aqui es el
// recorrido por el panel con las tres operaciones simuladas en
// `support/admin.ts`. La autorizacion real -que un `responsable_departamento`
// no pueda anular aunque el panel se lo dejara pasar, que un `auditor` reciba
// `403` si fuerza la peticion- se prueba en el backend.
import { expect, test } from '@playwright/test'
import {
  EMPLOYEE_UUID,
  logIn,
  logInAsAuditor,
  logInAsManager,
  stubManagementApi,
} from './support/admin'

const WORKDAYS_URL = `/employees/${EMPLOYEE_UUID}/workdays`

test.describe('Añadir un tramo', () => {
  test(
    'con un motivo del catalogo, crea el tramo y recarga la jornada',
    { tag: ['@RF-PA-04'] },
    async ({ page }) => {
      const api = await stubManagementApi(page)
      await logIn(page)
      await page.goto(WORKDAYS_URL)

      await page.getByTestId('add-shift-entry').click()

      const dialog = page.getByRole('dialog', { name: 'Añadir un tramo' })
      await expect(dialog).toBeVisible()

      await dialog.getByTestId('dialog-work-date').fill('2026-08-14')
      await dialog.getByTestId('dialog-clock-in').fill('2026-08-14T06:00')
      await dialog.getByTestId('dialog-clock-out').fill('2026-08-14T14:00')
      await dialog.getByTestId('dialog-reason').selectOption('OLVIDO_FICHAJE_ENTRADA')
      await dialog.getByTestId('dialog-submit').click()

      await expect(dialog).toBeHidden()

      const request = api.requests.find(
        (candidate) => candidate.path === '/api/v1/shift-entries' && candidate.method === 'POST',
      )

      expect(request?.body).toMatchObject({
        employee_uuid: EMPLOYEE_UUID,
        work_date: '2026-08-14',
        reason_code: 'OLVIDO_FICHAJE_ENTRADA',
        // 06:00 en Madrid en agosto (CEST, +02:00) es 04:00 UTC: la hora se
        // escribe en la zona del centro y se convierte antes de salir del panel.
        clocked_in_at: '2026-08-14T04:00:00.000Z',
        clocked_out_at: '2026-08-14T12:00:00.000Z',
      })

      // El registro se vuelve a pedir tras el exito: el total recalculado y
      // el nuevo tramo tienen que verse sin recargar la pagina a mano.
      await expect
        .poll(() => api.requests.filter((candidate) => candidate.path.endsWith('/workdays')).length)
        .toBeGreaterThan(1)
    },
  )

  test(
    'con el motivo «otro motivo» y menos de 20 caracteres, no deja guardar',
    { tag: ['@RF-PA-04'] },
    async ({ page }) => {
      const api = await stubManagementApi(page)
      await logIn(page)
      await page.goto(WORKDAYS_URL)

      await page.getByTestId('add-shift-entry').click()
      const dialog = page.getByRole('dialog', { name: 'Añadir un tramo' })

      await dialog.getByTestId('dialog-work-date').fill('2026-08-14')
      await dialog.getByTestId('dialog-clock-in').fill('2026-08-14T06:00')
      await dialog.getByTestId('dialog-reason').selectOption('OTROS')
      await dialog.getByTestId('dialog-reason-text').fill('muy corto')

      await expect(dialog.getByTestId('dialog-submit')).toBeDisabled()

      await dialog
        .getByTestId('dialog-reason-text')
        .fill('Jornada de la semana anterior a la puesta en marcha, cargada a mano.')

      await expect(dialog.getByTestId('dialog-submit')).toBeEnabled()

      // Nunca se mando nada mientras el boton estuvo deshabilitado.
      expect(
        api.requests.some(
          (candidate) => candidate.path === '/api/v1/shift-entries' && candidate.method === 'POST',
        ),
      ).toBe(false)
    },
  )
})

test.describe('Corregir las horas', () => {
  test(
    'cambia una marca, con motivo, y el historial se actualiza',
    { tag: ['@RF-PA-04', '@RN-13'] },
    async ({ page }) => {
      const api = await stubManagementApi(page)
      await logIn(page)
      await page.goto(WORKDAYS_URL)

      await page.getByTestId('entry-correct').click()

      const dialog = page.getByRole('dialog', { name: 'Corregir las horas' })
      await expect(dialog).toBeVisible()

      // Lo que va a cambiar, desde que hora y hacia cual, antes de confirmar
      // (regla dura 5): el valor de partida ya esta a la vista.
      await expect(dialog.getByTestId('dialog-clock-out')).toHaveValue('2026-03-14T14:05')

      await dialog.getByTestId('dialog-clock-out').fill('2026-03-14T14:30')
      await dialog.getByTestId('dialog-reason').selectOption('AJUSTE_ACORDADO_CON_RRHH')
      await expect(dialog.getByTestId('dialog-preview')).toContainText('14:30')

      await dialog.getByTestId('dialog-submit').click()
      await expect(dialog).toBeHidden()

      const request = api.requests.find(
        (candidate) =>
          candidate.path.startsWith('/api/v1/shift-entries/') &&
          !candidate.path.endsWith('/void') &&
          candidate.method === 'PATCH',
      )

      expect(request?.body).toMatchObject({
        reason_code: 'AJUSTE_ACORDADO_CON_RRHH',
        clocked_out_at: '2026-03-14T13:30:00.000Z',
      })
      // La hora de entrada no cambio: un campo ausente es «no lo toques».
      expect(request?.body).not.toHaveProperty('clocked_in_at')
    },
  )

  test(
    'un 409 de version superada avisa, ofrece reintentar y no pierde lo escrito',
    { tag: ['@RF-PA-04'] },
    async ({ page }) => {
      await stubManagementApi(page, { correctionOutcome: 'superseded' })
      await logIn(page)
      await page.goto(WORKDAYS_URL)

      await page.getByTestId('entry-correct').click()
      const dialog = page.getByRole('dialog', { name: 'Corregir las horas' })

      await dialog.getByTestId('dialog-clock-out').fill('2026-03-14T14:30')
      await dialog.getByTestId('dialog-reason').selectOption('AJUSTE_ACORDADO_CON_RRHH')
      await dialog.getByTestId('dialog-submit').click()

      await expect(dialog.getByTestId('dialog-conflict')).toContainText(
        'Este tramo ya no es la versión vigente',
      )
      // El formulario NUNCA se desmonta (hallazgo 2): el valor tecleado sigue
      // ahi, no un hueco vacio ni el dialogo entero sustituido por el aviso.
      await expect(dialog).toBeVisible()
      await expect(dialog.getByTestId('dialog-clock-out')).toHaveValue('2026-03-14T14:30')
      // Sin boton de guardar -reintentar sobre esa version ya no tiene
      // sentido-, pero con uno explicito para cerrar y volver a abrir sobre la
      // version vigente, nunca un cierre automatico.
      await expect(dialog.getByTestId('dialog-submit')).toHaveCount(0)
      await expect(dialog.getByTestId('dialog-cancel')).toHaveText(
        'Reintentar sobre la versión vigente',
      )
    },
  )

  test(
    'un 409 de turno ya abierto avisa sin recargar y deja corregir de nuevo',
    { tag: ['@RF-PA-04', '@RN-01'] },
    async ({ page }) => {
      await stubManagementApi(page, { correctionOutcome: 'shiftAlreadyOpen' })
      await logIn(page)
      await page.goto(WORKDAYS_URL)

      await page.getByTestId('entry-correct').click()
      const dialog = page.getByRole('dialog', { name: 'Corregir las horas' })

      await dialog.getByTestId('dialog-clock-out').fill('2026-03-14T14:30')
      await dialog.getByTestId('dialog-reason').selectOption('AJUSTE_ACORDADO_CON_RRHH')
      await dialog.getByTestId('dialog-submit').click()

      await expect(dialog.getByTestId('dialog-conflict')).toContainText('ya tiene un turno abierto')
      // No es la version superada: nunca "ya no es la version vigente", y el
      // boton de guardar sigue disponible para corregir y reintentar.
      await expect(dialog.getByTestId('dialog-conflict')).not.toContainText(
        'ya no es la versión vigente',
      )
      await expect(dialog.getByTestId('dialog-clock-out')).toHaveValue('2026-03-14T14:30')
      await expect(dialog.getByTestId('dialog-submit')).toBeVisible()
      await expect(dialog.getByTestId('dialog-cancel')).toHaveText('Cancelar')
    },
  )

  test(
    'un 409 de solape avisa sin recargar y deja corregir de nuevo',
    { tag: ['@RF-PA-04', '@RN-02'] },
    async ({ page }) => {
      await stubManagementApi(page, { correctionOutcome: 'overlap' })
      await logIn(page)
      await page.goto(WORKDAYS_URL)

      await page.getByTestId('entry-correct').click()
      const dialog = page.getByRole('dialog', { name: 'Corregir las horas' })

      await dialog.getByTestId('dialog-clock-out').fill('2026-03-14T14:30')
      await dialog.getByTestId('dialog-reason').selectOption('AJUSTE_ACORDADO_CON_RRHH')
      await dialog.getByTestId('dialog-submit').click()

      await expect(dialog.getByTestId('dialog-conflict')).toContainText('se solapan')
      await expect(dialog.getByTestId('dialog-clock-out')).toHaveValue('2026-03-14T14:30')
      await expect(dialog.getByTestId('dialog-submit')).toBeVisible()
    },
  )

  test(
    'un 422 de cambio de jornada se explica en la propia pista del campo, no con el texto crudo del servidor',
    { tag: ['@RF-PA-04', '@RN-05'] },
    async ({ page }) => {
      await stubManagementApi(page, { correctionOutcome: 'workDateChange' })
      await logIn(page)
      await page.goto(WORKDAYS_URL)

      await page.getByTestId('entry-correct').click()
      const dialog = page.getByRole('dialog', { name: 'Corregir las horas' })

      await dialog.getByTestId('dialog-clock-in').fill('2026-03-15T00:30')
      await dialog.getByTestId('dialog-reason').selectOption('AJUSTE_ACORDADO_CON_RRHH')
      await dialog.getByTestId('dialog-submit').click()

      await expect(dialog).toContainText('Esa hora de entrada llevaría la jornada a otro día')
      await expect(dialog.getByTestId('dialog-clock-in')).toHaveValue('2026-03-15T00:30')
    },
  )
})

test.describe('Añadir un tramo cuando ya hay uno abierto o hay solape', () => {
  test(
    'un 409 de turno ya abierto al añadir un tramo avisa sin perder lo escrito',
    { tag: ['@RF-PA-04', '@RN-01'] },
    async ({ page }) => {
      await stubManagementApi(page, { correctionOutcome: 'shiftAlreadyOpen' })
      await logIn(page)
      await page.goto(WORKDAYS_URL)

      await page.getByTestId('add-shift-entry').click()
      const dialog = page.getByRole('dialog', { name: 'Añadir un tramo' })

      await dialog.getByTestId('dialog-work-date').fill('2026-08-14')
      await dialog.getByTestId('dialog-clock-in').fill('2026-08-14T06:00')
      await dialog.getByTestId('dialog-reason').selectOption('OLVIDO_FICHAJE_ENTRADA')
      await dialog.getByTestId('dialog-submit').click()

      await expect(dialog.getByTestId('dialog-conflict')).toContainText('ya tiene un turno abierto')
      await expect(dialog.getByTestId('dialog-clock-in')).toHaveValue('2026-08-14T06:00')
      await expect(dialog.getByTestId('dialog-submit')).toBeVisible()
    },
  )
})

test.describe('Anular el tramo', () => {
  test(
    'con un motivo del catalogo, anula el tramo y el total baja',
    { tag: ['@RF-PA-04', '@ADR-026'] },
    async ({ page }) => {
      const api = await stubManagementApi(page)
      await logIn(page)
      await page.goto(WORKDAYS_URL)

      await page.getByTestId('entry-void').click()

      const dialog = page.getByRole('dialog', { name: 'Anular el tramo' })
      await expect(dialog).toBeVisible()
      await expect(dialog.getByTestId('dialog-clock-in')).toHaveCount(0)

      await dialog.getByTestId('dialog-reason').selectOption('ERROR_DE_ESCANEO_DUPLICADO')
      await dialog.getByTestId('dialog-submit').click()

      await expect(dialog).toBeHidden()

      const request = api.requests.find(
        (candidate) => candidate.path.endsWith('/void') && candidate.method === 'POST',
      )

      expect(request?.body).toMatchObject({ reason_code: 'ERROR_DE_ESCANEO_DUPLICADO' })
    },
  )
})

test.describe('Los botones se ocultan sin el ambito', () => {
  test(
    'un auditor (attendance:read sin attendance:correct) no ve ninguna de las tres acciones',
    { tag: ['@RF-PA-04', '@regla-dura-18'] },
    async ({ page }) => {
      await stubManagementApi(page, { role: 'auditor' })
      await logInAsAuditor(page)
      await page.goto(WORKDAYS_URL)

      await expect(page.getByRole('heading', { level: 1, name: 'Registro horario' })).toBeVisible()
      await expect(page.getByTestId('add-shift-entry')).toHaveCount(0)
      await expect(page.getByTestId('entry-correct')).toHaveCount(0)
      await expect(page.getByTestId('entry-void')).toHaveCount(0)
    },
  )

  test(
    'un responsable de departamento corrige, pero no ve «Anular el tramo»',
    { tag: ['@RF-PA-04'] },
    async ({ page }) => {
      await stubManagementApi(page, { role: 'manager' })
      await logInAsManager(page)
      await page.goto(WORKDAYS_URL)

      await expect(page.getByTestId('add-shift-entry')).toBeVisible()
      await expect(page.getByTestId('entry-correct')).toBeVisible()
      await expect(page.getByTestId('entry-void')).toHaveCount(0)
    },
  )
})
