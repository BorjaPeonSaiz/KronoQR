// Informe de horas por periodo (RF-IN-01, RF-IN-02, RF-IN-03).
//
// El backend no participa: lo que se prueba aqui es el recorrido por el panel
// con la API simulada en `support/admin.ts`. La autorizacion negativa por rol
// —`403` a quien no tiene `reports:*`— y el calculo de las horas se prueban en
// el backend, que es donde viven (regla dura 18, regla dura 7).
import { expect, test } from '@playwright/test'
import { logIn, PERIOD_REPORT, stubManagementApi } from './support/admin'

test(
  'RRHH pide el informe del mes y ve las horas, la comparativa y los criterios',
  { tag: ['@RF-IN-01'] },
  async ({ page }) => {
    const api = await stubManagementApi(page)
    await logIn(page)
    await page.goto('/reports')

    await expect(
      page.getByRole('heading', { level: 1, name: 'Informe de horas por periodo' }),
    ).toBeVisible()

    // NADA SE PIDE HASTA QUE SE ELIGE UN PERIODO: es una consulta cara y quien
    // entra todavia no ha elegido nada.
    expect(api.requests.some((request) => request.path === '/api/v1/reports/period')).toBe(false)

    await page.getByLabel('Desde').fill('2026-03-01')
    await page.getByLabel('Hasta').fill('2026-03-31')
    await page.getByLabel('Granularidad').selectOption('month')
    await page.getByLabel('Agrupar por').selectOption('employee')
    await page.getByRole('button', { name: 'Generar informe' }).click()

    // El periodo y la agrupacion viajan al servidor tal cual se han elegido: el
    // panel no filtra ni agrupa nada por su cuenta.
    await expect
      .poll(() =>
        api.requests.some(
          (request) =>
            request.path === '/api/v1/reports/period' &&
            request.query.includes('from=2026-03-01') &&
            request.query.includes('to=2026-03-31') &&
            request.query.includes('granularity=month') &&
            request.query.includes('group_by=employee'),
        ),
      )
      .toBe(true)

    const row = page.getByTestId('report-row')

    await expect(row).toBeVisible()
    await expect(row).toContainText('Youssef El Amrani')

    // LAS HORAS SE LEEN EN `HH:MM` Y NUNCA EN DECIMAL. Son exactamente las que
    // devolvio el servidor: el panel no las formatea ni las suma.
    await expect(row).toContainText('162:00')
    await expect(row).toContainText('134:17')
    await expect(row).toContainText('27:43')
    await expect(page.locator('body')).not.toContainText('162,0')

    // Y los criterios de inclusion, visibles con el informe: sin ellos la tabla
    // es un conjunto de numeros que cada persona interpreta a su manera.
    const criteria = page.getByTestId('report-criteria')

    await expect(criteria).toBeVisible()
    await expect(criteria).toContainText('no se parte a medianoche')
    await expect(criteria.getByRole('listitem')).toHaveCount(PERIOD_REPORT.meta.criteria.length)
  },
)

test(
  'el filtro de departamento y el de turnos abiertos viajan al servidor',
  { tag: ['@RF-IN-02'] },
  async ({ page }) => {
    const api = await stubManagementApi(page)
    await logIn(page)
    await page.goto('/reports')

    await page.getByLabel('Desde').fill('2026-03-01')
    await page.getByLabel('Hasta').fill('2026-03-31')
    await page.getByLabel('Agrupar por').selectOption('department')
    await page.getByLabel('Departamento').selectOption('3')
    await page.getByLabel('Contar los días con turno abierto').check()
    await page.getByRole('button', { name: 'Generar informe' }).click()

    await expect
      .poll(() =>
        api.requests.some(
          (request) =>
            request.path === '/api/v1/reports/period' &&
            request.query.includes('group_by=department') &&
            request.query.includes('department_id=3') &&
            request.query.includes('include_open_shifts=true'),
        ),
      )
      .toBe(true)
  },
)
