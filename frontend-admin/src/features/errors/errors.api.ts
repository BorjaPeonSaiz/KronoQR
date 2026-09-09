// Historico de errores agrupado por huella (RF-PD-15, tarea 5.12): la consulta
// paginada y la resolucion de un grupo. Las formas salen del contrato; aqui no
// se inventa ninguna.
import { requestJson } from '@kronoqr/web-kit/http'
import type { ErrorEvent, ErrorEventCollection, ErrorLevel, ErrorSource } from '@/shared/api/types'

/** `open` por omision en el servidor (contrato): lo pendiente es la pregunta de partida. */
export type ErrorEventStatus = 'open' | 'resolved' | 'all'

/** Filtros de la pantalla, en la forma que usa el panel (camelCase). */
export interface ErrorEventsQuery {
  source?: ErrorSource
  level?: ErrorLevel
  status?: ErrorEventStatus
  /** UTC, incluido. */
  from?: string
  /** UTC, incluido. */
  to?: string
  page?: number
  perPage?: number
}

export function listErrorEvents(query: ErrorEventsQuery = {}): Promise<ErrorEventCollection> {
  return requestJson<ErrorEventCollection>('/api/v1/diagnostics/errors', {
    query: {
      // `undefined` no se serializa: sin filtro no se manda el parametro.
      source: query.source,
      level: query.level,
      status: query.status,
      from: query.from,
      to: query.to,
      page: query.page,
      per_page: query.perPage,
    },
  })
}

/**
 * Da un grupo por resuelto (RF-PD-15). Idempotente (el contrato lo
 * garantiza): resolver uno ya resuelto vuelve a responder `200` con la misma
 * fila, sin segundo efecto.
 *
 * Un acceso de soporte con alcance `diagnostics` o `read_only` recibe `403`
 * aunque pueda leer el historico (decision 8 de la ficha 5.12): dar un fallo
 * por resuelto en la instalacion de un cliente es decision del cliente. La
 * vista traduce ese `403` con `ErrorNotice`, como cualquier otro rechazo del
 * servidor.
 */
export function resolveErrorEvent(id: number): Promise<ErrorEvent> {
  return requestJson<ErrorEvent>(`/api/v1/diagnostics/errors/${id}/resolve`, { method: 'POST' })
}
