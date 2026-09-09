// Las tres operaciones de RF-PA-04: dar de alta un tramo que nunca se ficho,
// rectificar sus marcas y anularlo. Las tres exigen el ambito
// `attendance:correct` (doc 02 §7.3) y las tres piden un motivo del catalogo
// cerrado del Anexo C (RN-13): sin eso, el servidor responde `422` antes de
// llegar aqui.
//
// Las tres devuelven `CorrectedShiftEntry` entero (RN-06, ADR-035): quien
// llama no compone nada con la respuesta, solo la usa para anunciar el
// resultado y deja que la jornada se vuelva a pedir (`WORKDAYS_QUERY_KEY`,
// `useEmployeeWorkDays.ts`) para ver el total recalculado y el historial de
// correcciones al dia.
import { requestJson } from '@kronoqr/web-kit/http'
import type {
  AddShiftEntryRequest,
  CorrectedShiftEntry,
  CorrectShiftEntryRequest,
  VoidShiftEntryRequest,
} from '@/shared/api/types'

/**
 * `POST /shift-entries` — alta manual de un tramo que nunca se ficho: el
 * olvido de fichaje de entrada, el dia sin tarjeta entregada, la jornada
 * anterior a la puesta en marcha.
 */
export function addShiftEntry(body: AddShiftEntryRequest): Promise<CorrectedShiftEntry> {
  return requestJson<CorrectedShiftEntry>('/api/v1/shift-entries', { method: 'POST', body })
}

/**
 * `PATCH /shift-entries/{uuid}` — rectifica la entrada, la salida o las dos de
 * un tramo VIGENTE. Cubre tambien cerrar un turno que quedo abierto: es la
 * misma peticion, y el servidor decide cual de las dos paso mirando el estado
 * anterior (`action` en la respuesta), no quien llama.
 *
 * El `uuid` que se envia deja de valer en cuanto la peticion tiene exito: la
 * respuesta trae uno nuevo (`shift_entry_uuid`, ADR-035). Repetir el `PATCH`
 * sobre el antiguo responde `409`, no `404`: ese tramo existio y ya no es la
 * version vigente.
 */
export function correctShiftEntry(
  uuid: string,
  body: CorrectShiftEntryRequest,
): Promise<CorrectedShiftEntry> {
  return requestJson<CorrectedShiftEntry>(`/api/v1/shift-entries/${uuid}`, {
    method: 'PATCH',
    body,
  })
}

/**
 * `POST /shift-entries/{uuid}/void` — declara que un tramo no ocurrio (doble
 * escaneo, fichaje de otra persona). No crea una version nueva: la fila queda,
 * con sus marcas, y sale del conjunto vigente (RN-06).
 *
 * Reservado a `rrhh+` en el servidor (`ShiftEntryPolicy::void`), aunque el
 * ambito del token sea el mismo `attendance:correct` que las otras dos: el
 * panel tambien oculta el boton por rol (`canVoidShiftEntry`,
 * `features/auth/abilities.ts`) para no ofrecer una accion que siempre
 * acabaria en `403`.
 */
export function voidShiftEntry(
  uuid: string,
  body: VoidShiftEntryRequest,
): Promise<CorrectedShiftEntry> {
  return requestJson<CorrectedShiftEntry>(`/api/v1/shift-entries/${uuid}/void`, {
    method: 'POST',
    body,
  })
}
