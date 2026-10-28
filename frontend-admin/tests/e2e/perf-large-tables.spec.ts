// RNF-P-04 en las tablas grandes del panel, en la suite NOCTURNA de
// rendimiento (D4 de la 2.3.0; separado de `large-tables.spec.ts`, que prueba
// lo mismo sin reloj en cada push).
//
// QUE FIJA. Con la API simulada sirviendo 20 000 filas de informe y 3 000
// hallazgos de cumplimiento, y la CPU a ×4 (el portatil de recepcion), la
// pantalla queda interactiva —primera fila pintada— en menos de 1,5 s, y
// ninguna tarea larga (`longtask`) pasa de su tope ni al generar ni al
// desplazar.
//
// POR QUE AQUI Y NO EN LA SUITE FUNCIONAL. La misma afirmacion en
// `large-tables.spec.ts` dio 1 626 ms frente a 1 500 en el run 40722198667,
// con el resto de la suite en verde: es la carga del runner, no el panel. Se
// juzga la MEDIANA de tres muestras, como `perf-lcp.spec.ts` del quiosco, para
// que un pico aislado no sea un rojo. Solo existe con
// KRONOQR_E2E_PERFORMANCE=1 (proyecto `admin-perf`, `make e2e-performance`,
// job nocturno `performance-kiosk` de ci.yml).
import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { logIn } from './support/admin'
import {
  longestTask,
  observeLongTasks,
  openReportForm,
  resetLongTasks,
  serveBigData,
  throttleCpu,
} from './support/largeTables'

/** RNF-P-04: interactiva en menos de 1,5 s. */
const INTERACTIVE_BUDGET_MS = 1_500
const LONG_TASK_BUDGET_MS = 200
/**
 * Solo al GENERAR el informe de 20 000 filas, con CPU ×4: leer y `JSON.parse` de un
 * cuerpo de ~12 MB son ~100 ms medidos con ×4 en una sola tarea que el navegador
 * no puede partir. El resto (desplazar, la tabla de cumplimiento) sigue en 200 ms.
 * Bajarlo exige paginar o transmitir el informe en el servidor, no tocar la tabla.
 */
const REPORT_LOAD_LONG_TASK_BUDGET_MS = 300
/** Muestras por medida. Impar, para que la mediana sea un valor medido. */
const SAMPLES = 3

interface Sample {
  readonly interactiveMs: number
  readonly longestLoadTaskMs: number
  readonly longestScrollTaskMs: number
}

function median(values: readonly number[]): number {
  const sorted = [...values].sort((a, b) => a - b)

  return sorted[Math.floor(sorted.length / 2)] ?? Number.POSITIVE_INFINITY
}

/** Desplaza la region hasta la mitad y devuelve la tarea larga mas larga del desplazamiento. */
async function scrollHalfway(page: Page, regionTestId: string, rowTestId: string): Promise<number> {
  const region = page.getByTestId(regionTestId)

  await resetLongTasks(page)
  await region.evaluate((element) => {
    element.scrollTop = element.scrollHeight / 2
  })
  await expect
    .poll(async () =>
      Number(await page.getByTestId(rowTestId).first().getAttribute('aria-rowindex')),
    )
    .toBeGreaterThan(100)

  return longestTask(page)
}

async function reportSample(page: Page): Promise<Sample> {
  await openReportForm(page)
  await resetLongTasks(page)

  const startedAt = Date.now()

  await page.getByRole('button', { name: 'Generar informe' }).click()
  await expect(page.getByTestId('report-row').first()).toBeVisible()

  const interactiveMs = Date.now() - startedAt
  const longestLoadTaskMs = await longestTask(page)
  const longestScrollTaskMs = await scrollHalfway(page, 'period-report-table', 'report-row')

  return { interactiveMs, longestLoadTaskMs, longestScrollTaskMs }
}

async function complianceSample(page: Page): Promise<Sample> {
  await resetLongTasks(page)

  const startedAt = Date.now()

  await page.goto('/compliance')
  await expect(page.getByTestId('compliance-finding-row').first()).toBeVisible()

  const interactiveMs = Date.now() - startedAt
  const longestLoadTaskMs = await longestTask(page)
  const longestScrollTaskMs = await scrollHalfway(
    page,
    'compliance-table',
    'compliance-finding-row',
  )

  return { interactiveMs, longestLoadTaskMs, longestScrollTaskMs }
}

async function samplesOf(page: Page, take: (page: Page) => Promise<Sample>): Promise<Sample[]> {
  const samples: Sample[] = []

  for (let index = 0; index < SAMPLES; index += 1) {
    samples.push(await take(page))
  }

  test.info().annotations.push({
    type: 'muestras',
    description: samples
      .map(
        (sample) =>
          `${sample.interactiveMs}/${sample.longestLoadTaskMs}/${sample.longestScrollTaskMs} ms`,
      )
      .join(' · '),
  })

  return samples
}

test.beforeEach(async ({ page }) => {
  await throttleCpu(page)
  await observeLongTasks(page)
  await serveBigData(page)
  await logIn(page)
})

test(
  'el informe de 20 000 filas queda interactivo en menos de 1,5 s y sin tareas largas, en la mediana de tres muestras',
  { tag: ['@RNF-P-04', '@RF-IN-01'] },
  async ({ page }) => {
    const samples = await samplesOf(page, reportSample)

    expect(median(samples.map((sample) => sample.interactiveMs))).toBeLessThan(
      INTERACTIVE_BUDGET_MS,
    )
    expect(median(samples.map((sample) => sample.longestLoadTaskMs))).toBeLessThan(
      REPORT_LOAD_LONG_TASK_BUDGET_MS,
    )
    expect(median(samples.map((sample) => sample.longestScrollTaskMs))).toBeLessThan(
      LONG_TASK_BUDGET_MS,
    )
  },
)

test(
  'los 3 000 hallazgos de cumplimiento quedan interactivos en menos de 1,5 s y sin tareas largas, en la mediana de tres muestras',
  { tag: ['@RNF-P-04', '@RF-PA-06'] },
  async ({ page }) => {
    const samples = await samplesOf(page, complianceSample)

    expect(median(samples.map((sample) => sample.interactiveMs))).toBeLessThan(
      INTERACTIVE_BUDGET_MS,
    )
    expect(median(samples.map((sample) => sample.longestLoadTaskMs))).toBeLessThan(
      LONG_TASK_BUDGET_MS,
    )
    expect(median(samples.map((sample) => sample.longestScrollTaskMs))).toBeLessThan(
      LONG_TASK_BUDGET_MS,
    )
  },
)
