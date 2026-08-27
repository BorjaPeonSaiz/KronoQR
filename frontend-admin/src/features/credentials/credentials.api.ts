// Credenciales fisicas (RF-QR-04, RF-QR-06, RF-QR-08).
//
// Dos cosas que el contrato deja claras y que este fichero respeta:
//  - **No hay reimpresion** (ADR-034). No existe ningun parametro para forzarla
//    y aqui no se fabrica uno: reponer una tarjeta es revocar → reemitir →
//    imprimir la nueva, tres actos distintos.
//  - Los PDF salen por `requestBlob`. Un `204` de la impresion por lotes NO es
//    un error: es su idempotencia, y significa «no habia nada pendiente».
import type { BinaryDocument } from '@kronoqr/web-kit/http'
import { requestBlob, requestJson } from '@kronoqr/web-kit/http'
import type {
  Credential,
  CredentialStatusBoard,
  IssueCredentialRequest,
  IssuedCredential,
  PrintCredentialBatchRequest,
  RevokeCredentialRequest,
} from '@/shared/api/types'

export interface CredentialBoardQuery {
  siteId?: number
  /** Solo quien todavia no tiene la tarjeta en la mano. */
  pendingOnly?: boolean
}

export function fetchCredentialBoard(query: CredentialBoardQuery): Promise<CredentialStatusBoard> {
  return requestJson<CredentialStatusBoard>('/api/v1/credentials/status', {
    query: { site_id: query.siteId, pending: query.pendingOnly },
  })
}

/** Lo unico binario que sirve este modulo: el PDF de una tarjeta. */
const PDF_ACCEPT = 'application/pdf, application/problem+json'

/** El PDF de una tarjeta. Acuña su QR en el mismo acto: no tiene vuelta atras. */
export function printCredential(uuid: string): Promise<BinaryDocument | null> {
  return requestBlob(`/api/v1/credentials/${uuid}/print`, 'credencial.pdf', {
    method: 'POST',
    accept: PDF_ACCEPT,
  })
}

/** La hoja A4 con todas las pendientes del centro. `null` si no habia ninguna. */
export function printCredentialBatch(
  body: PrintCredentialBatchRequest,
): Promise<BinaryDocument | null> {
  return requestBlob('/api/v1/credentials/print-batch', 'credenciales.pdf', {
    method: 'POST',
    body,
    accept: PDF_ACCEPT,
  })
}

/** Registra la entrega en mano. El responsable lo pone el servidor, no el cliente. */
export function deliverCredential(uuid: string): Promise<Credential> {
  return requestJson<Credential>(`/api/v1/credentials/${uuid}/deliver`, { method: 'POST' })
}

export function issueCredential(body: IssueCredentialRequest): Promise<IssuedCredential> {
  return requestJson<IssuedCredential>('/api/v1/credentials', { method: 'POST', body })
}

export function revokeCredential(uuid: string, body: RevokeCredentialRequest): Promise<Credential> {
  return requestJson<Credential>(`/api/v1/credentials/${uuid}/revoke`, { method: 'POST', body })
}
