// Recuperacion de un trozo (chunk) que ya no existe tras una actualizacion.
//
// La logica (reconocer el error, recargar UNA vez, limpiar la marca al navegar
// bien) es comun con el portal y vive en `@kronoqr/web-kit/chunkLoadRecovery`
// (ADR-036). Lo propio del panel es DONDE se guarda la marca anti-bucle: en
// `sessionStorage`, que es por pestaña y muere con ella, justo el alcance de
// «ya he recargado esta pestaña». No es un dato de la persona ni de la
// instalacion. Si `sessionStorage` lanza (modo restringido, cuota), NO se
// recarga: sin marca no se puede garantizar que no haya bucle.
import {
  installChunkLoadRecovery as installShared,
  isChunkLoadError,
  type ChunkReloadMark,
} from '@kronoqr/web-kit/chunkLoadRecovery'
import type { Router } from 'vue-router'

export { isChunkLoadError }

export const CHUNK_RELOAD_MARK = 'kronoqr:admin:chunk-reload'

export type MarkStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>

export interface ChunkRecoveryOptions {
  /** Por defecto `window.sessionStorage` (se lee dentro de try/catch). */
  storage?: MarkStorage
  reload: (target: string) => void
}

function storageMark(resolveStorage: () => MarkStorage): ChunkReloadMark {
  return {
    claim: (target) => {
      try {
        const storage = resolveStorage()

        if (storage.getItem(CHUNK_RELOAD_MARK) !== null) {
          return null
        }

        storage.setItem(CHUNK_RELOAD_MARK, '1')
      } catch {
        return null
      }

      return target
    },
    clear: () => {
      try {
        resolveStorage().removeItem(CHUNK_RELOAD_MARK)
      } catch {
        // Sin almacenamiento no hay marca que limpiar.
      }
    },
  }
}

export function installChunkLoadRecovery(router: Router, options: ChunkRecoveryOptions): void {
  installShared(router, {
    mark: storageMark(() => options.storage ?? window.sessionStorage),
    reload: options.reload,
  })
}
