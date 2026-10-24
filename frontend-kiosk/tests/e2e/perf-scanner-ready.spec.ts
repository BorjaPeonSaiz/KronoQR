// Cuando esta LISTO el escaner en frio (tarea 17.10; «se paso por alto» de la
// tanda 5 de la 2.2.1; RNF-P-01, RF-KI-01, RF-KI-02).
//
// QUE MIDE Y POR QUE NO BASTA EL LCP. El LCP (`perf-lcp.spec.ts`) es la pantalla
// de espera pintada; la persona no puede fichar hasta que la camara esta abierta
// y el decodificador, que llega por `import()` despues del primer pintado, lee
// su primera tarjeta. En el primer arranque, ademas, el service worker
// precachea ~1 460 KiB (29 entradas) por la MISMA wifi. Aqui se mide, con la
// tarjeta del video siempre delante de la camara, el tiempo desde la navegacion
// hasta que la confirmacion aparece en pantalla: el primer fichaje posible.
//
// COMO. Contexto nuevo por muestra (sin cache, sin service worker previo), CPU
// x4 por CDP y la red del perfil Lighthouse «3G rapida» aplicada por un proxy
// COMPARTIDO entre la pagina y el service worker (`support/throttledProxy.ts`):
// el estrangulamiento de CDP no alcanza al precacheo. Mediana de tres muestras.
//
// EL UMBRAL. 5 000 ms de mediana desde la navegacion hasta el primer fichaje
// posible: dos veces y media el LCP del Anexo A. Una tablet que arranca tras un
// corte de luz tiene a la primera persona delante en cuanto se enciende, y 5 s
// es lo que tarda en sacar la tarjeta de la cartera. Cualquier muestra por
// encima de 4 000 ms avisa (anotacion y `::warning` en la CI) sin fallar.
//
// VALORES MEDIDOS EN LOCAL (23-10-2026, portatil de desarrollo, Chromium de
// Playwright): 3 249-4 097 ms, mediana 3 360-3 526 ms, con el LCP en 1 486-1 650
// ms. Con el service worker bloqueado sale lo mismo (3 261-3 871 ms): el
// precacheo NO retrasa al escaner, porque el service worker no llega a activarse
// antes del primer fichaje y el decodificador ya viaja antes. Lo que pesa es el
// arranque diferido tras el primer pintado, la camara y el `import()` del
// decodificador con la CPU x4. Si la mediana pasa de 4 s, se mira por que antes
// de tocar el umbral.
//
// LO QUE QUEDA ABIERTO: la tablet real (`docs/runbooks/alta-nuevo-quiosco.md` §4.5).

import { expect, test } from '@playwright/test'
import type { Browser } from '@playwright/test'
import { stubKioskApi, stubScanApi } from './support/kiosk'
import { median, warnAboutSlowSamples } from './support/perfReport'
import { startThrottledProxy } from './support/throttledProxy'
import type { LinkProfile } from './support/throttledProxy'

const SCANNER_READY_BUDGET_MS = 5000
const SCANNER_READY_WARN_MS = 4000
const SCANNER_READY_CPU_SLOWDOWN = 4
const SCANNER_READY_SAMPLES = 3
/** El perfil que juzga `perf-lcp.spec.ts`: 150 ms por peticion y 1,6 Mbit/s. */
const SCANNER_READY_FAST_3G: LinkProfile = {
  latencyMs: 150,
  downloadBytesPerSecond: (1.6 * 1024 * 1024) / 8,
}

interface ColdBoot {
  /** Desde la navegacion hasta que la confirmacion del primer escaneo esta en el DOM. */
  readonly scannerReadyMs: number
  readonly lcpMs: number
  /** Cuando el service worker quedo activo, con el precacheo hecho. `null` si no llego antes. */
  readonly serviceWorkerReadyMs: number | null
}

async function measureColdBoot(browser: Browser, origin: string): Promise<ColdBoot> {
  const context = await browser.newContext({
    baseURL: origin,
    viewport: { width: 1280, height: 800 },
    locale: 'es-ES',
    timezoneId: 'Europe/Madrid',
    permissions: ['camera'],
  })
  try {
    const page = await context.newPage()
    await page.addInitScript(() => {
      const marks: { scannerReady: number | null; lcp: number; serviceWorkerReady: number | null } =
        { scannerReady: null, lcp: 0, serviceWorkerReady: null }
      Object.defineProperty(window, '__kqBoot', { value: marks })
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) marks.lcp = entry.startTime
      }).observe({ type: 'largest-contentful-paint', buffered: true })
      new MutationObserver(() => {
        if (marks.scannerReady !== null) return
        if (document.querySelector('[data-testid="scan-confirmation"]') === null) return
        marks.scannerReady = performance.now()
      }).observe(document, { childList: true, subtree: true })
      void navigator.serviceWorker?.ready.then(() => {
        marks.serviceWorkerReady = performance.now()
      })
    })
    await stubKioskApi(page)
    await stubScanApi(page, { outcome: 'clock_in' })
    const cdp = await context.newCDPSession(page)
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: SCANNER_READY_CPU_SLOWDOWN })

    await page.goto('/', { waitUntil: 'commit' })
    await expect(page.getByTestId('scan-confirmation')).toBeVisible({ timeout: 60_000 })

    return await page.evaluate(() => {
      const marks = (
        window as unknown as {
          __kqBoot: { scannerReady: number | null; lcp: number; serviceWorkerReady: number | null }
        }
      ).__kqBoot
      return {
        scannerReadyMs: marks.scannerReady ?? Number.POSITIVE_INFINITY,
        lcpMs: marks.lcp,
        serviceWorkerReadyMs: marks.serviceWorkerReady,
      }
    })
  } finally {
    await context.close()
  }
}

test(
  'el escaner esta listo en frio, con el precacheo compitiendo por la red, en 5 s como mucho',
  { tag: ['@RNF-P-01', '@RF-KI-01', '@RF-KI-02'] },
  async ({ browser, baseURL }) => {
    test.setTimeout(240_000)
    const proxy = await startThrottledProxy(baseURL ?? '', SCANNER_READY_FAST_3G)
    try {
      const samples: ColdBoot[] = []
      for (let index = 0; index < SCANNER_READY_SAMPLES; index += 1) {
        samples.push(await measureColdBoot(browser, proxy.origin))
      }

      const ready = samples.map((sample) => sample.scannerReadyMs)
      const readyMedian = median(ready)
      const report =
        `escaner listo ${ready.map(Math.round).join(' / ')} ms; ` +
        `LCP ${samples.map((sample) => Math.round(sample.lcpMs)).join(' / ')} ms; ` +
        `service worker activo ${samples.map((sample) => (sample.serviceWorkerReadyMs === null ? 'no' : Math.round(sample.serviceWorkerReadyMs))).join(' / ')} ms`
      test.info().annotations.push({ type: 'Arranque en frio', description: report })
      console.log(`[perf] ${report}`)
      warnAboutSlowSamples('Escaner listo en frio', ready, SCANNER_READY_WARN_MS)

      expect(
        readyMedian,
        `escaner listo, mediana ${Math.round(readyMedian)} ms (presupuesto ${SCANNER_READY_BUDGET_MS} ms)`,
      ).toBeLessThanOrEqual(SCANNER_READY_BUDGET_MS)
    } finally {
      await proxy.close()
    }
  },
)
