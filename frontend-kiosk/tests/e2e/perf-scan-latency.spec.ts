// RNF-P-01 — latencia percibida del fichaje, con percentiles (KP3; D4 de la 2.3.0).
//
// QUE MIDE. Sesenta fichajes seguidos con la camara simulada: dos tarjetas que se
// turnan delante de ella con un segundo sin nadie entre una y otra
// (`qr-video-alternating.y4m`), como en un cambio de turno. Cada aparicion es otra
// persona para el quiosco y un fichaje nuevo. De cada uno se toma el tiempo desde
// el FOTOGRAMA en que aparece la tarjeta hasta que la confirmacion esta PINTADA:
//
//   - el fotograma se ve con `requestVideoFrameCallback` sobre el `<video>` del
//     quiosco: cada fotograma se reduce a 64 x 36 y se compara con el anterior;
//   - la confirmacion, con un `MutationObserver` que ve pasar `data-kind` a
//     `pending` (lectura hecha, servidor sin contestar), y el fotograma siguiente.
//
// Entra todo lo que el quiosco hace: la espera hasta el siguiente intento de
// ZXing, la decodificacion, la tuberia y el pintado. Ademas se anota la sonda
// `scan-latency-ms` de la aplicacion (de la lectura al estado de la confirmacion),
// que es la parte de los 300 ms del doc 02 §6.
//
// LO QUE NO ENTRA. El servidor: la confirmacion no espera a la red (regla dura 19),
// y eso lo prueba `scan.spec.ts` con la respuesta retenida. La camara y la CPU de
// una tablet de gama media, que ninguna maquina de integracion continua
// reproduce (`docs/runbooks/alta-nuevo-quiosco.md` §4.5).
//
// EL UMBRAL es el del Anexo A, sin tocar: p95 < 800 ms y p99 < 1,5 s; y la
// mediana de la parte del quiosco por debajo de sus 300 ms. Con sesenta muestras
// el p99 es la peor: un solo fichaje lento lo decide, que es lo que el p99 dice.
//
// POR QUE NOCTURNA. Es reloj de verdad sobre un runner compartido: en el E2E de
// cada push fallaria cuando la maquina va cargada (la de 300 ms de una muestra
// fallo 2 de 70 veces, V2-QB-1). Corre en el job `performance-kiosk` de ci.yml
// (`make e2e-performance`); su proyecto solo existe con
// `KRONOQR_E2E_PERFORMANCE=1` (ver `playwright.config.ts`).

import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { stubKioskApi, stubScanApi } from './support/kiosk'
import { median } from './support/perfReport'

/** Fichajes medidos: por encima de los 50 que pide KP3. */
const SCAN_LATENCY_SAMPLES = 60
/** RNF-P-01, Anexo A del doc 01. */
const SCAN_LATENCY_P95_BUDGET_MS = 800
const SCAN_LATENCY_P99_BUDGET_MS = 1500
/** La parte del quiosco de RNF-P-01: de la lectura a la confirmacion (doc 02 §6). */
const SCAN_LATENCY_KIOSK_BUDGET_MS = 300
/** Sesenta fichajes a uno cada 2 s, con holgura para el arranque en frio. */
const SCAN_LATENCY_TIMEOUT_MS = 240_000

interface ScanLatencySample {
  /** Lo que mide la aplicacion: de la lectura al estado de la confirmacion. */
  readonly kioskMs: number
  /** Del fotograma en que aparece la tarjeta a la confirmacion pintada. */
  readonly perceivedMs: number
}

interface ScanLatencyProbe {
  __kronoqrScanLatencies: ScanLatencySample[]
}

/**
 * La sonda, en la pagina y antes de que arranque la camara.
 *
 * El instante del fotograma se lee DESPUES del pintado de la confirmacion y no
 * cuando cambia `data-kind`: el bucle de ZXing puede leer un fotograma antes de
 * que su `requestVideoFrameCallback` haya corrido, y en el paso de pintado
 * siguiente ese callback ya ha corrido seguro.
 */
