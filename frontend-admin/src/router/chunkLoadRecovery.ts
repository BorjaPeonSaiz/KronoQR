// Recuperacion de un trozo (chunk) que ya no existe tras una actualizacion.
//
// Las vistas cargan por `import()` y su fichero lleva un hash. Tras
// `update.sh`, una pestaña abierta con la version anterior pide un hash que ya
// no esta: Nginx responde con `index.html` (`try_files`), el `import()` falla y
// el clic no hace nada. La salida es recargar la pagina, que trae el
// `index.html` nuevo, y hacerlo UNA sola vez: si el fallo es otro (red caida,
// servidor roto), recargar en bucle seria peor que el fallo.
//
// La marca contra el bucle vive en `sessionStorage` y no en `localStorage`:
// es por pestaña y muere con ella, que es justo el alcance de «ya he recargado
// esta pestaña». No es un dato de la persona ni de la instalacion. Si
// `sessionStorage` lanza (modo restringido, cuota), NO se recarga: sin marca no
// se puede garantizar que no haya bucle.
import type { Router } from 'vue-router'

export const CHUNK_RELOAD_MARK = 'kronoqr:admin:chunk-reload'

const CHUNK_ERROR_PATTERNS = [
  /Failed to fetch dynamically imported module/i,
  /error loading dynamically imported module/i,
  /Importing a module script failed/i,
  /Unable to preload CSS/i,
]

export function isChunkLoadError(error: unknown): boolean {
  if (!(error instanceof Error)) {
    return false
  }

  return error.name === 'ChunkLoadError' || CHUNK_ERROR_PATTERNS.some((p) => p.test(error.message))
}

export type MarkStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>

export interface ChunkRecoveryOptions {
  /** Por defecto `window.sessionStorage` (se lee dentro de try/catch). */
  storage?: MarkStorage
  reload: (target: string) => void
}

export function installChunkLoadRecovery(router: Router, options: ChunkRecoveryOptions): void {
  const resolveStorage = (): MarkStorage => options.storage ?? window.sessionStorage

  /** Recarga una sola vez; devuelve si lo ha hecho. */
  const recoverOnce = (target: string): boolean => {
    try {
      const storage = resolveStorage()

      if (storage.getItem(CHUNK_RELOAD_MARK) !== null) {
        return false
      }

      storage.setItem(CHUNK_RELOAD_MARK, '1')
    } catch {
      return false
    }

    options.reload(target)

    return true
  }

  router.onError((error, to) => {
    if (isChunkLoadError(error)) {
      recoverOnce(to.fullPath)
    }
  })

  // Los trozos de CSS/JS precargados por Vite avisan por este evento.
  window.addEventListener('vite:preloadError', (event) => {
    if (recoverOnce(router.currentRoute.value.fullPath)) {
      event.preventDefault()
    }
  })

  // Una navegacion correcta demuestra que los trozos cargan: se limpia la
  // marca para poder recuperar de nuevo tras la SIGUIENTE actualizacion.
  router.afterEach((_to, _from, failure) => {
    if (failure) {
      return
    }

    try {
      resolveStorage().removeItem(CHUNK_RELOAD_MARK)
    } catch {
      // Sin almacenamiento no hay marca que limpiar.
    }
  })
}
