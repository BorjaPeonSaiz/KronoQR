#!/usr/bin/env node
//
// KronoQR — monitor de la prueba de resistencia de 12 h del quiosco en la tablet
// REAL (doc 02, Anexo A; tarea 17.10; R5-KI-02). El procedimiento completo, con
// la preparacion de la tablet y que hacer con el resultado, esta en
// `docs/runbooks/alta-nuevo-quiosco.md` §4.5.
//
// SOLO OBSERVA. Se conecta por el protocolo de DevTools a la pestaña del quiosco
// (Chrome de Android, con `adb forward tcp:9222 localabstract:chrome_devtools_remote`),
// recarga la pagina UNA vez al empezar para poder contar las camaras desde el
// primer `getUserMedia`, y despues solo lee: memoria y nodos del DOM por CDP,
// pistas de video vivas, si el video avanza, si hay aviso de camara y cuantas
// recargas ha habido. No toca la cola, no ficha, no cambia nada del quiosco.
//
// Uso:
//
//   node scripts/soak-monitor.mjs [--cdp http://127.0.0.1:9222] [--hours 12]
//       [--interval-min 5] [--out soak-AAAAMMDD.jsonl] [--url-includes /kiosk]
//       [--adb-pid-of <proceso>]
//
//   --url-includes  lo que identifica la pestaña del quiosco en su URL (`/kiosk` de serie).
//   --adb-pid-of  nombre del proceso de Android de la pestaña (por ejemplo
//                 `com.android.chrome:sandboxed_process0`): con el, cada muestra
//                 lee tambien su PSS con `adb shell dumpsys meminfo` y el
//                 criterio de 250 MB se juzga sobre el proceso, no sobre el
//                 monton de JavaScript.
//
// Salida: una linea JSON por muestra en `--out`, el veredicto en la consola y en
// `<out>.verdict.json`. Codigo 0 si pasa, 1 si no, 2 si no se pudo medir.
// Ctrl+C termina antes de tiempo y da el veredicto de lo medido.

import { execFile } from 'node:child_process'
import { appendFileSync, writeFileSync } from 'node:fs'
import { promisify } from 'node:util'
import { chromium } from '@playwright/test'
import { soakVerdict } from './soak/verdict.mjs'

const run = promisify(execFile)

function option(name, fallback) {
  const index = process.argv.indexOf(`--${name}`)
  return index === -1 ? fallback : (process.argv[index + 1] ?? fallback)
}

const cdpUrl = option('cdp', 'http://127.0.0.1:9222')
const hours = Number(option('hours', '12'))
const intervalMs = Number(option('interval-min', '5')) * 60_000
const stamp = new Date().toISOString().slice(0, 10).replaceAll('-', '')
const outFile = option('out', `soak-${stamp}.jsonl`)
const adbProcess = option('adb-pid-of', null)
/** Lo que identifica la pestaña del quiosco en su URL. */
const urlIncludes = option('url-includes', '/kiosk')

/** PSS del proceso, en bytes, o `null` si `adb` no esta o no lo encuentra. */
async function processMemoryBytes() {
  if (adbProcess === null) return null
  try {
    const { stdout } = await run('adb', ['shell', 'dumpsys', 'meminfo', adbProcess])
    const match = /TOTAL PSS:\s+(\d+)/.exec(stdout) ?? /TOTAL:\s+(\d+)/.exec(stdout)
    return match === null ? null : Number(match[1]) * 1024
  } catch {
    return null
  }
}

/** Se inyecta antes de cada carga: cuenta TODAS las camaras abiertas, tambien las que nadie pinta. */
function trackCameras() {
  const streams = []
  Object.defineProperty(window, '__kqSoakStreams', { value: streams })
  const original = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices)
  navigator.mediaDevices.getUserMedia = async (constraints) => {
    const stream = await original(constraints)
    streams.push(stream)
    return stream
  }
}

