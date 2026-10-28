// Tablas grandes del panel (V5-PA-1, RC1 · RF-IN-01, RF-IN-03, RF-PA-06).
//
// El servidor admite hasta 20 000 filas en el informe por periodo y la vista de
// cumplimiento no pagina. Con la API simulada (`page.route`) se sirven esos
// volumenes y se comprueba, en Chromium y con la CPU a ×4:
//
//   - que el DOM no crece con el volumen (solo la ventana visible) y que la
//     region se recorre con el teclado y avanza sin pintar todas las filas;
//   - que axe no encuentra violaciones criticas ni graves a 1024 y a 768 px.
//
// LO QUE NO SE MIDE AQUI ES EL TIEMPO. Que la pantalla quede interactiva en
// menos de 1,5 s (RNF-P-04) y que ninguna tarea larga pase de su tope lo afirma
// `perf-large-tables.spec.ts`, en la suite nocturna de rendimiento
// (`make e2e-performance`, D4 de la 2.3.0): aqui un runner cargado ponia la
// rama en rojo sin que el panel hubiera empeorado.
//
// La impresion y el «buscar en la pagina» NO funcionan sobre las filas que no
// estan pintadas: es el coste asumido de virtualizar, y la guia de RRHH remite a
// la exportacion (RF-IN-04).
import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { logIn, stubManagementApi } from './support/admin'
import { expectNoBlockingViolations } from './support/axe'
import {
  BIG_REPORT,
  FINDINGS,
  openReportForm,
  PAINTED_CEILING,
  REPORT_ROWS,
  serveBigData,
  SITE_REPORT,
  throttleCpu,
} from './support/largeTables'

test.beforeEach(async ({ page }) => {
  await throttleCpu(page)
})

async function requestReport(page: Page): Promise<void> {
  await openReportForm(page)
  await page.getByRole('button', { name: 'Generar informe' }).click()
  await expect(page.getByTestId('report-row').first()).toBeVisible()
}

test(
  'el informe de 20 000 filas queda interactivo sin meter las filas en el DOM',
  { tag: ['@RF-IN-01', '@RF-IN-03'] },
  async ({ page }) => {
    await serveBigData(page)
    await logIn(page)

    await requestReport(page)

    const painted = await page.getByTestId('report-row').count()

    expect(painted).toBeGreaterThan(0)
    expect(painted).toBeLessThan(PAINTED_CEILING)

    // Interactivo de verdad: la region se alcanza con el teclado y avanza por
    // las filas sin pintarlas todas.
    const region = page.getByRole('region', { name: /Horas por periodo/ })

    await expect(region).toHaveAttribute('tabindex', '0')
    await expect(region.getByRole('table')).toHaveAttribute(
      'aria-rowcount',
      String(REPORT_ROWS + 1),
    )

    await region.focus()
    await region.evaluate((element) => {
      element.scrollTop = element.scrollHeight / 2
    })
    await expect
      .poll(async () => {
        const index = await page.getByTestId('report-row').first().getAttribute('aria-rowindex')

        return Number(index)
      })
      .toBeGreaterThan(1_000)
    expect(await page.getByTestId('report-row').count()).toBeLessThan(PAINTED_CEILING)
  },
)

for (const width of [1024, 768] as const) {
  test(
    `el informe de 20 000 filas no tiene violaciones criticas ni graves a ${width} px`,
    { tag: ['@RF-IN-01', '@RF-IN-03'] },
    async ({ page }) => {
      await page.setViewportSize({ width, height: 768 })
      await serveBigData(page)
      await logIn(page)
      await requestReport(page)

      await expectNoBlockingViolations(page)
    },
  )
}

for (const [label, report] of [
  ['con columna de sujeto', BIG_REPORT],
  ['sin columna de sujeto', SITE_REPORT],
] as const) {
  for (const width of [1024, 1920] as const) {
    test(
      `las filas del informe no se salen de la cabecera ${label} a ${width} px`,
      { tag: ['@RF-IN-01', '@RF-IN-03'] },
      async ({ page }) => {
        await page.setViewportSize({ width, height: 768 })
        await serveBigData(page, report)
        await logIn(page)
        await requestReport(page)

        const region = page.getByTestId('period-report-table')
        const widths = await region.evaluate((element) => {
          const header = element.querySelector<HTMLElement>('[role="row"][aria-rowindex="1"]')
          const rows = Array.from(element.querySelectorAll<HTMLElement>('[data-test="report-row"]'))

          if (header === null || rows.length === 0) {
            throw new Error('la tabla no tiene cabecera o filas')
          }

          const headerRight = header.getBoundingClientRect().right

          // Lo que sobra de las columnas ya resueltas (pistas + huecos + relleno) sobre la caja de
          // la fila: 0 si la rejilla cabe, y positivo si es mas ancha que la tabla.
          const spill = (row: HTMLElement): number => {
            const style = getComputedStyle(row)
            const tracks = style.gridTemplateColumns.split(' ').map(Number.parseFloat)
            const sum = tracks.reduce((total, track) => total + track, 0)
            const gaps = (tracks.length - 1) * Number.parseFloat(style.columnGap)
            const padding =
              Number.parseFloat(style.paddingLeft) + Number.parseFloat(style.paddingRight)

            return sum + gaps + padding - row.clientWidth
          }

          return {
            headerSpill: spill(header),
            rowSpills: rows.map(spill),
            overshoot: Math.max(
              ...rows.map((row) => row.getBoundingClientRect().right - headerRight),
            ),
          }
        })

        // La rejilla cabe en la caja de la cabecera y en la de cada fila, y ninguna
        // fila termina mas a la derecha que la cabecera fija.
        expect(widths.headerSpill).toBeLessThanOrEqual(1)
        expect(Math.max(...widths.rowSpills)).toBeLessThanOrEqual(1)
        expect(widths.overshoot).toBeLessThanOrEqual(0.5)
      },
    )
  }
}

