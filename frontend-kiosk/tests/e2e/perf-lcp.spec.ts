// KP1 (2.2.1, bloque 17) — LCP del quiosco en frio, con la CPU a x4 y 3G rapida.
//
// QUE FIJA. Doc 02, Anexo A: LCP <= 2 s en tablet de gama media. La tablet de
// pared no es el portatil de quien ejecuta la prueba, asi que se estrangula con
// el protocolo de DevTools (`Emulation.setCPUThrottlingRate` = 4 y
// `Network.emulateNetworkConditions` con el perfil «Fast 3G» de Chrome) y se
// mide con el `PerformanceObserver` de `largest-contentful-paint` del propio
// navegador. Se sirve el BUILD (`vite preview`, mismas cabeceras CSP que
// Nginx), no el servidor de desarrollo: lo que se mide es lo que se instala.
//
// «EN FRIO»: un contexto de navegador nuevo por muestra, sin service worker
// previo, sin cache HTTP y sin padron cacheado. Es el peor primer arranque.
//
// MEDIANA DE TRES MUESTRAS, no una: un runner compartido tiene picos que no son
// una regresion. Lo que caza esta prueba es el orden de magnitud —alguien que
// devuelva ZXing o libsodium (500-900 KB sin comprimir) al camino critico, o que
// quite la precarga de fuentes—, no un 5 %.
//
// VALORES MEDIDOS EN LOCAL (portatil de desarrollo, Windows, Chromium de
// Playwright, 3 muestras, perfil Lighthouse: 150 ms, 1,6 Mbit/s, CPU x4):
// 1364-1456 ms, es decir un margen del 27-32 % sobre los 2000 ms. Con el perfil
// «Fast 3G» de las DevTools (562,5 ms por peticion, solo informativo): 2184-2296 ms.
// Antes de KP1 el mismo harness daba 1496-1500 ms (Lighthouse) y 2356 ms (DevTools).
// Valores de la CI (job ⑦ del quiosco, run 38762123501): 1340 / 1352 / 1364 ms
// (margen del 32 %) y 2132 ms con el perfil de las DevTools.
//
// DOS UMBRALES DESDE LA 2.3.0 (V21-5-KI-2). Antes solo se juzgaba la mediana, y
// una muestra de 1 544 ms en el host paso sin que nadie la viera. Ahora:
//   - CADA muestra, no la mediana, cabe en los 2 000 ms del Anexo A: una sola
//     carga en frio fuera de presupuesto ya es una tablet que tarda de mas.
//   - Cualquier muestra por encima de 1 500 ms (margen < 25 %) AVISA sin fallar:
//     anotacion y `::warning` en el resumen del job de la CI. Si avisa, se mira
//     por que antes de tocar el umbral o el numero de muestras.
//
// LO QUE QUEDA ABIERTO: el LCP en la tablet real. Esto es la maquina de quien
// ejecuta la prueba estrangulada, y se declara asi (mismo criterio que
// `qr-decode-budget.spec.ts`).

import { expect, test } from '@playwright/test'
import type { Browser } from '@playwright/test'
import { stubKioskApi } from './support/kiosk'
import { median, warnAboutSlowSamples } from './support/perfReport'

/** Presupuesto del Anexo A del doc 02. */
const LCP_BUDGET_MS = 2000
/** Margen del 25 % sobre el presupuesto: por encima, aviso (V21-5-KI-2). */
const LCP_WARN_MS = 1500
const CPU_SLOWDOWN = 4
/**
 * «3G rapida» tal y como la define Lighthouse (preset movil): 150 ms de RTT,
 * 1,6 Mbit/s de bajada, 750 kbit/s de subida y CPU x4. Es el perfil que JUZGA
 * esta prueba. Lighthouse modela las idas y vueltas de la conexion (TCP, TLS) por
 * su cuenta; contra un servidor local con la conexion ya abierta, la latencia
 * se aplica una vez por peticion.
 */
interface NetworkProfile {
  readonly offline: false
  readonly latency: number
  readonly downloadThroughput: number
  readonly uploadThroughput: number
}

const FAST_3G_LIGHTHOUSE: NetworkProfile = {
  offline: false,
  latency: 150,
  downloadThroughput: (1.6 * 1024 * 1024) / 8,
  uploadThroughput: (750 * 1024) / 8,
} as const

/**
 * El perfil «Fast 3G» de la lista de las DevTools de Chrome: la MISMA banda, pero
 * 562,5 ms de latencia en CADA peticion (equivale a los 150 ms de Lighthouse por
 * ~3,75 idas y vueltas). Es el caso peor y solo se INFORMA, no se juzga: es lo
 * que ve un primer arranque sobre una 3G real con handshake, y sirve para vigilar
 * que no se aleje del presupuesto.
 */
const FAST_3G_DEVTOOLS: NetworkProfile = { ...FAST_3G_LIGHTHOUSE, latency: 562.5 } as const
const SAMPLES = 3

