// RNF-P-04 tal como se promete (doc 01 §8, doc 05): la carga del panel de
// presencia en vivo con 500 empleados, LCP < 1,5 s. Suite NOCTURNA de
// rendimiento (D4 de la 2.3.0), proyecto `admin-perf`: solo existe con
// KRONOQR_E2E_PERFORMANCE=1 (`make e2e-performance`, job `performance-kiosk`).
//
// COMO. Tres muestras EN FRIO —contexto de navegador nuevo, CPU a ×4 (el
// portatil de recepcion), Reverb y API simulados— y se juzga la mediana, como
// `perf-lcp.spec.ts` del quiosco. El LCP lo da el `PerformanceObserver` del
// propio navegador para la navegacion a /live. BLOQUEA: es la promesa.
//
// Lo que `live-presence.spec.ts` sigue probando en cada push es la funcion:
// las 500 personas contadas y el DOM acotado, sin reloj.
import { expect, test } from '@playwright/test'
import type { Browser, TestInfo } from '@playwright/test'
import { logIn, stubManagementApi } from './support/admin'
import { boardOf, fakeReverb } from './support/livePresence'
import { coldPage, median, SAMPLES } from './support/perf'

/** RNF-P-04: LCP < 1,5 s con 500 empleados. */
const LIVE_PRESENCE_LCP_BUDGET_MS = 1_500
const LIVE_PRESENCE_EMPLOYEES = 500

async function lcpSample(browser: Browser, info: TestInfo): Promise<number> {
  const page = await coldPage(browser, info)

  await fakeReverb(page.context())
  await stubManagementApi(page, { liveBoard: boardOf(LIVE_PRESENCE_EMPLOYEES) })
  await logIn(page)
  await page.goto('/live')
  await expect(page.getByTestId('present-count')).toHaveText(String(LIVE_PRESENCE_EMPLOYEES))

  // Las entradas `largest-contentful-paint` ya estan en el bufer: el primer
  // aviso del observador las trae, sin esperas ni temporizadores.
  const lcp = await page.evaluate(
    () =>
      new Promise<number>((resolve) => {
        new PerformanceObserver((list) => {
          const entries = list.getEntries()

          resolve(entries[entries.length - 1]?.startTime ?? 0)
        }).observe({ type: 'largest-contentful-paint', buffered: true })
      }),
  )

  await page.context().close()

  return lcp
}

test(
  'el panel de presencia en vivo con 500 empleados pinta su contenido principal en menos de 1,5 s, en la mediana de tres cargas en frio',
  { tag: ['@RNF-P-04', '@RF-PA-01'] },
  async ({ browser }) => {
    const samples: number[] = []

    for (let index = 0; index < SAMPLES; index += 1) {
      samples.push(await lcpSample(browser, test.info()))
    }

    test.info().annotations.push({
      type: 'muestras',
      description: samples.map((sample) => `${Math.round(sample)} ms`).join(' · '),
    })

    expect(Math.min(...samples)).toBeGreaterThan(0)
    expect(median(samples)).toBeLessThan(LIVE_PRESENCE_LCP_BUDGET_MS)
  },
)
