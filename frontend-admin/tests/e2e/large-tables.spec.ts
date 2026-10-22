// Tablas grandes del panel (V5-PA-1, RC1 · RF-IN-01, RF-IN-03, RF-PA-06, RNF-P-04).
//
// El servidor admite hasta 20 000 filas en el informe por periodo y la vista de
// cumplimiento no pagina. Con la API simulada (`page.route`) se sirven esos
// volumenes y se comprueba, en Chromium, tres cosas:
//
//   - que el DOM no crece con el volumen (solo la ventana visible);
//   - que la pantalla queda interactiva en menos de 1,5 s (RNF-P-04 como
//     referencia) y que ninguna tarea larga (`longtask`, >= 50 ms por
//     definicion del navegador) pasa de 200 ms, ni al generar ni al desplazar;
//   - que axe no encuentra violaciones criticas ni graves a 1024 y a 768 px.
//
// La impresion y el «buscar en la pagina» NO funcionan sobre las filas que no
// estan pintadas: es el coste asumido de virtualizar, y la guia de RRHH remite a
// la exportacion (RF-IN-04).
import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import type { ComplianceFinding, PeriodReport, PeriodReportRow } from '@/shared/api/types'
import {
  BILINGUAL_BRANDING,
  COMPLIANCE_SUMMARY,
  EMPLOYEE_UUID,
  logIn,
  PERIOD_REPORT,
  stubManagementApi,
} from './support/admin'
import { expectNoBlockingViolations } from './support/axe'

const REPORT_ROWS = 20_000
const FINDINGS = 3_000
const INTERACTIVE_BUDGET_MS = 1_500
const LONG_TASK_BUDGET_MS = 200
/**
 * Solo al GENERAR el informe de 20 000 filas, con CPU ×4: leer y `JSON.parse` de un
 * cuerpo de ~12 MB son ~100 ms medidos con ×4 en una sola tarea que el navegador
 * no puede partir. El resto (desplazar, la tabla de cumplimiento) sigue en 200 ms.
 * Bajarlo exige paginar o transmitir el informe en el servidor, no tocar la tabla.
 */
const REPORT_LOAD_LONG_TASK_BUDGET_MS = 300
/** Cuanto cabe en pantalla mas el sobrepintado: el techo de filas en el DOM con cualquier volumen. */
const PAINTED_CEILING = 80
/**
 * Ralentizacion de CPU con la que se mide (CDP `Emulation.setCPUThrottlingRate`):
 * un portatil de recepcion es varias veces mas lento que la maquina de desarrollo
 * o del runner. Con ×1 los tiempos de abajo se cumplian de sobra y no decian nada
 * del equipo real (V5-PA-1, tanda 5 «se pasó por alto»). Los topes de abajo
 * valen CON esta ralentizacion.
 */
const CPU_THROTTLE_RATE = 4
declare global {
  interface Window {
    __longTasks: number[]
    __longTaskObserver: PerformanceObserver
  }
}

function uuidFor(index: number): string {
  return `0199f0c2-1f4a-7c3e-9b21-${String(index).padStart(12, '0')}`
}

function reportRow(index: number): PeriodReportRow {
  const template = PERIOD_REPORT.data[0]

  if (template === undefined) {
    throw new Error('PERIOD_REPORT sin filas')
  }

  return {
    ...template,
    subject: {
      ...template.subject,
      employee_uuid: uuidFor(index),
      employee_code: `E${String(index).padStart(8, '0')}`,
      full_name: `Persona ${index} Apellido`,
      label: `Persona ${index} Apellido`,
    },
  }
}

function finding(index: number): ComplianceFinding {
  return {
    rule: 'daily_excess',
    requirement: 'RN-11',
    employee: {
      uuid: index === 1 ? EMPLOYEE_UUID : uuidFor(index),
      employee_code: `E${String(index).padStart(8, '0')}`,
      full_name: `Persona ${index} Apellido`,
      department: { id: 3, name: 'Recepción' },
    },
    work_date: '2026-03-14',
    week: null,
    measured_minutes: 600,
    threshold_minutes: 540,
    difference_minutes: 60,
    shift_entry_uuid: null,
    has_open_shift: index % 7 === 0,
    incident: null,
  }
}

const BIG_REPORT: PeriodReport = {
  ...PERIOD_REPORT,
  data: Array.from({ length: REPORT_ROWS }, (_, index) => reportRow(index + 1)),
  meta: { ...PERIOD_REPORT.meta, row_count: REPORT_ROWS },
}

/** Informe agrupado por centro: sin columna de sujeto, la rejilla mas corta. */
const SITE_REPORT: PeriodReport = {
  ...PERIOD_REPORT,
  group_by: 'site',
  data: Array.from({ length: 200 }, (_, index) => {
    const row = reportRow(index + 1)

    return {
      ...row,
      subject: {
        kind: 'site',
        employee_uuid: null,
        employee_code: null,
        full_name: null,
        department_id: null,
        label: null,
      },
    }
  }),
  meta: { ...PERIOD_REPORT.meta, row_count: 200 },
}

