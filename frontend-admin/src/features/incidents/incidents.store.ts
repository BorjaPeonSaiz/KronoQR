// Estado de la bandeja de incidencias (RF-PA-05, RF-PR-01).
//
// Una pagina (`GET /incidents`) con los filtros que resuelve el servidor
// -incluido el alcance por departamento de RF-ID-03, que entra en la consulta y
// no se filtra aqui- y una accion de cierre que sustituye la fila sin volver a
// pedir la bandeja entera (el contrato devuelve la incidencia completa).
//
// EL RELOJ ES EL DEL SERVIDOR (regla dura 3, mismo motivo que
// `features/live/presence.store.ts`): la antiguedad de cada fila se calcula
// contra `meta.generated_at`, nunca contra `Date.now()` del navegador.
//
// UNA INCIDENCIA SE RESUELVE UNA SOLA VEZ. Si dos personas la trabajan a la
// vez, la segunda peticion recibe `409` y la accion correcta no es reintentar:
// es averiguar quien la cerro y refrescar la bandeja. Como el `409` no trae esa
// informacion (es un `Problem` generico), se busca la incidencia ya cerrada
// con el mismo filtro por empleado que usa la ficha de una persona.
import { isApiError } from '@kronoqr/web-kit/http'
import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'
import type { Incident, IncidentPageMeta, ResolveIncidentRequest } from '@/shared/api/types'
import type { IncidentsQuery } from './incidents.api'
import { listIncidents, resolveIncident } from './incidents.api'

/** Lo que se sabe de un conflicto de resolucion: quien se adelanto, si se pudo averiguar. */
export interface ResolutionConflict {
  /** `null` cuando la incidencia ya no aparece ni entre las resueltas ni entre las descartadas de esa persona: un caso raro, pero la interfaz no puede inventarse un nombre. */
  incident: Incident | null
}

export const DEFAULT_PER_PAGE = 25

function defaultFilters(): IncidentsQuery {
  return { status: 'open', page: 1, perPage: DEFAULT_PER_PAGE }
}

export const useIncidentsStore = defineStore('incidents', () => {
  const entries = shallowRef<Incident[]>([])
  const meta = ref<IncidentPageMeta | null>(null)
  const filters = ref<IncidentsQuery>(defaultFilters())
  const loading = ref(false)
  const error = ref<unknown>(null)
  const conflict = ref<ResolutionConflict | null>(null)
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
      const page = await listIncidents(filters.value)

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
  async function applyFilters(patch: Partial<IncidentsQuery>): Promise<void> {
    filters.value = { ...filters.value, ...patch, page: 1 }
    await load()
  }

  async function goToPage(page: number): Promise<void> {
    filters.value = { ...filters.value, page }
    await load()
  }

  /** Quita la fila cerrada de la bandeja y ajusta el recuento, sin volver a pedir la pagina. */
  function removeResolved(id: number): void {
    entries.value = entries.value.filter((item) => item.id !== id)

    if (meta.value !== null) {
      const total = Math.max(meta.value.total - 1, 0)

      meta.value = {
        ...meta.value,
        total,
        total_pages: Math.max(Math.ceil(total / meta.value.per_page), 1),
      }
    }
  }

  /**
   * Busca quien cerro una incidencia que ya no se puede resolver (`409`).
   *
   * El propio `409` no trae esa informacion: es un `Problem` generico (RFC
   * 9457) sin cuerpo de dominio. Se releen las incidencias resueltas y
   * descartadas de esa persona -el mismo filtro que usa la ficha de empleado- y
   * se busca la fila por identificador. Dos peticiones como mucho, y solo
   * cuando de verdad hay un conflicto que explicar.
   */
  async function lookupConflict(row: Incident): Promise<Incident | null> {
    for (const status of ['resolved', 'dismissed'] as const) {
      const page = await listIncidents({
        employeeUuid: row.employee.uuid,
        status,
        perPage: 100,
      })
      const found = page.data.find((item) => item.id === row.id)

      if (found !== undefined) {
        return found
      }
    }

    return null
  }

  /**
   * Cierra una incidencia. Al confirmar, sustituye la fila sin volver a pedir
   * la bandeja (regla general de la 2.4 para las escrituras que devuelven el
   * recurso entero).
   *
   * En un `409` -alguien se adelanto- deja el conflicto en `conflict` para que
   * la pantalla diga quien fue, y refresca la bandeja: la accion siguiente es
   * releer, nunca reintentar la misma peticion.
   */
  async function resolve(id: number, payload: ResolveIncidentRequest): Promise<Incident> {
    conflict.value = null
    const before = entries.value.find((item) => item.id === id) ?? null

    try {
      const updated = await resolveIncident(id, payload)

      removeResolved(updated.id)

      return updated
    } catch (caught) {
      if (isApiError(caught) && caught.status === 409 && before !== null) {
        conflict.value = { incident: await lookupConflict(before) }
        await load()
      }

      throw caught
    }
  }

  function clearConflict(): void {
    conflict.value = null
  }

  return {
    entries,
    meta,
    filters,
    loading,
    error,
    conflict,
    serverNowMs,
    load,
    applyFilters,
    goToPage,
    resolve,
    clearConflict,
  }
})
