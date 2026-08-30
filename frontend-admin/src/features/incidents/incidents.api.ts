// Bandeja de incidencias (RF-PA-05, RF-PR-01): la consulta paginada y la
// resolucion. Las formas salen del contrato; aqui no se inventa ninguna.
//
// El alcance por departamento de RF-ID-03 entra en la consulta del servidor:
// este cliente no filtra nada por su cuenta, solo pasa lo que la persona ha
// pedido.
import { requestJson } from '@kronoqr/web-kit/http'
import type {
  Incident,
  IncidentCollection,
  IncidentSeverity,
  IncidentStatus,
  IncidentType,
  ResolveIncidentRequest,
} from '@/shared/api/types'

/** Filtros de la bandeja, en la forma que usa el panel (camelCase). */
export interface IncidentsQuery {
  /** `open` por omision en el servidor: lo pendiente es la pregunta de partida. */
  status?: IncidentStatus
  type?: IncidentType
  severity?: IncidentSeverity
  departmentId?: number
  /** Identificador **publico** del empleado (`employees.uuid`). */
  employeeUuid?: string
  page?: number
  perPage?: number
}

export function listIncidents(query: IncidentsQuery = {}): Promise<IncidentCollection> {
  return requestJson<IncidentCollection>('/api/v1/incidents', {
    query: {
      // `undefined` no se serializa: sin filtro no se manda el parametro.
      status: query.status,
      type: query.type,
      severity: query.severity,
      department_id: query.departmentId,
      employee_uuid: query.employeeUuid,
      page: query.page,
      per_page: query.perPage,
    },
  })
}

/**
 * Cierra una incidencia, con nota obligatoria (RN-13). El servidor devuelve la
 * incidencia entera ya cerrada: el panel sustituye la fila sin volver a pedir
 * la bandeja.
 *
 * Se resuelve una sola vez: una que ya este `resolved` o `dismissed` responde
 * `409`, y la accion siguiente es releer, no reintentar (lo hace el store).
 */
export function resolveIncident(id: number, payload: ResolveIncidentRequest): Promise<Incident> {
  return requestJson<Incident>(`/api/v1/incidents/${id}/resolve`, {
    method: 'POST',
    body: payload,
  })
}
