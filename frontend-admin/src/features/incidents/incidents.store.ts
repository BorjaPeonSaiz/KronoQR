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

/**
 * Que peticion fallo, para decir que datos se estan viendo:
 *  - `refresh`: releer lo mismo (boton, tras un conflicto); las filas siguen
 *    siendo las de la ultima lectura buena y se enseñan con su hora.
 *  - `filter`: cambio de filtro o de pagina; las filas que hubiera eran de OTRO
 *    conjunto y se retiran: enseñarlas bajo el filtro nuevo seria mentir.
 */
export type IncidentsFailureKind = 'refresh' | 'filter'

export interface IncidentsFailure {
  kind: IncidentsFailureKind
  error: unknown
}

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
  /** Ultima lectura fallida con la bandeja ya pintada; se limpia al leer bien. */
  const refreshFailure = ref<IncidentsFailure | null>(null)
  /** Hay una lectura en vuelo con datos ya en pantalla (para el estado del boton). */
  const refreshing = ref(false)
  /** Numero de la ultima peticion: una respuesta tardia de una anterior no pisa a la nueva. */
  let loadSequence = 0
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

  async function load(kind: IncidentsFailureKind = 'refresh'): Promise<void> {
    const firstLoad = entries.value.length === 0 && meta.value === null

    loading.value = firstLoad
    refreshing.value = !firstLoad
    error.value = null
    loadSequence += 1
    const sequence = loadSequence

    try {
      const page = await listIncidents(filters.value)

      if (sequence !== loadSequence) {
        return
      }

      entries.value = page.data
      meta.value = page.meta
      receivedAt.value = Date.now()
      refreshFailure.value = null
    } catch (caught) {
      if (sequence !== loadSequence) {
        return
      }

      error.value = caught
      // Un fallo de filtro sigue siendo de filtro hasta que una lectura buena lo
      // limpie: pulsar «Actualizar» y fallar otra vez no devuelve las filas viejas.
      const failureKind = refreshFailure.value?.kind === 'filter' ? 'filter' : kind

      refreshFailure.value = { kind: failureKind, error: caught }

      if (failureKind === 'filter') {
        entries.value = []
      }
    } finally {
      if (sequence === loadSequence) {
        loading.value = false
        refreshing.value = false
      }
    }
  }

  /** Cambia filtros y vuelve a la primera pagina: un filtro nuevo invalida la pagina actual. */
  async function applyFilters(patch: Partial<IncidentsQuery>): Promise<void> {
    filters.value = { ...filters.value, ...patch, page: 1 }
    await load('filter')
  }

  async function goToPage(page: number): Promise<void> {
    filters.value = { ...filters.value, page }
    await load('filter')
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
    // Sin persona (`employee` nulo) no hay ficha donde buscar: se relee la bandeja sin filtro de persona.
    for (const status of ['resolved', 'dismissed'] as const) {
      const page = await listIncidents({
        ...(row.employee === null ? {} : { employeeUuid: row.employee.uuid }),
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
   * la bandeja (regla general del panel para las escrituras que devuelven el
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
    refreshFailure,
    refreshing,
    serverNowMs,
    load,
    applyFilters,
    goToPage,
    resolve,
    clearConflict,
  }
})
