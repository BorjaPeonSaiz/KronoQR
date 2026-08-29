// Presencia en vivo (RF-PA-01, RF-PA-02): la foto inicial y la firma de la
// suscripcion al canal privado (ADR-011). Las formas salen del contrato; aqui
// no se inventa ninguna.
//
// Solo lectura. Nada de lo que devuelve cambia el registro horario.
import { requestJson } from '@kronoqr/web-kit/http'
import type { LivePresenceBoard, LivePresenceStatus } from '@/shared/api/types'

/** Filtros de RF-PA-02. Los resuelve el servidor, con el alcance del rol dentro de la consulta. */
export interface LivePresenceQuery {
  departmentId?: number
  /** `present` por omision en el servidor: quien esta dentro ahora mismo. */
  status?: LivePresenceStatus
  q?: string
}

export function listLivePresence(query: LivePresenceQuery = {}): Promise<LivePresenceBoard> {
  return requestJson<LivePresenceBoard>('/api/v1/attendance/live', {
    query: {
      department_id: query.departmentId,
      status: query.status,
      // `undefined` no se serializa: una busqueda vacia no es un filtro.
      q: query.q === undefined || query.q === '' ? undefined : query.q,
    },
  })
}

/** Lo que devuelve `auth_endpoint`: la firma que el servidor calcula para ese canal y ese socket. */
export interface ChannelAuthorization {
  auth: string
}

/**
 * Firma de una suscripcion a un canal privado. Se llama con el mismo token
 * Bearer que el resto de la API (`meta.realtime.auth_endpoint` del contrato) y
 * es lo que autoriza de verdad: la lista de canales de `meta.realtime` solo
 * dice a que puede intentar suscribirse esta cuenta (regla dura 18).
 */
export function authorizeChannel(
  authEndpoint: string,
  socketId: string,
  channelName: string,
): Promise<ChannelAuthorization> {
  return requestJson<ChannelAuthorization>(authEndpoint, {
    method: 'POST',
    body: { socket_id: socketId, channel_name: channelName },
  })
}
