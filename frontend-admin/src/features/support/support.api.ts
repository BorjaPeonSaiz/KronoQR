// Paquete de diagnostico y accesos de soporte auditados (RF-PD-09, RF-PD-11,
// ADR-020, regla dura 16).
//
// Las formas salen del contrato; aqui no se inventa ninguna.
import type { BinaryDocument } from '@kronoqr/web-kit/http'
import { isApiError, request, requestBlob, requestJson } from '@kronoqr/web-kit/http'
import type {
  DiagnosticsBundleRequest,
  GrantSupportAccessRequest,
  IssuedSupportGrant,
  SupportGrantCollection,
  SupportGrantUseCollection,
} from '@/shared/api/types'

/**
 * Nombres de reserva si, por lo que sea, la respuesta llegara sin
 * `Content-Disposition`. El servidor SIEMPRE la manda (contrato,
 * `POST /diagnostics/bundle`): esto es solo para no descargar un fichero sin
 * nombre si algun dia deja de hacerlo. El cifrado conserva `.sealed.json`.
 */
export const FALLBACK_BUNDLE_FILENAME = 'kronoqr-diagnostics.json'
export const FALLBACK_SEALED_BUNDLE_FILENAME = 'kronoqr-diagnostics.sealed.json'

/** `Content-Type` del paquete cifrado (`SealedDiagnosticsBundle`). */
export const SEALED_BUNDLE_MEDIA_TYPE = 'application/vnd.kronoqr.diagnostics-sealed+json'

/** `type` del `503` «esta instalacion no tiene la clave publica de soporte» (sin `Retry-After`). */
export const DIAGNOSTICS_SEALING_UNAVAILABLE_TYPE =
  'urn:kronoqr:problem:diagnostics-sealing-unavailable'

/**
 * Si el fallo es el `503` «esta instalacion no puede cifrar el paquete con
 * datos personales». No se arregla esperando: no se reintenta; lo que hay que
 * enviar a soporte es el paquete anonimizado.
 */
export function isDiagnosticsSealingUnavailable(error: unknown): boolean {
  return (
    isApiError(error) &&
    error.status === 503 &&
    error.problem?.type === DIAGNOSTICS_SEALING_UNAVAILABLE_TYPE
  )
}

/**
 * `POST /api/v1/diagnostics/bundle` (RF-PD-09, RL-19): genera el paquete y lo
 * devuelve como documento descargable, con el nombre que trae
 * `Content-Disposition`. El tipo lo decide `include_personal_data`, no
 * `Accept`:
 *
 * - `false`: JSON en claro (`kronoqr-diagnostics-<version>-<UTC>.json`) que la
 *   persona abre y revisa antes de enviarlo.
 * - `true`: sobre cifrado para el fabricante
 *   (`…<UTC>.sealed.json`) que el hotel no puede abrir.
 *
 * Se pide con `requestBlob` y no con `requestJson` **a proposito**: el
 * contenido no se interpreta nunca en el panel (ver `README.md` de esta
 * _feature_), se suelta tal cual llega, intacto.
 */
const DEFAULT_BUNDLE_REQUEST: DiagnosticsBundleRequest = {
  include_personal_data: false,
  period_days: 7,
}

export async function generateDiagnosticsBundle(
  body: DiagnosticsBundleRequest = DEFAULT_BUNDLE_REQUEST,
): Promise<BinaryDocument> {
  const document_ = await requestBlob(
    '/api/v1/diagnostics/bundle',
    body.include_personal_data === true
      ? FALLBACK_SEALED_BUNDLE_FILENAME
      : FALLBACK_BUNDLE_FILENAME,
    {
      method: 'POST',
      body,
      accept: `application/json, ${SEALED_BUNDLE_MEDIA_TYPE}, application/problem+json`,
    },
  )

  if (document_ === null) {
    // El endpoint nunca responde 204: el paquete siempre trae un documento.
    throw new Error('El paquete de diagnóstico ha llegado vacío.')
  }

  return document_
}

/**
 * `GET /api/v1/support/grants` (RF-PD-11): las 100 concesiones mas recientes,
 * activas, caducadas y revocadas. Es la mitad «visible para el cliente» del
 * requisito (ADR-020): nadie tiene que leer `audit_log` para saber si el
 * fabricante entro y cuando.
 */
export function listSupportGrants(): Promise<SupportGrantCollection> {
  return requestJson<SupportGrantCollection>('/api/v1/support/grants')
}

/**
 * `POST /api/v1/support/grants` (RF-PD-11): concede un acceso temporal.
 * **El token de la respuesta se muestra una sola vez** — nunca se vuelve a
 * pedir, y esta funcion no lo guarda en ningun sitio: lo devuelve y la vista
 * decide cuanto vive en memoria.
 */
export function grantSupportAccess(body: GrantSupportAccessRequest): Promise<IssuedSupportGrant> {
  return requestJson<IssuedSupportGrant>('/api/v1/support/grants', { method: 'POST', body })
}

/**
 * `DELETE /api/v1/support/grants/{uuid}` (RF-PD-11): revoca en el acto.
 * Idempotente (el contrato lo garantiza): revocar una concesion ya revocada o
 * ya caducada tambien responde `204`.
 */
export async function revokeSupportAccess(uuid: string): Promise<void> {
  await request<null>(`/api/v1/support/grants/${uuid}`, {
    method: 'DELETE',
  })
}

/** Filas por pagina de la lista de usos de una concesion. */
export const SUPPORT_USES_PER_PAGE = 25

/**
 * `GET /api/v1/support/grants/{uuid}/uses` (RF-PD-11): las ventanas de uso de
 * una concesion, de la mas reciente a la mas antigua. Cada elemento es el
 * inicio de una ventana de 15 minutos, no una peticion.
 */
export function listSupportGrantUses(
  uuid: string,
  page: number,
): Promise<SupportGrantUseCollection> {
  return requestJson<SupportGrantUseCollection>(`/api/v1/support/grants/${uuid}/uses`, {
    query: { page, per_page: SUPPORT_USES_PER_PAGE },
  })
}
