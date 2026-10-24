// Informe comun de las medidas de arranque (`perf-lcp.spec.ts`,
// `perf-scanner-ready.spec.ts`, tarea 17.10).

import { test } from '@playwright/test'

/** La mediana de un numero impar de muestras. */
export function median(values: readonly number[]): number {
  const sorted = [...values].sort((left, right) => left - right)
  return sorted[Math.floor(sorted.length / 2)] ?? Number.POSITIVE_INFINITY
}

/**
 * Avisa, SIN fallar, de cada muestra por encima de `warnMs`: anotacion de la
 * prueba y, en la CI, `::warning`, que GitHub pinta en el resumen del job. Es
 * la senal que faltaba en la 2.2.1 (V21-5-KI-2): una muestra de 1 544 ms
 * superaba el aviso de 1 500 ms y, como solo se juzgaba la mediana, nadie la vio.
 */
export function warnAboutSlowSamples(
  label: string,
  samples: readonly number[],
  warnMs: number,
): void {
  const slow = samples.filter((value) => value > warnMs).map(Math.round)
  if (slow.length === 0) return
  const message = `${label}: ${slow.join(', ')} ms por encima del aviso de ${warnMs} ms`
  test.info().annotations.push({ type: 'warning', description: message })
  console.log(process.env['CI'] === 'true' ? `::warning title=${label}::${message}` : message)
}