const BIG_SUMMARY = {
  ...COMPLIANCE_SUMMARY,
  data: Array.from({ length: FINDINGS }, (_, index) => finding(index + 1)),
  meta: {
    ...COMPLIANCE_SUMMARY.meta,
    totals: {
      ...COMPLIANCE_SUMMARY.meta.totals,
      by_rule: { ...COMPLIANCE_SUMMARY.meta.totals.by_rule, daily_excess: FINDINGS },
    },
  },
}

/** Sirve el volumen grande DESPUES del doble general: la ultima ruta registrada gana. */
async function serveBigData(
  page: Page,
  report: PeriodReport = BIG_REPORT,
  locale?: 'es' | 'en',
): Promise<void> {
  // Las dos variantes de idioma exigen la instalacion bilingue.
  await stubManagementApi(page, {
    branding: BILINGUAL_BRANDING,
    ...(locale === undefined ? {} : { locale }),
  })
  await page.route(
    (url) => url.pathname === '/api/v1/reports/period',
    (route) => route.fulfill({ status: 200, json: report }),
  )
  await page.route(
    (url) => url.pathname === '/api/v1/compliance/summary',
    (route) => route.fulfill({ status: 200, json: BIG_SUMMARY }),
  )
}

test.beforeEach(async ({ page }) => {
  const session = await page.context().newCDPSession(page)

  await session.send('Emulation.setCPUThrottlingRate', { rate: CPU_THROTTLE_RATE })
})

/** Registra las tareas largas desde el primer momento de cada documento. */
async function observeLongTasks(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.__longTasks = []
    window.__longTaskObserver = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        window.__longTasks.push(entry.duration)
      }
    })
    window.__longTaskObserver.observe({ type: 'longtask', buffered: true })
  })
}

async function resetLongTasks(page: Page): Promise<void> {
  await page.evaluate(() => {
    window.__longTaskObserver.takeRecords()
    window.__longTasks.length = 0
  })
}

async function longestTask(page: Page): Promise<number> {
  // `takeRecords` entrega ya las entradas pendientes: sin esperas ni temporizadores.
  return page.evaluate(() => {
    for (const entry of window.__longTaskObserver.takeRecords()) {
      window.__longTasks.push(entry.duration)
    }

    return Math.max(0, ...window.__longTasks)
  })
}

async function requestReport(page: Page): Promise<number> {
  await page.goto('/reports')
  await page.getByLabel('Desde').fill('2026-03-01')
  await page.getByLabel('Hasta').fill('2026-03-31')
  await resetLongTasks(page)

  const startedAt = Date.now()

  await page.getByRole('button', { name: 'Generar informe' }).click()
  await expect(page.getByTestId('report-row').first()).toBeVisible()

  return Date.now() - startedAt
}

test(
  'el informe de 20 000 filas queda interactivo en menos de 1,5 s, sin tareas largas y sin meter las filas en el DOM',
  { tag: ['@RF-IN-01', '@RF-IN-03', '@RNF-P-04'] },
  async ({ page }) => {
    await observeLongTasks(page)
    await serveBigData(page)
    await logIn(page)

    const elapsedMs = await requestReport(page)

    expect(elapsedMs).toBeLessThan(INTERACTIVE_BUDGET_MS)

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

    // Lo medido al generar se comprueba ANTES de reiniciar el contador para el scroll.
    expect(await longestTask(page)).toBeLessThan(REPORT_LOAD_LONG_TASK_BUDGET_MS)

    await region.focus()
    await resetLongTasks(page)
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

    expect(await longestTask(page)).toBeLessThan(LONG_TASK_BUDGET_MS)
  },
)

for (const width of [1024, 768] as const) {
  test(
    `el informe de 20 000 filas no tiene violaciones criticas ni graves a ${width} px`,
    { tag: ['@RF-IN-01', '@RF-IN-03'] },
    async ({ page }) => {
      await page.setViewportSize({ width, height: 768 })
      await observeLongTasks(page)
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
        await observeLongTasks(page)
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
  'los 3 000 hallazgos de cumplimiento quedan interactivos en menos de 1,5 s, sin tareas largas y sin meter las filas en el DOM',
  { tag: ['@RF-PA-06', '@RNF-P-04'] },
  async ({ page }) => {
    await observeLongTasks(page)
    await serveBigData(page)
    await logIn(page)
    await resetLongTasks(page)

    const startedAt = Date.now()

    await page.goto('/compliance')
    await expect(page.getByTestId('compliance-finding-row').first()).toBeVisible()

    expect(Date.now() - startedAt).toBeLessThan(INTERACTIVE_BUDGET_MS)
    expect(await longestTask(page)).toBeLessThan(LONG_TASK_BUDGET_MS)

    const painted = await page.getByTestId('compliance-finding-row').count()

    expect(painted).toBeGreaterThan(0)
    expect(painted).toBeLessThan(PAINTED_CEILING)

    const region = page.getByTestId('compliance-table')

    await expect(region).toHaveAttribute('tabindex', '0')
    await expect(region.getByRole('table')).toHaveAttribute('aria-rowcount', String(FINDINGS + 1))

    await resetLongTasks(page)
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

    expect(await longestTask(page)).toBeLessThan(LONG_TASK_BUDGET_MS)
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
