// Bandeja de incidencias y resolucion (RF-PA-05, RF-PR-01).
//
// El backend no participa (regla dura 18): lo que se prueba aqui es el
// recorrido por el panel con la API simulada en `support/admin.ts`. La
// autorizacion negativa por rol -403 al salirse del alcance del departamento-
// se prueba en el backend, no aqui.
import { expect, test } from '@playwright/test'
import {
  EMPLOYEE_UUID,
  INCIDENT_CLOSED_BY_OTHER,
  logIn,
  logInAsManager,
  OPEN_INCIDENT,
  stubManagementApi,
  WORKDAYS_WITH_INCIDENT,
} from './support/admin'

test(
  'la bandeja lista lo pendiente y los filtros van al servidor',
  { tag: ['@RF-PA-05'] },
  async ({ page }) => {
    const api = await stubManagementApi(page)
    await logIn(page)
    await page.goto('/incidents')

    await expect(
      page.getByRole('heading', { level: 1, name: 'Bandeja de incidencias' }),
    ).toBeVisible()

    const row = page
      .getByTestId('incident-row')
      .filter({ hasText: OPEN_INCIDENT.employee.full_name })
    await expect(row).toBeVisible()
    await expect(row).toContainText('Alta')
    await expect(row).toContainText('Descanso insuficiente')
    await expect(row).toContainText(OPEN_INCIDENT.employee.employee_code)
    await expect(row).toContainText('Recepción')

    await page.getByLabel('Tipo').selectOption('insufficient_rest')
    await expect
      .poll(() =>
        api.requests.some(
          (request) =>
            request.path === '/api/v1/incidents' &&
            request.query.includes('type=insufficient_rest'),
        ),
      )
      .toBe(true)

    await page.getByLabel('Severidad').selectOption('high')
    await expect
      .poll(() =>
        api.requests.some(
          (request) =>
            request.path === '/api/v1/incidents' && request.query.includes('severity=high'),
        ),
      )
      .toBe(true)

    await page.getByLabel('Departamento').selectOption('3')
    await expect
      .poll(() =>
        api.requests.some(
          (request) =>
            request.path === '/api/v1/incidents' && request.query.includes('department_id=3'),
        ),
      )
      .toBe(true)

    await page.getByLabel('Estado').selectOption('resolved')
    await expect
      .poll(() =>
        api.requests.some(
          (request) =>
            request.path === '/api/v1/incidents' && request.query.includes('status=resolved'),
        ),
      )
      .toBe(true)
    await expect(page.getByText('Sin incidencias resueltas')).toBeVisible()
  },
)

test(
  'resolver una incidencia la retira de la bandeja y deja nota',
  { tag: ['@RF-PA-05'] },
  async ({ page }) => {
    const api = await stubManagementApi(page)
    await logIn(page)
    await page.goto('/incidents')

    await page.getByTestId('resolve-button').click()
    await expect(page.getByRole('dialog', { name: 'Cerrar incidencia' })).toBeVisible()

    await page.getByLabel('Se ha corregido').check()
    await page.getByLabel('Nota (obligatoria)').fill('Corregida con el parte de turno del dia 14.')
    await page.getByRole('button', { name: 'Confirmar' }).click()

    // La fila desaparece y la bandeja queda vacia (era la unica).
    await expect(page.getByTestId('incident-row')).toHaveCount(0)
    await expect(page.getByText('Sin incidencias pendientes')).toBeVisible()

    const resolveRequest = api.requests.find((request) => request.path.endsWith('/resolve'))
    expect(resolveRequest?.body).toMatchObject({
      outcome: 'resolved',
      note: 'Corregida con el parte de turno del dia 14.',
    })
  },
)

test(
  'un 409 al resolver dice quien se adelanto, y no reintenta',
  { tag: ['@RF-PA-05'] },
  async ({ page }) => {
    const api = await stubManagementApi(page, { resolveOutcome: 'conflict' })
    await logIn(page)
    await page.goto('/incidents')

    await page.getByTestId('resolve-button').click()
    await page.getByLabel('Nota (obligatoria)').fill('Intento de cierre que llega tarde.')
    await page.getByRole('button', { name: 'Confirmar' }).click()

    await expect(page.getByText('Ya se ha resuelto')).toBeVisible()
    await expect(
      page.getByText(`${INCIDENT_CLOSED_BY_OTHER.resolved_by?.name} la marcó como`),
    ).toBeVisible()
    await expect(page.getByTestId('conflict-note')).toContainText(
      INCIDENT_CLOSED_BY_OTHER.resolution_note ?? '',
    )

    // Un unico intento de resolver: no hay boton para reenviar el mismo cierre.
    const resolveAttempts = api.requests.filter((request) => request.path.endsWith('/resolve'))
    expect(resolveAttempts).toHaveLength(1)

    await page.getByRole('button', { name: 'Entendido' }).click()
    await expect(page.getByRole('dialog')).toHaveCount(0)
  },
)

test('el responsable de departamento ve la seccion', { tag: ['@RF-PA-05'] }, async ({ page }) => {
  await stubManagementApi(page, { role: 'manager' })
  await logInAsManager(page)

  // Sin plantilla ni credenciales (RF-ID-03): lo que no puede usar no se enseña.
  await expect(page.getByRole('link', { name: 'Plantilla' })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Credenciales' })).toHaveCount(0)

  await page.getByRole('link', { name: 'Incidencias' }).click()
  await expect(
    page.getByRole('heading', { level: 1, name: 'Bandeja de incidencias' }),
  ).toBeVisible()
  await expect(page.getByTestId('incident-row')).toHaveCount(1)
})

test(
  'la marca de incidencia aparece en el detalle de jornada y enlaza a la bandeja',
  { tag: ['@RF-PA-05'] },
  async ({ page }) => {
    await stubManagementApi(page, { workdays: WORKDAYS_WITH_INCIDENT })
    await logIn(page)
    await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)

    const badge = page.getByTestId('workday-incident-badge')
    await expect(badge).toBeVisible()
    await expect(badge).toContainText('Descanso insuficiente')
    await expect(badge).toContainText('Pendiente')

    await page.getByRole('link', { name: 'Ver en la bandeja de incidencias' }).click()
    await expect(page).toHaveURL(/\/incidents\?employee=/)
    await expect(page.getByTestId('employee-filter-banner')).toContainText('Youssef Amrani')
  },
)
