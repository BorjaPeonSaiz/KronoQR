// Cuentas de gestion (RF-ID-10). Todas las formas salen del contrato.
//
// Los campos `actor_*` (reautenticacion de quien actua) y el `reason` del
// restablecimiento de contrasena los anade la revision de seguridad del 12c; los
// tipos generados los traeran cuando el contrato los declare.
//
// Las contrasenas temporales que devuelven `createManagementAccount` y
// `resetManagementAccountPassword` existen en claro SOLO en esa respuesta: quien
// llame a estas funciones las enseña en el acto y no las guarda en ningun sitio.
import { requestJson } from '@kronoqr/web-kit/http'
import type {
  CreateManagementAccountRequest,
  DeactivateManagementAccountRequest,
  ManagementAccount,
  ManagementAccountCollection,
  ManagementAccountProvisioned,
  ManagementAccountStatus,
  ManagementRole,
  ResetManagementTwoFactorRequest,
  TemporaryPasswordIssued,
} from '@/shared/api/types'
import type { ActorReauth } from './actorReauth'

/** Tamano de pagina del listado: el del contrato por omision, paginado en el servidor. */
export const ACCOUNT_LIST_PER_PAGE = 25

export interface AccountListQuery {
  page: number
  perPage: number
  q?: string
  status?: ManagementAccountStatus
  role?: ManagementRole
}

export function listManagementAccounts(
  query: AccountListQuery,
): Promise<ManagementAccountCollection> {
  return requestJson<ManagementAccountCollection>('/api/v1/management-accounts', {
    query: {
      page: query.page,
      per_page: query.perPage,
      q: query.q,
      status: query.status,
      role: query.role,
    },
  })
}

export function createManagementAccount(
  body: CreateManagementAccountRequest & ActorReauth,
): Promise<ManagementAccountProvisioned> {
  return requestJson<ManagementAccountProvisioned>('/api/v1/management-accounts', {
    method: 'POST',
    body,
  })
}

/** Baja logica: nunca borra. `404` es «no existe o ya estaba de baja». */
export function deactivateManagementAccount(
  uuid: string,
  body: DeactivateManagementAccountRequest,
): Promise<ManagementAccount> {
  return requestJson<ManagementAccount>(`/api/v1/management-accounts/${uuid}/deactivate`, {
    method: 'POST',
    body,
  })
}

export function resetManagementAccountPassword(
  uuid: string,
  body: ResetManagementTwoFactorRequest & ActorReauth,
): Promise<TemporaryPasswordIssued> {
  return requestJson<TemporaryPasswordIssued>(
    `/api/v1/management-accounts/${uuid}/password/reset`,
    { method: 'POST', body },
  )
}

export function resetManagementAccountTwoFactor(
  uuid: string,
  body: ResetManagementTwoFactorRequest & ActorReauth,
): Promise<ManagementAccount> {
  return requestJson<ManagementAccount>(`/api/v1/management-accounts/${uuid}/two-factor/reset`, {
    method: 'POST',
    body,
  })
}
