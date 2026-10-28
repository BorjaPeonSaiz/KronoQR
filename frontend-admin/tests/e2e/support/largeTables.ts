// Datos y ayudantes de las tablas grandes del panel (V5-PA-1, RC1 · RF-IN-01,
// RF-IN-03, RF-PA-06, RNF-P-04), compartidos por la prueba funcional
// (`large-tables.spec.ts`, en cada push) y por la de tiempo
// (`perf-large-tables.spec.ts`, nocturna). Una afirmacion contra el reloj
// dentro de la suite funcional falla cuando el runner va cargado, no cuando el
// panel empeora (D4 de la 2.3.0): el informe de 20 000 filas dio 1 626 ms
// frente a 1 500 en el run 40722198667 con todo lo demas en verde.
import type { CDPSession, Page } from '@playwright/test'
import type { ComplianceFinding, PeriodReport, PeriodReportRow } from '@/shared/api/types'
import {
  BILINGUAL_BRANDING,
  COMPLIANCE_SUMMARY,
  EMPLOYEE_UUID,
  PERIOD_REPORT,
  stubManagementApi,
} from './admin'

export const REPORT_ROWS = 20_000
export const FINDINGS = 3_000
/** Cuanto cabe en pantalla mas el sobrepintado: el techo de filas en el DOM con cualquier volumen. */
export const PAINTED_CEILING = 80
/**
 * Ralentizacion de CPU con la que se mide (CDP `Emulation.setCPUThrottlingRate`):
 * un portatil de recepcion es varias veces mas lento que la maquina de desarrollo
 * o del runner. Con ×1 los tiempos se cumplian de sobra y no decian nada del
 * equipo real (V5-PA-1, tanda 5 «se pasó por alto»).
 */
export const CPU_THROTTLE_RATE = 4

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

export const BIG_REPORT: PeriodReport = {
  ...PERIOD_REPORT,
  data: Array.from({ length: REPORT_ROWS }, (_, index) => reportRow(index + 1)),
  meta: { ...PERIOD_REPORT.meta, row_count: REPORT_ROWS },
}

/** Informe agrupado por centro: sin columna de sujeto, la rejilla mas corta. */
export const SITE_REPORT: PeriodReport = {
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
export async function serveBigData(
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

/** La CPU del portatil de recepcion: se aplica antes de cada prueba. */
export async function throttleCpu(page: Page): Promise<CDPSession> {
  const session = await page.context().newCDPSession(page)

  await session.send('Emulation.setCPUThrottlingRate', { rate: CPU_THROTTLE_RATE })

  return session
}

/** Registra las tareas largas desde el primer momento de cada documento. */
export async function observeLongTasks(page: Page): Promise<void> {
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

export async function resetLongTasks(page: Page): Promise<void> {
  await page.evaluate(() => {
    window.__longTaskObserver.takeRecords()
    window.__longTasks.length = 0
  })
}

export async function longestTask(page: Page): Promise<number> {
  // `takeRecords` entrega ya las entradas pendientes: sin esperas ni temporizadores.
  return page.evaluate(() => {
    for (const entry of window.__longTaskObserver.takeRecords()) {
      window.__longTasks.push(entry.duration)
    }

    return Math.max(0, ...window.__longTasks)
  })
}

/** Abre el informe por periodo con las fechas de marzo, listo para generarlo. */
export async function openReportForm(page: Page): Promise<void> {
  await page.goto('/reports')
  await page.getByLabel('Desde').fill('2026-03-01')
  await page.getByLabel('Hasta').fill('2026-03-31')
}
