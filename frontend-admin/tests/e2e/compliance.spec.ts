// Vista de cumplimiento (RF-PA-06, tarea 3.4): descanso insuficiente entre
// jornadas, jornada diaria excesiva, pausa en tramo continuado (suspendida) y
// exceso semanal informativo, con el umbral del perfil aplicado a cada una.
//
// El backend no participa (regla dura 18): lo que se prueba aqui es el
// recorrido por el panel con la API simulada en `support/admin.ts`
// (`COMPLIANCE_SUMMARY`). La autorizacion negativa por rol y el calculo de los
// hallazgos se prueban en el backend, no aqui.
import { expect, test } from '@playwright/test'
import { logIn, logInAsManager, stubManagementApi } from './support/admin'

test(
  'la vista enseña el umbral aplicado y el origen del perfil, en cada tarjeta',
  { tag: ['@RF-PA-06'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)

    await page.getByRole('link', { name: 'Cumplimiento', exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Cumplimiento' })).toBeVisible()

    // Carga sola, sin elegir nada: los filtros de fecha quedan rellenos con lo
    // que el servidor resolvio (el rango de `COMPLIANCE_SUMMARY.meta`).
    await expect(page.getByLabel('Desde')).toHaveValue('2026-03-04')
    await expect(page.getByLabel('Hasta')).toHaveValue('2026-03-31')

    // La zona horaria del centro, a la vista junto a la cabecera (correccion
    // de UI/UX, segunda vuelta): no solo en un `<caption>` invisible.
    await expect(page.getByTestId('generated-at')).toContainText('Europe/Madrid')

    const cards = page.getByTestId('rule-card')
    await expect(cards).toHaveCount(4)

    const restCard = cards.filter({ hasText: 'Descanso mínimo entre jornadas' })
    await expect(restCard).toContainText('12 h 00 min según el perfil ES-hosteleria')
    await expect(restCard.getByTestId('rule-count')).toHaveText('1')

    // RN-17 es informativa, pero su umbral se enseña igual (regla dura 14).
    const weeklyCard = cards.filter({ hasText: 'Jornada semanal ordinaria' })
    await expect(weeklyCard).toContainText('40 h 00 min según el perfil ES-hosteleria')
    await expect(weeklyCard.getByTestId('rule-count')).toHaveText('1')

    // El hallazgo semanal trae un turno todavia abierto: la insignia se ve
    // con texto, no solo con color (WCAG 1.4.1).
    await expect(page.getByTestId('finding-open-shift')).toBeVisible()
    await expect(page.getByTestId('finding-open-shift')).toContainText('Turno abierto')
  },
)

test(
  'la regla suspendida se marca «No se evalúa», con el motivo',
  { tag: ['@RF-PA-06'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)
    await page.goto('/compliance')

    const breakCard = page
      .getByTestId('rule-card')
      .filter({ hasText: 'Tramo continuo máximo sin pausa' })

    await expect(breakCard.getByTestId('rule-suspended')).toHaveText('No se evalúa')
    await expect(breakCard.getByTestId('rule-suspended-reason')).toContainText(
      'mientras el fichaje de pausa esté desactivado',
    )
    // Sin hallazgos propios: el recuento de la tarjeta es cero y no hay tabla
    // para esta regla.
    await expect(breakCard.getByTestId('rule-count')).toHaveText('0')
  },
)

test(
  'el enlace a la incidencia lleva a la bandeja acotada a esa persona',
  { tag: ['@RF-PA-06'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)
    await page.goto('/compliance')

    await expect(page.getByTestId('compliance-finding-row').first()).toBeVisible()

    await page.getByTestId('finding-incident-link').first().click()
    await expect(page).toHaveURL(/\/incidents\?employee=/)
    await expect(page.getByTestId('employee-filter-banner')).toContainText('Youssef Amrani')
  },
)

test(
  'un periodo sin hallazgos enseña el estado vacio con los criterios',
  { tag: ['@RF-PA-06'] },
  async ({ page }) => {
    await stubManagementApi(page, {
      complianceSummary: {
        data: [],
        meta: {
          generated_at: '2026-03-31T09:12:03.418000Z',
          time_zone: 'Europe/Madrid',
          from: '2026-03-04',
          to: '2026-03-31',
          profile: { id: 1, name: 'ES-hosteleria', jurisdiction: 'ES' },
          week_starts_on: 1,
          rules: [
            {
              rule: 'insufficient_rest',
              requirement: 'RN-10',
              threshold_minutes: 720,
              evaluated: true,
              suspension_reason: null,
            },
            {
              rule: 'daily_excess',
              requirement: 'RN-11',
              threshold_minutes: 540,
              evaluated: true,
              suspension_reason: null,
            },
            {
              rule: 'missing_break',
              requirement: 'RN-12',
              threshold_minutes: 360,
              evaluated: false,
              suspension_reason: 'break_clocking_disabled',
            },
            {
              rule: 'weekly_excess',
              requirement: 'RN-17',
              threshold_minutes: 2400,
              evaluated: true,
              suspension_reason: null,
            },
          ],
          totals: {
            by_rule: { insufficient_rest: 0, daily_excess: 0, missing_break: 0, weekly_excess: 0 },
            employees_affected: 0,
            employees_evaluated: 48,
          },
          scope: 'all',
          criteria: [
            'Descanso entre jornadas: se compara la última salida con la primera entrada.',
          ],
        },
      },
    })
    await logIn(page)
    await page.goto('/compliance')

    await expect(page.getByText('Sin alertas en el periodo')).toBeVisible()
    await expect(
      page.getByText('Descanso entre jornadas: se compara la última salida'),
    ).toBeVisible()
  },
)

test(
  'un responsable de departamento alcanza la vista con attendance:read',
  { tag: ['@RF-PA-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'manager' })
    await logInAsManager(page)

    await page.getByRole('link', { name: 'Cumplimiento', exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Cumplimiento' })).toBeVisible()
  },
)
