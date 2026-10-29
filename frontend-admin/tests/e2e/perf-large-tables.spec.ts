// Las tablas de volumen grande del panel, en la suite NOCTURNA de rendimiento
// (D4 de la 2.3.0; separado de `large-tables.spec.ts`, que prueba la funcion
// sin reloj en cada push).
//
// QUE MIDE. Con la API simulada sirviendo 20 000 filas de informe y 3 000
// hallazgos de cumplimiento, y la CPU a ×4 (el portatil de recepcion), lo que
// tarda la pantalla en quedar interactiva —primera fila pintada— y la tarea
// larga (`longtask`) mas larga al cargar y al desplazar.
//
// UN OBJETIVO INTERNO, NO LA PROMESA (decision del propietario, 28-10-2026).
// RNF-P-04 promete el panel de presencia en vivo con 500 empleados, y eso lo
// mide y lo BLOQUEA `perf-live-presence.spec.ts`. Estos volumenes no se han
// prometido: sus umbrales (1,5 s interactivo; 300 ms de tarea larga al generar
// el informe, 200 ms en el resto) se siguen midiendo, pero AVISAN —anotacion
// y `::warning` en el resumen del job— sin poner la nocturna en rojo. Lo que si
// falla es que la pantalla no llegue a pintar filas. Medido en el anfitrion con
// CPU ×4 y en frio, la carga de los hallazgos pasa de 200 ms de tarea larga;
// arreglarlo (paginar, trocear la carga) es trabajo del producto, no de esta
// prueba.
//
// COMO. La MEDIANA de tres muestras EN FRIO —un contexto de navegador nuevo
// por muestra—, como `perf-lcp.spec.ts` del quiosco. El doble de la API sirve
// el cuerpo ya serializado (`support/largeTables.ts`): con
// `route.fulfill({ json })` Node serializaba los ~12 MB del informe dentro de
// la ventana medida. Solo existe con KRONOQR_E2E_PERFORMANCE=1 (proyecto
// `admin-perf`, `make e2e-performance`, job nocturno `performance-kiosk`).
import type { Browser, Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { logIn } from './support/admin'
import {
  longestTask,
  observeLongTasks,
  openReportForm,
  resetLongTasks,
  serveBigData,
} from './support/largeTables'
import { coldPage, median, SAMPLES, warnAboveTarget } from './support/perf'

/** Objetivo interno: interactiva en menos de 1,5 s. */
const INTERACTIVE_TARGET_MS = 1_500
const LONG_TASK_TARGET_MS = 200
/**
 * Solo al GENERAR el informe de 20 000 filas, con CPU ×4: leer y `JSON.parse` de un
 * cuerpo de ~12 MB son ~100 ms medidos con ×4 en una sola tarea que el navegador
 * no puede partir. El resto (desplazar, la tabla de cumplimiento) sigue en 200 ms.
 * Bajarlo exige paginar o transmitir el informe en el servidor, no tocar la tabla.
 */
const REPORT_LOAD_LONG_TASK_TARGET_MS = 300

interface Sample {
  readonly interactiveMs: number
  readonly longestLoadTaskMs: number
  readonly longestScrollTaskMs: number
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

async function samplesOf(
  browser: Browser,
  take: (page: Page) => Promise<Sample>,
): Promise<Sample[]> {
  const samples: Sample[] = []

  for (let index = 0; index < SAMPLES; index += 1) {
    const page = await coldPage(browser, test.info())

    await observeLongTasks(page)
    await serveBigData(page)
    await logIn(page)
    samples.push(await take(page))
    await page.context().close()
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

test(
  'mide el informe de 20 000 filas frente al objetivo interno de 1,5 s y sin tareas largas, y avisa si lo pasa',
  { tag: ['@RF-IN-01'] },
  async ({ browser }) => {
    const samples = await samplesOf(browser, reportSample)

    warnAboveTarget(
      'Informe de 20 000 filas, interactivo',
      median(samples.map((sample) => sample.interactiveMs)),
      INTERACTIVE_TARGET_MS,
    )
    warnAboveTarget(
      'Informe de 20 000 filas, tarea larga al generar',
      median(samples.map((sample) => sample.longestLoadTaskMs)),
      REPORT_LOAD_LONG_TASK_TARGET_MS,
    )
    warnAboveTarget(
      'Informe de 20 000 filas, tarea larga al desplazar',
      median(samples.map((sample) => sample.longestScrollTaskMs)),
      LONG_TASK_TARGET_MS,
    )
  },
)

test(
  'mide los 3 000 hallazgos de cumplimiento frente al objetivo interno de 1,5 s y sin tareas largas, y avisa si lo pasa',
  { tag: ['@RF-PA-06'] },
  async ({ browser }) => {
    const samples = await samplesOf(browser, complianceSample)

    warnAboveTarget(
      '3 000 hallazgos, interactivo',
      median(samples.map((sample) => sample.interactiveMs)),
      INTERACTIVE_TARGET_MS,
    )
    warnAboveTarget(
      '3 000 hallazgos, tarea larga al cargar',
      median(samples.map((sample) => sample.longestLoadTaskMs)),
      LONG_TASK_TARGET_MS,
    )
    warnAboveTarget(
      '3 000 hallazgos, tarea larga al desplazar',
      median(samples.map((sample) => sample.longestScrollTaskMs)),
      LONG_TASK_TARGET_MS,
    )
  },
)
