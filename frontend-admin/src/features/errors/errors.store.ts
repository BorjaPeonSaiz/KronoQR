// Estado del historico de errores (RF-PD-15, tarea 5.12).
//
// Una pagina (`GET /diagnostics/errors`) con los filtros de origen, nivel,
// estado y periodo, y una accion de resolucion que sustituye o retira la fila
// sin volver a pedir la pagina entera (el contrato devuelve el grupo
// completo), igual que `incidents.store.ts`.
//
// EL RELOJ ES EL DEL SERVIDOR (regla dura 3, mismo motivo que
// `incidents.store.ts`): la antiguedad de `last_seen_at` se calcula contra
// `meta.generated_at`, nunca contra `Date.now()` del navegador.
import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'
import type { ErrorEvent, ErrorEventPageMeta } from '@/shared/api/types'
import type { ErrorEventsQuery } from './errors.api'
import { listErrorEvents, resolveErrorEvent } from './errors.api'

export const DEFAULT_PER_PAGE = 25

function defaultFilters(): ErrorEventsQuery {
  return { status: 'open', page: 1, perPage: DEFAULT_PER_PAGE }
}

export const useErrorEventsStore = defineStore('errorEvents', () => {
  const entries = shallowRef<ErrorEvent[]>([])
  const meta = ref<ErrorEventPageMeta | null>(null)
  const filters = ref<ErrorEventsQuery>(defaultFilters())
  const loading = ref(false)
  const error = ref<unknown>(null)
  /** `Date.now()` del navegador en el momento en que llego `meta.generated_at`. */
  const receivedAt = ref(0)

  /** El «ahora» del servidor, extrapolado desde la ultima foto con el reloj monotono local. */
  function serverNowMs(now: number = Date.now()): number {
    if (meta.value === null) {
      return now
    }

    // Nunca hacia atras: el reloj local solo aporta lo transcurrido desde la foto.
    return Date.parse(meta.value.generated_at) + Math.max(now - receivedAt.value, 0)
  }

  async function load(): Promise<void> {
    loading.value = entries.value.length === 0 && meta.value === null
    error.value = null

    try {
      const page = await listErrorEvents(filters.value)

      entries.value = page.data
      meta.value = page.meta
      receivedAt.value = Date.now()
    } catch (caught) {
      error.value = caught
    } finally {
      loading.value = false
    }
  }

  /** Cambia filtros y vuelve a la primera pagina: un filtro nuevo invalida la pagina actual. */
  async function applyFilters(patch: Partial<ErrorEventsQuery>): Promise<void> {
    filters.value = { ...filters.value, ...patch, page: 1 }
    await load()
  }

  async function goToPage(page: number): Promise<void> {
    filters.value = { ...filters.value, page }
    await load()
  }

  /**
   * Aplica el grupo ya resuelto: si el filtro de estado sigue enseñando
   * resueltos (`resolved`/`all`), sustituye la fila; si la vista es la de
   * pendientes (`open`, la de partida), la retira y ajusta los recuentos de
   * cabecera sin volver a pedir la pagina.
   */
  function applyResolved(updated: ErrorEvent): void {
    if (filters.value.status !== 'open') {
      entries.value = entries.value.map((row) => (row.id === updated.id ? updated : row))

      return
    }

    entries.value = entries.value.filter((row) => row.id !== updated.id)

    if (meta.value === null) {
      return
    }

    const total = Math.max(meta.value.total - 1, 0)

    meta.value = {
      ...meta.value,
      total,
      total_pages: Math.max(Math.ceil(total / meta.value.per_page), 1),
      open_errors:
        updated.level === 'error'
          ? Math.max(meta.value.open_errors - 1, 0)
          : meta.value.open_errors,
      open_critical:
        updated.level === 'critical'
          ? Math.max(meta.value.open_critical - 1, 0)
          : meta.value.open_critical,
    }
  }

  async function resolve(id: number): Promise<ErrorEvent> {
    const updated = await resolveErrorEvent(id)

    applyResolved(updated)

    return updated
  }

  return {
    entries,
    meta,
    filters,
    loading,
    error,
    serverNowMs,
    load,
    applyFilters,
    goToPage,
    resolve,
  }
})
