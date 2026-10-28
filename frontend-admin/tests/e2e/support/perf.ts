// Lo comun de la suite nocturna de rendimiento del panel (`perf-*.spec.ts`,
// proyecto `admin-perf`, D4 de la 2.3.0): muestras en frio, mediana y aviso.
import { test } from '@playwright/test'
import type { Browser, Page, TestInfo } from '@playwright/test'
import { throttleCpu } from './largeTables'

/** Muestras por medida. Impar, para que la mediana sea un valor medido. */
export const SAMPLES = 3

/** La mediana de un numero impar de muestras. */
export function median(values: readonly number[]): number {
  const sorted = [...values].sort((a, b) => a - b)

  return sorted[Math.floor(sorted.length / 2)] ?? Number.POSITIVE_INFINITY
}

/**
 * Una pagina en un contexto de navegador nuevo, con la CPU a ×4: cada muestra
 * empieza en frio, sin cache ni estado de la anterior, como `perf-lcp.spec.ts`
 * del quiosco. Las opciones del contexto son las del proyecto, que un
 * `browser.newContext()` no hereda solo. Quien la pide cierra su contexto.
 */
export async function coldPage(browser: Browser, info: TestInfo): Promise<Page> {
  const { baseURL, viewport, locale, timezoneId } = info.project.use
  const context = await browser.newContext({
    ...(baseURL === undefined ? {} : { baseURL }),
    ...(viewport === undefined ? {} : { viewport }),
    ...(locale === undefined ? {} : { locale }),
    ...(timezoneId === undefined ? {} : { timezoneId }),
  })
  const page = await context.newPage()

  await throttleCpu(page)

  return page
}

/**
 * Juzga un OBJETIVO INTERNO sin fallar: si la mediana pasa del umbral, lo dice
 * con la cifra y el umbral en una anotacion de la prueba y, en la CI, con un
 * `::warning` que GitHub pinta en el resumen del job. Es para lo que el
 * producto no promete (doc 02 §10.1): lo que se promete bloquea con `expect`.
 */
export function warnAboveTarget(label: string, medianMs: number, targetMs: number): void {
  if (medianMs < targetMs) {
    return
  }

  const message = `${label}: mediana ${Math.round(medianMs)} ms, objetivo interno < ${targetMs} ms`

  test.info().annotations.push({ type: 'warning', description: message })
  console.log(process.env['CI'] === 'true' ? `::warning title=${label}::${message}` : message)
}