interface ColdLoad {
  readonly lcpMs: number
  readonly firstContentfulPaintMs: number
  /** Inicio de la primera peticion de un trozo diferido (decodificador o libsodium). */
  readonly firstDeferredRequestMs: number | null
}

async function measureColdLoad(
  browser: Browser,
  baseURL: string,
  network: NetworkProfile,
): Promise<ColdLoad> {
  const context = await browser.newContext({
    baseURL,
    viewport: { width: 1280, height: 800 },
    locale: 'es-ES',
    timezoneId: 'Europe/Madrid',
    permissions: ['camera'],
    serviceWorkers: 'block',
  })
  try {
    const page = await context.newPage()
    await page.addInitScript(() => {
      const state = { lcp: 0 }
      Object.defineProperty(window, '__kqLcp', { value: state })
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) state.lcp = entry.startTime
      }).observe({ type: 'largest-contentful-paint', buffered: true })
    })
    await stubKioskApi(page)

    const cdp = await context.newCDPSession(page)
    await cdp.send('Network.enable')
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true })
    await cdp.send('Network.emulateNetworkConditions', network)
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: CPU_SLOWDOWN })

    await page.goto('/', { waitUntil: 'load' })
    await expect(page.getByTestId('scan-camera-section')).toBeVisible({ timeout: 30_000 })
    // Condicion, no tiempo: el decodificador solo se pide tras el primer pintado,
    // asi que su peticion prueba que el arranque ya paso por el pintado y deja al
    // navegador cerrar el candidato de LCP.
    await expect
      .poll(
        () =>
          page.evaluate(
            () =>
              performance
                .getEntriesByType('resource')
                .filter((e) => e.name.includes('/assets/esm-')).length,
          ),
        { timeout: 30_000 },
      )
      .toBeGreaterThan(0)

    return await page.evaluate(() => {
      const state = (window as unknown as { __kqLcp: { lcp: number } }).__kqLcp
      const fcp = performance.getEntriesByName('first-contentful-paint')[0]
      const deferred = performance
        .getEntriesByType('resource')
        .filter((entry) => /\/assets\/(pinSealing|esm)-/.test(entry.name))
        .map((entry) => entry.startTime)
      return {
        lcpMs: state.lcp,
        firstContentfulPaintMs: fcp?.startTime ?? 0,
        firstDeferredRequestMs: deferred.length === 0 ? null : Math.min(...deferred),
      }
    })
  } finally {
    await context.close()
  }
}

test(
  'el LCP del quiosco en frio, con CPU x4 y 3G rapida, no pasa de 2 s en ninguna muestra',
  { tag: ['@RNF-P-07'] },
  async ({ browser, baseURL }) => {
    test.setTimeout(180_000)
    expect(baseURL).toBeDefined()

    const samples: ColdLoad[] = []
    for (let i = 0; i < SAMPLES; i += 1) {
      samples.push(await measureColdLoad(browser, baseURL ?? '', FAST_3G_LIGHTHOUSE))
    }

    const lcp = samples.map((sample) => sample.lcpMs).sort((a, b) => a - b)
    const lcpMedian = median(lcp)
    const lcpWorst = lcp.at(-1) ?? Number.POSITIVE_INFINITY
    test.info().annotations.push({
      type: 'LCP (ms)',
      description: `muestras ${lcp.map((v) => Math.round(v)).join(', ')}; mediana ${Math.round(lcpMedian)}`,
    })
    console.log(
      `[perf] LCP en frio (CPU x${CPU_SLOWDOWN}, Fast 3G): ${lcp.map(Math.round).join(' / ')} ms`,
    )
    warnAboutSlowSamples('LCP en frio', lcp, LCP_WARN_MS)

    // Caso peor, solo informativo (ver `FAST_3G_DEVTOOLS`).
    const worst = await measureColdLoad(browser, baseURL ?? '', FAST_3G_DEVTOOLS)
    test.info().annotations.push({
      type: 'LCP DevTools Fast 3G (ms)',
      description: String(Math.round(worst.lcpMs)),
    })
    console.log(`[perf] LCP en frio, DevTools Fast 3G (informativo): ${Math.round(worst.lcpMs)} ms`)

    expect(lcp[0] ?? 0, `LCP de la mejor muestra ${Math.round(lcp[0] ?? 0)} ms`).toBeGreaterThan(0)
    expect(
      lcpWorst,
      `LCP de la peor muestra ${Math.round(lcpWorst)} ms (presupuesto ${LCP_BUDGET_MS} ms)`,
    ).toBeLessThanOrEqual(LCP_BUDGET_MS)

    // El decodificador y libsodium no compiten con el primer pintado: sus
    // peticiones nacen DESPUES del primer contenido pintado.
    for (const sample of samples) {
      if (sample.firstDeferredRequestMs === null) continue
      expect(sample.firstDeferredRequestMs).toBeGreaterThanOrEqual(sample.firstContentfulPaintMs)
    }
  },
)
