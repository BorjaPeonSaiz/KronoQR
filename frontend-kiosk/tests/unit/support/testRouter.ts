import type { Router } from 'vue-router'
import { createMemoryHistory, createRouter } from 'vue-router'
import { routes } from '@/router'

/**
 * Router de prueba con las rutas reales y un historial en memoria, para montar
 * una pantalla que usa `useRouter()` o `RouterLink` sin tocar `window.location`
 * (V21-1-KI-1: sin el, cada montaje emitia `injection "Symbol(router)" not found`).
 */
export function createTestRouter(): Router {
  return createRouter({ history: createMemoryHistory(), routes })
}