for (const locale of ['es', 'en'] as const) {
  test(
    `ningun texto de cabecera del informe desborda su celda a 1024 px en ${locale}`,
    { tag: ['@RF-IN-01', '@RF-IN-03'] },
    async ({ page }) => {
      await page.setViewportSize({ width: 1024, height: 768 })
      await serveBigData(page, BIG_REPORT, locale)
      await logIn(page)
      await page.goto('/reports')
      await page.getByLabel(/Desde|From/).fill('2026-03-01')
      await page.getByLabel(/Hasta|To/).fill('2026-03-31')
      await page.getByRole('button', { name: /Generar informe|Generate report/ }).click()
      await expect(page.getByTestId('report-row').first()).toBeVisible()
      await expect(page.locator('html')).toHaveAttribute('lang', locale)

      const region = page.getByTestId('period-report-table')

      // Sin partir palabras: se quitan el guionado y el corte de palabra del
      // navegador, que dependen de su diccionario, y se exige que cada palabra
      // quepa entera en su columna (las cabeceras solo parten por espacios).
      await region.evaluate((element) => {
        for (const header of element.querySelectorAll<HTMLElement>('[role="columnheader"]')) {
          header.style.hyphens = 'manual'
          header.style.overflowWrap = 'normal'
        }
      })

      const overflowing = await region.evaluate((element) =>
        Array.from(element.querySelectorAll<HTMLElement>('[role="columnheader"]'))
          .filter((header) => header.scrollWidth > header.clientWidth)
          .map(
            (header) =>
              `${header.textContent?.trim() ?? ''}: ${header.scrollWidth} > ${header.clientWidth}`,
          ),
      )

      expect(overflowing).toEqual([])
    },
  )
}

test(
  'los 3 000 hallazgos de cumplimiento quedan interactivos sin meter las filas en el DOM',
  { tag: ['@RF-PA-06'] },
  async ({ page }) => {
    await serveBigData(page)
    await logIn(page)

    await page.goto('/compliance')
    await expect(page.getByTestId('compliance-finding-row').first()).toBeVisible()

    const painted = await page.getByTestId('compliance-finding-row').count()

    expect(painted).toBeGreaterThan(0)
    expect(painted).toBeLessThan(PAINTED_CEILING)

    const region = page.getByTestId('compliance-table')

    await expect(region).toHaveAttribute('tabindex', '0')
    await expect(region.getByRole('table')).toHaveAttribute('aria-rowcount', String(FINDINGS + 1))

    await region.evaluate((element) => {
      element.scrollTop = element.scrollHeight / 2
    })
    await expect
      .poll(async () => {
        const index = await page
          .getByTestId('compliance-finding-row')
          .first()
          .getAttribute('aria-rowindex')

        return Number(index)
      })
      .toBeGreaterThan(500)
    expect(await page.getByTestId('compliance-finding-row').count()).toBeLessThan(PAINTED_CEILING)
  },
)

for (const width of [1024, 768] as const) {
  test(
    `los 3 000 hallazgos de cumplimiento no tienen violaciones criticas ni graves a ${width} px`,
    { tag: ['@RF-PA-06'] },
    async ({ page }) => {
      await page.setViewportSize({ width, height: 768 })
      await serveBigData(page)
      await logIn(page)
      await page.goto('/compliance')
      await expect(page.getByTestId('compliance-finding-row').first()).toBeVisible()

      await expectNoBlockingViolations(page)
    },
  )
}

test(
  'una fila semanal con turno abierto cabe en su altura fija a 768 px',
  { tag: ['@RF-PA-06'] },
  async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 768 })
    await stubManagementApi(page)
    await logIn(page)
    await page.goto('/compliance')

    const row = page
      .getByTestId('compliance-finding-row')
      .filter({ has: page.getByTestId('finding-open-shift') })

    await expect(row).toBeVisible()
    await expect(row).toContainText('9 mar 2026 – 15 mar 2026')

    // Nada de lo que hay dentro de la fila (periodo, etiqueta, enlaces) sobresale
    // de su caja, ni por abajo ni por la derecha de su celda.
    const overflow = await row.evaluate((element) => {
      const box = element.getBoundingClientRect()
      const escapes: string[] = []

      for (const child of Array.from(element.querySelectorAll<HTMLElement>('*'))) {
        const rect = child.getBoundingClientRect()

        if (rect.bottom > box.bottom + 0.5 || rect.top < box.top - 0.5) {
          escapes.push(`${child.tagName} vertical: ${child.textContent?.trim() ?? ''}`)
        }
      }

      for (const cell of Array.from(element.querySelectorAll<HTMLElement>('[role="cell"]'))) {
        if (cell.scrollWidth > cell.clientWidth + 0.5) {
          escapes.push(`celda horizontal: ${cell.textContent?.trim() ?? ''}`)
        }
      }

      return escapes
    })

    expect(overflow).toEqual([])
  },
)
