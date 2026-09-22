// Ausencias: vacaciones, baja médica y permiso (RF-GP-04, tarea 3.10).
//
// El backend no participa (regla dura 18): lo que se prueba aquí es el
// recorrido por el panel, con la API simulada **con estado** de
// `support/admin.ts` -las tres operaciones mutan `absencesState` exactamente
// como lo haría el servidor, y `GET /reports/period` con `granularity=day`
// la lee-. La autorización real -que el servidor rechace a quien no lleva el
// ámbito- se prueba en el backend.
import type { Locator, Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import type { Absence } from '@/shared/api/types'
import { EMPLOYEE_UUID, logIn, logInAsManager, stubManagementApi } from './support/admin'

/** La baja de partida de los recorridos de corrección y anulación (RN-13). */
const SICK_LEAVE: Absence = {
  uuid: '0199f8d2-0001-7a10-9c60-6d7e8f9a0b12',
  employee_uuid: EMPLOYEE_UUID,
  employee_code: 'E7QK2MXPR',
  employee_name: 'Youssef Amrani',
  department_id: 3,
  department_name: 'Recepción',
  type: 'sick_leave',
  starts_on: '2026-03-10',
  ends_on: '2026-03-12',
  days: 3,
  note: 'Gripe estacional.',
  status: 'active',
  version: 1,
  supersedes_uuid: null,
  superseded_by_uuid: null,
  change_reason: null,
  voided_at: null,
  void_reason: null,
  created_at: '2026-03-09T09:00:00.000000Z',
}

/** El informe del rango `[from, to]` con granularidad diaria (RF-IN-01, tarea 2.8). */
async function generateDayReport(page: Page, from: string, to: string): Promise<void> {
  await page.goto('/reports')
  await page.getByLabel('Desde').fill(from)
  await page.getByLabel('Hasta').fill(to)
  await page.getByLabel('Granularidad').selectOption('day')
  await page.getByRole('button', { name: 'Generar informe' }).click()
}

interface AbsenceCells {
  absenceDays: string
  unjustifiedDays: string
}

/**
 * `absence_days`/`unjustified_absence_days` de la fila del dia `day`
 * (columnas 11ª y 13ª de `PeriodReportTable.vue`: periodo, trabajadas,
 * contratadas, desviación, exceso, tramos, con actividad, sin actividad,
 * turno abierto, incidencia, **ausencia**, festivo, **absentismo no
 * justificado**).
 */
async function absenceCellsFor(rows: Locator, day: string): Promise<AbsenceCells> {
  const cells = rows.filter({ hasText: day }).locator('td')

  return {
    absenceDays: await cells.nth(10).innerText(),
    unjustifiedDays: await cells.nth(12).innerText(),
  }
}

async function registerSickLeave(page: Page, startsOn: string, endsOn: string): Promise<void> {
  await page.goto('/absences')
  await page.getByTestId('absences-register').click()

  const dialog = page.getByRole('dialog', { name: 'Registrar una ausencia' })

  await dialog.getByTestId('register-person-search').fill('Youssef')
  await dialog.locator('[data-test^="register-person-option-"]').first().click()
  await dialog.getByTestId('register-type').selectOption('sick_leave')
  await dialog.getByTestId('register-starts-on').fill(startsOn)
  await dialog.getByTestId('register-ends-on').fill(endsOn)
  await dialog.getByTestId('register-submit').click()

  await expect(dialog).toBeHidden()
}

test(
  'RRHH registra una baja de tres días y el informe del mismo periodo cambia solo esos tres días',
  { tag: ['@RF-GP-04'] },
  async ({ page }) => {
    const api = await stubManagementApi(page)
    await logIn(page)

    // ANTES de registrar nada: cinco días, ninguno cubierto por una ausencia.
    await generateDayReport(page, '2026-03-09', '2026-03-13')
    const rowsBefore = page.getByTestId('report-row')
    await expect(rowsBefore).toHaveCount(5)

    const dayBeforeInitially = await absenceCellsFor(rowsBefore, '2026-03-09')
    const dayAfterInitially = await absenceCellsFor(rowsBefore, '2026-03-13')

    expect(dayBeforeInitially).toEqual({ absenceDays: '0', unjustifiedDays: '1' })
    expect(dayAfterInitially).toEqual({ absenceDays: '0', unjustifiedDays: '1' })

    await registerSickLeave(page, '2026-03-10', '2026-03-12')

    const request = api.requests.find(
      (candidate) => candidate.method === 'POST' && candidate.path === '/api/v1/absences',
    )

    expect(request?.body).toMatchObject({
      employee_uuid: EMPLOYEE_UUID,
      type: 'sick_leave',
      starts_on: '2026-03-10',
      ends_on: '2026-03-12',
    })

    // DESPUÉS: el día anterior y el posterior no cambian…
    await generateDayReport(page, '2026-03-09', '2026-03-13')
    const rowsAfter = page.getByTestId('report-row')
    await expect(rowsAfter).toHaveCount(5)

    await expect.poll(() => absenceCellsFor(rowsAfter, '2026-03-09')).toEqual(dayBeforeInitially)
    await expect.poll(() => absenceCellsFor(rowsAfter, '2026-03-13')).toEqual(dayAfterInitially)

    // …y los tres días de la baja pasan a contar como ausencia justificada,
    // no como absentismo no justificado: `absence_days` suma 3 en total.
    const day10 = await absenceCellsFor(rowsAfter, '2026-03-10')
    const day11 = await absenceCellsFor(rowsAfter, '2026-03-11')
    const day12 = await absenceCellsFor(rowsAfter, '2026-03-12')

    expect(day10).toEqual({ absenceDays: '1', unjustifiedDays: '0' })
    expect(day11).toEqual({ absenceDays: '1', unjustifiedDays: '0' })
    expect(day12).toEqual({ absenceDays: '1', unjustifiedDays: '0' })
  },
)

test(
  'corregir una ausencia enseña qué cambia, desde qué valor y hacia cuál, antes de confirmar',
  { tag: ['@RF-GP-04'] },
  async ({ page }) => {
    const api = await stubManagementApi(page, { absences: [SICK_LEAVE] })
    await logIn(page)
    await page.goto('/absences')

    const row = page.getByRole('row', { name: /Youssef Amrani/ })
    await row.getByRole('button', { name: 'Corregir' }).click()

    const dialog = page.getByRole('dialog', { name: 'Corregir una ausencia' })
    await dialog.getByTestId('correct-ends-on').fill('2026-03-13')

    const preview = dialog.getByTestId('dialog-preview')
    await expect(preview).toBeVisible()
    await expect(preview).toContainText('12 mar 2026')
    await expect(preview).toContainText('13 mar 2026')

    // El motivo es obligatorio: sin el, el envío sigue bloqueado aunque ya
    // haya un cambio que enseñar.
    await expect(dialog.getByTestId('dialog-submit')).toBeDisabled()

    await dialog.getByTestId('correct-reason').fill('El parte de baja se prorrogó una semana.')
    await expect(dialog.getByTestId('dialog-submit')).toBeEnabled()
    await dialog.getByTestId('dialog-submit').click()

    await expect(dialog).toBeHidden()

    const request = api.requests.find(
      (candidate) => candidate.method === 'PATCH' && candidate.path.startsWith('/api/v1/absences/'),
    )

    expect(request?.body).toMatchObject({
      ends_on: '2026-03-13',
      reason: 'El parte de baja se prorrogó una semana.',
    })

    // La versión anterior sigue consultable: el historial no pierde nada.
    const correctedRow = page.getByRole('row', { name: /Youssef Amrani/ })
    await correctedRow.getByRole('button', { name: 'Historial' }).click()

    const historyDialog = page.getByRole('dialog', { name: 'Historial de la ausencia' })
    await expect(historyDialog.getByText('Ausencia registrada')).toBeVisible()
    await expect(historyDialog).toContainText('12 mar 2026')
    await expect(historyDialog).toContainText('13 mar 2026')
  },
)

test(
  'anular una ausencia pide motivo y resume lo que se anula antes de confirmar',
  { tag: ['@RF-GP-04'] },
  async ({ page }) => {
    const api = await stubManagementApi(page, { absences: [SICK_LEAVE] })
    await logIn(page)
    await page.goto('/absences')

    const row = page.getByRole('row', { name: /Youssef Amrani/ })
    await row.getByRole('button', { name: 'Anular' }).click()

    const dialog = page.getByRole('dialog', { name: 'Anular una ausencia' })
    await expect(dialog).toContainText('Youssef Amrani')
    await expect(dialog).toContainText('Baja médica')

    const confirmButton = dialog.getByRole('button', { name: 'Anular' })
    await expect(confirmButton).toBeDisabled()

    await dialog.getByTestId('void-reason').fill('Se registró a la persona equivocada.')
    await expect(confirmButton).toBeEnabled()
    await confirmButton.click()

    await expect(dialog).toBeHidden()

    const request = api.requests.find(
      (candidate) => candidate.method === 'POST' && candidate.path.endsWith('/void'),
    )

    expect(request?.body).toMatchObject({ reason: 'Se registró a la persona equivocada.' })

    // Anulada, ya no sale en el listado por omisión (solo activas).
    await expect(page.getByRole('row', { name: /Youssef Amrani/ })).toHaveCount(0)
  },
)

test(
  'el responsable de departamento ve las ausencias de su gente, sin nota ni acciones de escritura',
  { tag: ['@RF-GP-04', '@RF-ID-03'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'manager', absences: [SICK_LEAVE] })
    await logInAsManager(page)
    await page.goto('/absences')

    const row = page.getByRole('row', { name: /Youssef Amrani/ })
    await expect(row).toBeVisible()
    // El tipo sí lo ve (quien organiza el turno necesita saberlo)…
    await expect(row).toContainText('Baja médica')
    // …pero la nota, dato de salud, no: el servidor ni siquiera la manda.
    await expect(row).not.toContainText('Gripe estacional')

    await expect(row.getByRole('button', { name: 'Corregir' })).toHaveCount(0)
    await expect(row.getByRole('button', { name: 'Anular' })).toHaveCount(0)
    await expect(page.getByTestId('absences-register')).toHaveCount(0)
    await expect(page.getByTestId('absences-import')).toHaveCount(0)

    // El historial sigue siendo lectura, y la lectura la lleva.
    await expect(row.getByRole('button', { name: 'Historial' })).toBeVisible()
  },
)
