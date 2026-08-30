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
  CredentialStatusRow,
  IssueCredentialRequest,
  IssuedCredential,
  PrintCredentialBatchRequest,
  RevokeCredentialRequest,
} from '@/shared/api/types'

export interface CredentialBoardQuery {
  /** Solo quien todavia no tiene la tarjeta en la mano. */
  pendingOnly?: boolean
  /**
   * Acota a una sola persona. La usa la ficha de empleado (RF-PA-03): pide
   * su fila y nada mas, no el tablero entero filtrado en cliente. Cada
   * lectura del tablero audita como divulgado TODO lo que devuelve
   * (ADR-037), y aqui solo hace falta una fila.
   */
  employeeUuid?: string
  /**
   * A quien le falta reimprimir durante una rotacion de clave (RF-QR-07):
   * solo las personas cuya tarjeta EN USO sigue firmada con ese `key_id`.
   *
   * No hace falta teclearlo: el propio `summary` trae `retiring_key_id`
   * cuando hay una rotacion abierta. La rotacion en si **no tiene endpoint**
   * y se ejecuta con `php artisan credentials:rotate-key`; aqui solo se lee.
   */
  keyId?: string
}

export function fetchCredentialBoard(query: CredentialBoardQuery): Promise<CredentialStatusBoard> {
  return requestJson<CredentialStatusBoard>('/api/v1/credentials/status', {
    query: { pending: query.pendingOnly, employee_uuid: query.employeeUuid, key_id: query.keyId },
  })
}

/**
 * La fila de una sola persona del tablero de credenciales, para la seccion
 * «Tarjeta QR» de la ficha de empleado. `null` cuando el servidor no devuelve
 * ninguna fila (caso raro: un empleado sin nada que mostrar todavia).
 *
 * Aunque el contrato garantiza como mucho una fila para un `employee_uuid`
 * dado, `find()` localiza esa fila por `employee_uuid` en vez de asumir la
 * posicion: `data[0]` seria la fila de otra persona el dia que el filtro del
 * servidor dejara de aplicarse.
 */
export async function fetchCredentialStatusFor(
  employeeUuid: string,
): Promise<CredentialStatusRow | null> {
  const board = await fetchCredentialBoard({ employeeUuid })

  return board.data.find((row) => row.employee_uuid === employeeUuid) ?? null
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

/** La hoja A4 con todas las pendientes de la instalacion. `null` si no habia ninguna. */
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
