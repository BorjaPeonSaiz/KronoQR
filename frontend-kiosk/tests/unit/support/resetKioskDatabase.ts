import { DATABASE_NAME } from '@/features/offline/infrastructure/dexieStorage'

/**
 * Borra la base de IndexedDB del quiosco (con `fake-indexeddb`) para que una
 * prueba no herede las filas de la anterior. Hay que cerrar antes la cola
 * (`disposeOfflineQueue()`): una base abierta bloquea el borrado.
 */
export function resetKioskDatabase(): Promise<void> {
  return new Promise((resolve) => {
    const request = indexedDB.deleteDatabase(DATABASE_NAME)
    request.onsuccess = (): void => resolve()
    request.onerror = (): void => resolve()
    request.onblocked = (): void => resolve()
  })
}
