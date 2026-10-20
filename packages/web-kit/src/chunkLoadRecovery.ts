// Recuperacion de un trozo (chunk) que ya no existe tras una actualizacion,
// compartida por el panel y el portal (ADR-036).
//
// Las vistas cargan por `import()` y su fichero lleva un hash. Tras
// `update.sh`, una pestaña abierta con la version anterior pide un hash que ya
// no esta: Nginx responde con `index.html` (`try_files`), el `import()` falla y
// el clic no hace nada. La salida es recargar la pagina, que trae el
// `index.html` nuevo, y hacerlo UNA sola vez: si el fallo es otro (red caida,
// servidor roto), recargar en bucle seria peor que el fallo.
//
// **Donde vive la marca anti-bucle lo decide cada aplicacion** (`ChunkReloadMark`):
// este paquete no toca el almacenamiento del navegador (regla `no-browser-storage`).
//  - El panel guarda la marca en `sessionStorage` (excepcion declarada en su lint).
//  - El portal, que solo admite `sessionStorage` para la sesion de la propia
//    persona, usa `createQueryReloadMark`: la marca viaja en la URL de la recarga
//    (`?kq_reloaded=1`) y no se escribe en ninguna parte.
// Si no se puede garantizar la marca, NO se recarga.

type QueryValue = string | null | (string | null)[]

/**
 * Lo que este modulo necesita del router, estructural a proposito: cada
 * aplicacion tiene su propia copia de `vue-router` y los tipos de dos copias no
 * son intercambiables.
 */
export interface RecoveryRouter {
  onError: (handler: (error: unknown, to: { fullPath: string }) => void) => unknown
  afterEach: (guard: (to: unknown, from: unknown, failure?: unknown) => void) => unknown
  currentRoute: {
    value: { fullPath: string; path: string; hash: string; query: Record<string, QueryValue> }
  }
  replace: (to: { path: string; hash: string; query: Record<string, QueryValue> }) => unknown
}

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

/** La marca contra el bucle de recarga. */
export interface ChunkReloadMark {
  /**
   * Intenta marcar la recarga. Devuelve a donde recargar, o `null` si ya se
   * recargo (hay marca) o no se puede garantizar la marca.
   */
  claim: (target: string) => string | null
  /** Una navegacion correcta demuestra que los trozos cargan: se retira la marca. */
  clear: () => void
}

export interface ChunkRecoveryOptions {
  readonly mark: ChunkReloadMark
  readonly reload: (target: string) => void
  readonly window?: Window
}

export function installChunkLoadRecovery(
  router: RecoveryRouter,
  options: ChunkRecoveryOptions,
): void {
  const win = options.window ?? globalThis.window

  /** Recarga una sola vez; devuelve si lo ha hecho. */
  const recoverOnce = (target: string): boolean => {
    const reloadTo = options.mark.claim(target)

    if (reloadTo === null) {
      return false
    }

    options.reload(reloadTo)

    return true
  }

  router.onError((error, to) => {
    if (isChunkLoadError(error)) {
      recoverOnce(to.fullPath)
    }
  })

  // Los trozos de CSS/JS precargados por Vite avisan por este evento.
  win.addEventListener('vite:preloadError', (event) => {
    if (recoverOnce(router.currentRoute.value.fullPath)) {
      event.preventDefault()
    }
  })

  router.afterEach((_to, _from, failure) => {
    if (!failure) {
      options.mark.clear()
    }
  })
}

/** Nombre del parametro de consulta que lleva la marca del portal. */
export const CHUNK_RELOAD_PARAM = 'kq_reloaded'

/**
 * Marca en la URL, sin almacenamiento: la recarga va a `target?kq_reloaded=1` y
 * la pagina que arranca ve el parametro en `location.search`. Si el trozo sigue
 * sin cargar, el parametro ya esta y no se vuelve a recargar. Al navegar con
 * exito el router lo quita de la ruta (`replace`, sin entrada nueva en el
 * historial).
 */
export function createQueryReloadMark(
  router: RecoveryRouter,
  win: Window = globalThis.window,
): ChunkReloadMark {
  const hasMark = (): boolean => new URLSearchParams(win.location.search).has(CHUNK_RELOAD_PARAM)

  return {
    claim: (target) => {
      if (hasMark()) {
        return null
      }

      const url = new URL(target, win.location.origin)

      url.searchParams.set(CHUNK_RELOAD_PARAM, '1')

      return `${url.pathname}${url.search}${url.hash}`
    },
    clear: () => {
      const route = router.currentRoute.value

      if (!(CHUNK_RELOAD_PARAM in route.query)) {
        return
      }

      const query = Object.fromEntries(
        Object.entries(route.query).filter(([name]) => name !== CHUNK_RELOAD_PARAM),
      )

      void router.replace({ path: route.path, query, hash: route.hash })
    },
  }
}
