// Accesibilidad automatizada del panel con axe-core (doc 02 §9.2 y §9.4;
// WCAG 2.2 AA, doc 01 §6.5). Mismo criterio que en el quiosco: CERO
// violaciones criticas o graves en cada pantalla del recorrido de la Fase 1.
// Las de impacto menor se listan en la salida para que se vean, pero no
// bloquean.
//
// Hasta ahora el panel solo tenia una comprobacion estructural en Vitest; esto
// es el analisis de verdad, sobre el DOM real del build.

import AxeBuilder from '@axe-core/playwright'
import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { EMPLOYEE_UUID, logIn, stubManagementApi } from './support/admin'

/** Etiquetas WCAG que se comprueban: A y AA hasta la 2.2 (doc 01 §6.5). */
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

async function expectNoBlockingViolations(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze()

  const blocking = results.violations.filter(
    (violation) => violation.impact === 'critical' || violation.impact === 'serious',
  )

  expect(
    blocking,
    blocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
  ).toEqual([])
}

test.beforeEach(async ({ page }) => {
  await stubManagementApi(page)
})

test(
  'el acceso no tiene violaciones criticas ni graves',
  { tag: ['@RF-ID-01'] },
  async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByRole('heading', { name: 'Acceso al panel de gestión' })).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test('la plantilla tampoco', { tag: ['@RF-GP-01'] }, async ({ page }) => {
  await logIn(page)
  await expect(page.getByRole('table')).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('la ficha de una persona tampoco', { tag: ['@RF-GP-01'] }, async ({ page }) => {
  await logIn(page)
  await page.goto(`/employees/${EMPLOYEE_UUID}`)
  await expect(page.getByRole('heading', { level: 1, name: 'Youssef Amrani' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('el registro horario tampoco', { tag: ['@RF-PA-03'] }, async ({ page }) => {
  await logIn(page)
  await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)
  await expect(page.getByTestId('workday')).toHaveCount(1)

  await expectNoBlockingViolations(page)
})