function readPage() {
  const streams = window.__kqSoakStreams ?? []
  const video = document.querySelector('[data-testid="scan-video"]')
  return {
    liveVideoTracks: streams
      .flatMap((stream) => stream.getVideoTracks())
      .filter((track) => track.readyState === 'live').length,
    videoTime: video === null ? null : video.currentTime,
    visible: document.visibilityState === 'visible',
    cameraFailureShown: document.querySelector('[data-testid="camera-failure"]') !== null,
  }
}

/** Una muestra, o `null` si la pagina estaba recargandose justo entonces. */
async function takeSample(cdp, page, startedAt, reloads) {
  try {
    const { metrics } = await cdp.send('Performance.getMetrics')
    const metric = (name) => metrics.find((entry) => entry.name === name)?.value ?? 0
    return {
      elapsedMs: Date.now() - startedAt,
      jsHeapUsedBytes: metric('JSHeapUsedSize'),
      processMemoryBytes: await processMemoryBytes(),
      domNodes: metric('Nodes'),
      eventListeners: metric('JSEventListeners'),
      ...(await page.evaluate(readPage)),
      reloads,
    }
  } catch (error) {
    console.warn(`[soak] Muestra perdida (${error.message}); se sigue.`)
    return null
  }
}

function report(sample) {
  console.log(
    `[soak] ${(sample.elapsedMs / 3_600_000).toFixed(2)} h · monton ${(sample.jsHeapUsedBytes / 1e6).toFixed(1)} MB` +
      ` · nodos ${sample.domNodes} · escuchas ${sample.eventListeners} · camaras ${sample.liveVideoTracks}` +
      ` · recargas ${sample.reloads}`,
  )
}

async function main() {
  let browser
  try {
    browser = await chromium.connectOverCDP(cdpUrl)
  } catch (error) {
    console.error(`[soak] No se puede conectar a ${cdpUrl}: ${error.message}`)
    console.error('[soak] Que hacer: adb forward tcp:9222 localabstract:chrome_devtools_remote')
    return 2
  }
  const page = browser
    .contexts()
    .flatMap((context) => context.pages())
    .find((candidate) => candidate.url().includes(urlIncludes))
  if (page === undefined) {
    console.error(`[soak] No hay ninguna pestaña cuya URL contenga ${urlIncludes} en la tablet.`)
    await browser.close()
    return 2
  }

  await page.context().addInitScript(trackCameras)
  // La recarga inicial es la unica que hace este guion: sin ella no se veria la
  // camara que la pagina ya habia abierto antes de conectarse.
  let reloads = -1
  page.on('load', () => {
    reloads += 1
  })
  await page.reload()
  const cdp = await page.context().newCDPSession(page)
  await cdp.send('Performance.enable')

  const samples = []
  const startedAt = Date.now()
  const endsAt = startedAt + hours * 3_600_000
  let stopping = false
  let wakeUp = () => undefined
  process.on('SIGINT', () => {
    stopping = true
    wakeUp()
  })

  console.log(
    `[soak] Midiendo ${page.url()} durante ${hours} h, una muestra cada ${intervalMs / 60_000} min.`,
  )
  while (!stopping && Date.now() < endsAt) {
    const sample = await takeSample(cdp, page, startedAt, Math.max(0, reloads))
    if (sample !== null) {
      samples.push(sample)
      appendFileSync(outFile, `${JSON.stringify(sample)}\n`)
      report(sample)
    }
    await new Promise((resolve) => {
      wakeUp = resolve
      setTimeout(resolve, intervalMs)
    })
  }

  const verdict = soakVerdict(samples)
  writeFileSync(`${outFile}.verdict.json`, `${JSON.stringify(verdict, null, 2)}\n`)
  console.log(
    verdict.passed ? '[soak] PASA' : `[soak] NO PASA:\n  - ${verdict.failures.join('\n  - ')}`,
  )
  await browser.close()
  return verdict.passed ? 0 : 1
}

process.exit(await main())
