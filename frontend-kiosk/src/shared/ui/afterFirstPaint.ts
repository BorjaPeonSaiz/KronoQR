// Ejecuta `task` una sola vez cuando el navegador ya ha pintado el primer
// fotograma (Anexo A del doc 02: LCP <= 2 s con CPU x4 y 3G rapida).
//
// Todo lo que NO hace falta para que el empleado vea la pantalla (abrir la
// camara, descargar y compilar el decodificador, arrancar libsodium) espera a
// este punto. El doble `requestAnimationFrame` + `setTimeout(0)` es el patron
// habitual para «despues del siguiente pintado». El temporizador de respaldo
// existe para que la tarea corra SIEMPRE: una pestana en segundo plano no
// recibe `requestAnimationFrame`, y el quiosco nunca debe quedarse sin camara
// por eso (regla dura 19).

/** Techo de espera: si no ha habido pintado en este tiempo, se ejecuta igual. */
export const FIRST_PAINT_FALLBACK_MS = 250

export function afterFirstPaint(task: () => void): () => void {
  let done = false
  let fallback: ReturnType<typeof setTimeout> | null = null
  let cancelled = false

  const run = (): void => {
    if (done || cancelled) return
    done = true
    if (fallback !== null) clearTimeout(fallback)
    task()
  }

  fallback = setTimeout(run, FIRST_PAINT_FALLBACK_MS)
  if (typeof requestAnimationFrame === 'function') {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        setTimeout(run, 0)
      })
    })
  }

  return () => {
    cancelled = true
    if (fallback !== null) clearTimeout(fallback)
  }
}

/**
 * Como `afterFirstPaint`, pero para trabajo pesado y prescindible (libsodium):
 * espera ademas a un hueco del hilo principal.
 */
export function whenIdleAfterPaint(task: () => void, timeoutMs = 3000): () => void {
  let cancelIdle: (() => void) | null = null
  const cancelPaint = afterFirstPaint(() => {
    if (typeof requestIdleCallback === 'function') {
      const handle = requestIdleCallback(task, { timeout: timeoutMs })
      cancelIdle = () => cancelIdleCallback(handle)
    } else {
      const handle = setTimeout(task, 0)
      cancelIdle = () => clearTimeout(handle)
    }
  })
  return () => {
    cancelPaint()
    cancelIdle?.()
  }
}
