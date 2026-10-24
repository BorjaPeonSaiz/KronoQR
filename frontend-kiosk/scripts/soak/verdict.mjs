// KronoQR — veredicto de la prueba de resistencia de 12 h del quiosco (doc 02,
// Anexo A; tarea 17.10; R5-KI-02).
//
// Funcion pura sobre las muestras que toma `scripts/soak-monitor.mjs`, separada
// para que la pruebe Vitest (`tests/unit/soakVerdict.spec.ts`): una prueba de
// 12 h en la tablet real que da un veredicto equivocado son 12 h perdidas.
//
// LOS CRITERIOS (doc 02, Anexo A: «Memoria en marcha 12 h: <= 250 MB, sin
// crecimiento sostenido»; doc 03 §4.3: «una fuga aqui tumba la tablet a media
// tarde»):
//
//   1. Memoria: ninguna muestra por encima de 250 MB (decimales: 250 000 000
//      bytes, el mas estricto de los dos).
//   2. Sin crecimiento sostenido: tras la primera hora (calentamiento: caches,
//      padron, fuentes), la pendiente por minimos cuadrados de la memoria no
//      pasa de 2 MB por hora (22 MB en las 11 h restantes), y la de los nodos
//      del DOM, de 100 por hora.
//   3. Escuchas estables: tras la primera hora, el numero de escuchas de eventos
//      no varia en mas de 20.
//   4. Una sola camara: en ninguna muestra hay mas de una pista de video viva, y
//      con la pagina visible y sin aviso de camara hay al menos una.
//   5. La camara no se congela: con la pagina visible, el `currentTime` del
//      video avanza entre dos muestras seguidas.
//   6. Sin bucle de recargas: como mucho 3 recargas (una actualizacion urgente
//      legitima recarga una vez; 3 es el corte de `minimumVersion.ts`).

/** @typedef {import('./verdict.mjs').SoakSample} SoakSample */
/** @typedef {import('./verdict.mjs').SoakVerdict} SoakVerdict */

export const SOAK_MAX_MEMORY_BYTES = 250_000_000
export const SOAK_WARM_UP_MS = 60 * 60_000
export const SOAK_MAX_MEMORY_SLOPE_BYTES_PER_HOUR = 2_000_000
export const SOAK_MAX_NODES_SLOPE_PER_HOUR = 100
export const SOAK_MAX_LISTENER_SPREAD = 20
export const SOAK_MAX_RELOADS = 3

const HOUR_MS = 60 * 60_000

/**
 * Pendiente por minimos cuadrados de `value` frente a `elapsedMs`, en unidades
 * por hora. `0` con menos de dos puntos o sin dispersion temporal.
 *
 * @param {readonly { elapsedMs: number, value: number }[]} points
 * @returns {number}
 */
export function slopePerHour(points) {
  if (points.length < 2) return 0
  const meanX = points.reduce((sum, point) => sum + point.elapsedMs, 0) / points.length
  const meanY = points.reduce((sum, point) => sum + point.value, 0) / points.length
  let numerator = 0
  let denominator = 0
  for (const point of points) {
    numerator += (point.elapsedMs - meanX) * (point.value - meanY)
    denominator += (point.elapsedMs - meanX) ** 2
  }
  return denominator === 0 ? 0 : (numerator / denominator) * HOUR_MS
}

/**
 * @param {SoakSample} sample
 * @returns {number}
 */
function memoryOf(sample) {
  return sample.processMemoryBytes ?? sample.jsHeapUsedBytes
}

/**
 * @param {readonly SoakSample[]} samples
 * @returns {SoakVerdict}
 */
export function soakVerdict(samples) {
  /** @type {string[]} */
  const failures = []
  if (samples.length === 0) {
    return { passed: false, failures: ['no hay muestras'], summary: null }
  }

  const peakMemory = Math.max(...samples.map(memoryOf))
  if (peakMemory > SOAK_MAX_MEMORY_BYTES) {
    failures.push(`memoria maxima ${Math.round(peakMemory / 1e6)} MB > 250 MB`)
  }

  const settled = samples.filter((sample) => sample.elapsedMs >= SOAK_WARM_UP_MS)
  const memorySlope = slopePerHour(
    settled.map((sample) => ({ elapsedMs: sample.elapsedMs, value: memoryOf(sample) })),
  )
  if (memorySlope > SOAK_MAX_MEMORY_SLOPE_BYTES_PER_HOUR) {
    failures.push(`la memoria crece ${(memorySlope / 1e6).toFixed(1)} MB/h tras la primera hora`)
  }
  const nodesSlope = slopePerHour(
    settled.map((sample) => ({ elapsedMs: sample.elapsedMs, value: sample.domNodes })),
  )
  if (nodesSlope > SOAK_MAX_NODES_SLOPE_PER_HOUR) {
    failures.push(`los nodos del DOM crecen ${Math.round(nodesSlope)}/h tras la primera hora`)
  }
  const listeners = settled.map((sample) => sample.eventListeners)
  const listenerSpread =
    listeners.length === 0 ? 0 : Math.max(...listeners) - Math.min(...listeners)
  if (listenerSpread > SOAK_MAX_LISTENER_SPREAD) {
    failures.push(`las escuchas varian en ${listenerSpread} tras la primera hora`)
  }

  const extraCameras = samples.filter((sample) => sample.liveVideoTracks > 1)
  if (extraCameras.length > 0) {
    failures.push(`${extraCameras.length} muestras con mas de una pista de video viva`)
  }
  const visibleWithoutCamera = samples.filter(
    (sample) => sample.visible && !sample.cameraFailureShown && sample.liveVideoTracks === 0,
  )
  if (visibleWithoutCamera.length > 0) {
    failures.push(`${visibleWithoutCamera.length} muestras visibles sin la camara abierta`)
  }
  const cameraFailures = samples.filter((sample) => sample.cameraFailureShown)
  if (cameraFailures.length > 0) {
    failures.push(`${cameraFailures.length} muestras con el aviso de camara en pantalla`)
  }

  let frozen = 0
  for (let index = 1; index < samples.length; index += 1) {
    const before = samples[index - 1]
    const after = samples[index]
    if (before === undefined || after === undefined) continue
    if (!before.visible || !after.visible) continue
    if (before.reloads !== after.reloads) continue
    if (before.videoTime === null || after.videoTime === null) continue
    if (after.videoTime <= before.videoTime) frozen += 1
  }
  if (frozen > 0) failures.push(`la camara se quedo congelada en ${frozen} intervalos`)

  const reloads = samples.at(-1)?.reloads ?? 0
  if (reloads > SOAK_MAX_RELOADS) failures.push(`${reloads} recargas: bucle de actualizacion`)

  return {
    passed: failures.length === 0,
    failures,
    summary: {
      samples: samples.length,
      hours: (samples.at(-1)?.elapsedMs ?? 0) / HOUR_MS,
      peakMemoryBytes: peakMemory,
      memorySlopeBytesPerHour: memorySlope,
      nodesSlopePerHour: nodesSlope,
      listenerSpread,
      reloads,
    },
  }
}
