// Traduce la respuesta del servidor (o su ausencia) a lo que se pinta en
// pantalla. Compartida por las dos tuberias que encolan un fichaje —
// `scanPipeline` (QR) y `features/pin/application/pinPipeline` (PIN,
// RF-AT-11) — porque ambas confirman en local ANTES de saber que dira el
// servidor y solo difieren en como llegan hasta aqui (sincrono vs. con un
// sellado previo async).
//
// El caso `rejected` no trae `response`: el servidor no ha podido asociar
// nada a ese `scan_id`, así que no hay un instante suyo que usar. Se pinta el
// instante del INTENTO (el que ya vive en `occurredAt`, capturado al decodificar
// el QR o al pulsar «confirmar» en el PIN), nunca el de la respuesta: es lo que
// la persona vivio delante de la tablet, y es ademas el unico instante
// disponible quando el escaneo viajo en la cola offline y el servidor tardo en
// contestar.

import type { ScanConfirmation } from '../domain/scanOutcome'
import type { ScanSubmissionResult } from './ports'

/**
 * @param result     lo que devolvio (o no) el envio al servidor.
 * @param scanId     el `scan_id` generado al encolar (regla dura 8).
 * @param occurredAt el instante del intento, para la rama `rejected`.
 * @returns la confirmacion a pintar, o `null` si sigue «pendiente» en cola
 *          (`deferred`): la pantalla ya dice eso y no hay que tocarla.
 */
export function settleFrom(
  result: ScanSubmissionResult,
  scanId: string,
  occurredAt: Date,
): ScanConfirmation | null {
  switch (result.kind) {
    case 'accepted':
      return {
        kind: 'accepted',
        scanId,
        occurredAt: new Date(result.response.occurred_at),
        action: result.response.action,
        displayName: result.response.employee_display_name,
        workedMinutes: result.response.worked_minutes,
        workDate: result.response.work_date,
      }
    case 'debounced':
      return {
        kind: 'debounced',
        scanId,
        occurredAt: new Date(result.response.occurred_at),
        displayName: result.response.employee_display_name,
        workedMinutes: result.response.worked_minutes,
        lastAcceptedAt: new Date(result.response.last_accepted_at),
      }
    case 'rejected':
      return { kind: 'rejected', scanId, occurredAt }
    case 'deferred':
      return null
  }
}