async function installScanLatencyProbe(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const probe = window as unknown as ScanLatencyProbe
    probe.__kronoqrScanLatencies = []

    const canvas = document.createElement('canvas')
    canvas.width = 64
    canvas.height = 36
    const context = canvas.getContext('2d', { willReadFrequently: true })
    let lastFrame = ''
    let lastFrameChangeAt = Number.NaN

    function watchFrames(video: HTMLVideoElement): void {
      const onFrame: VideoFrameRequestCallback = (now, metadata) => {
        if (context !== null) {
          context.drawImage(video, 0, 0, canvas.width, canvas.height)
          const frame = context.getImageData(0, 0, canvas.width, canvas.height).data.join(',')
          if (frame !== lastFrame) {
            lastFrame = frame
            lastFrameChangeAt = metadata.presentationTime || now
          }
        }
        video.requestVideoFrameCallback(onFrame)
      }
      video.requestVideoFrameCallback(onFrame)
    }

    let watchedVideo: HTMLVideoElement | null = null
    let lastKind: string | null = null

    const observer = new MutationObserver(() => {
      const video = document.querySelector('video')
      if (video !== null && video !== watchedVideo) {
        watchedVideo = video
        watchFrames(video)
      }

      const kind =
        document.querySelector('[data-testid="scan-confirmation"]')?.getAttribute('data-kind') ??
        null
      const isNewScan = kind === 'pending' && lastKind !== 'pending'
      lastKind = kind
      if (!isNewScan) return

      const kioskMs = Number(
        document.querySelector('[data-testid="scan-latency-ms"]')?.textContent ?? 'NaN',
      )
      requestAnimationFrame(() => {
        setTimeout(() => {
          probe.__kronoqrScanLatencies.push({
            kioskMs,
            perceivedMs: performance.now() - lastFrameChangeAt,
          })
        }, 0)
      })
    })

    document.addEventListener('DOMContentLoaded', () => {
      observer.observe(document.body, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['data-kind'],
      })
    })
  })
}

/** Percentil por rango mas cercano: siempre un valor medido. */
function percentile(values: readonly number[], rank: number): number {
  const sorted = [...values].sort((left, right) => left - right)
  return sorted[Math.ceil((rank / 100) * sorted.length) - 1] ?? Number.POSITIVE_INFINITY
}

test(
  'confirma sesenta fichajes seguidos con p95 por debajo de 800 ms y p99 por debajo de 1,5 s',
  { tag: ['@RNF-P-01', '@RF-KI-02'] },
  async ({ page }) => {
    test.setTimeout(SCAN_LATENCY_TIMEOUT_MS)
    await stubKioskApi(page)
    await stubScanApi(page, { outcome: 'clock_in' })
    await installScanLatencyProbe(page)

    await page.goto('/')

    await expect
      .poll(
        () =>
          page.evaluate(
            () => (window as unknown as ScanLatencyProbe).__kronoqrScanLatencies.length,
          ),
        { timeout: SCAN_LATENCY_TIMEOUT_MS - 30_000 },
      )
      .toBeGreaterThanOrEqual(SCAN_LATENCY_SAMPLES)

    const samples = (
      await page.evaluate(() => (window as unknown as ScanLatencyProbe).__kronoqrScanLatencies)
    ).slice(0, SCAN_LATENCY_SAMPLES)
    const perceived = samples.map((sample) => sample.perceivedMs)
    const kiosk = samples.map((sample) => sample.kioskMs)
    const report = {
      muestras: samples.length,
      p50: Math.round(median(perceived)),
      p95: Math.round(percentile(perceived, 95)),
      p99: Math.round(percentile(perceived, 99)),
      quioscoMediana: Math.round(median(kiosk)),
    }
    test.info().annotations.push({ type: 'RNF-P-01', description: JSON.stringify(report) })
    console.log(`RNF-P-01: ${JSON.stringify(report)}`)

    expect(perceived.every(Number.isFinite), 'alguna muestra no se pudo leer').toBe(true)
    expect(percentile(perceived, 95)).toBeLessThan(SCAN_LATENCY_P95_BUDGET_MS)
    expect(percentile(perceived, 99)).toBeLessThan(SCAN_LATENCY_P99_BUDGET_MS)
    expect(median(kiosk)).toBeLessThan(SCAN_LATENCY_KIOSK_BUDGET_MS)
  },
)
