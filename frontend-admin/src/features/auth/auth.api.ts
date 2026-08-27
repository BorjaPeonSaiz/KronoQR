// Los tres endpoints de la sesion de gestion (RF-ID-01, contrato §/api/v1/auth).
//
// El empleado NO entra por aqui: su portal usa codigo de empleado y PIN
// (ADR-015, regla dura 12). Este panel es solo para personal de gestion.
import { request, requestJson } from '@kronoqr/web-kit/http'
import type { LoginRequest, ManagementUser, Session } from '@/shared/api/types'

export function logIn(body: LoginRequest): Promise<Session> {
  // `anonymous`: sin cabecera `Authorization` y sin disparar el cierre de sesion
  // ante el 401, que aqui significa «credenciales no validas» y no «caduco».
  return requestJson<Session>('/api/v1/auth/login', { method: 'POST', body, anonymous: true })
}

export async function logOut(): Promise<void> {
  await request<null>('/api/v1/auth/logout', { method: 'POST' })
}

export function fetchCurrentUser(): Promise<ManagementUser> {
  return requestJson<ManagementUser>('/api/v1/auth/me')
}
