// Paquete de diagnostico y accesos de soporte auditados (RF-PD-09, RF-PD-11,
// ADR-020, regla dura 16).
//
// Las formas salen del contrato; aqui no se inventa ninguna.
import type { BinaryDocument } from '@kronoqr/web-kit/http'
import { request, requestBlob, requestJson } from '@kronoqr/web-kit/http'
import type {
  DiagnosticsBundleRequest,
  GrantSupportAccessRequest,
  IssuedSupportGrant,
  SupportGrantCollection,
} from '@/shared/api/types'

/**
 * Nombre de reserva si, por lo que sea, la respuesta llegara sin
 * `Content-Disposition`. El servidor SIEMPRE la manda (contrato,
 * `POST /diagnostics/bundle`): esto es solo para no descargar un fichero sin
 * nombre si algun dia deja de hacerlo.
 */
const FALLBACK_BUNDLE_FILENAME = 'kronoqr-diagnostics.json'

/**
 * `POST /api/v1/diagnostics/bundle` (RF-PD-09): genera el paquete y lo
 * devuelve como documento descargable, con el nombre que trae
 * `Content-Disposition` (`kronoqr-diagnostics-<version>-<UTC>.json`).
 *
 * Se pide con `requestBlob` y no con `requestJson` **a proposito**: el
 * contenido no se interpreta nunca en el panel (ver `README.md` de esta
 * _feature_), asi que no hace falta parsear el JSON aqui — con soltarlo tal
 * cual llega, intacto, basta para que la persona lo revise antes de enviarlo
 * y para que el `sha256` del `manifest` seguido cuadre.
 */
const DEFAULT_BUNDLE_REQUEST: DiagnosticsBundleRequest = {
  include_personal_data: false,
  period_days: 7,
}

export async function generateDiagnosticsBundle(
  body: DiagnosticsBundleRequest = DEFAULT_BUNDLE_REQUEST,
): Promise<BinaryDocument> {
  const document_ = await requestBlob('/api/v1/diagnostics/bundle', FALLBACK_BUNDLE_FILENAME, {
    method: 'POST',
    body,
    accept: 'application/json, application/problem+json',
  })

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
