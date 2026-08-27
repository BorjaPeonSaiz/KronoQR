// El unico endpoint anonimo del portal (RF-ID-06, ADR-015).
//
// Codigo de empleado y PIN, nunca correo ni contrasena (regla dura 12). El
// empleado de gestion NO entra por aqui: eso es `POST /api/v1/auth/login`, del
// panel, que este portal ni siquiera importa.
import { requestJson } from '@kronoqr/web-kit/http'
import type { PortalLoginRequest, PortalSession } from '@/shared/api/types'

export function logInToPortal(body: PortalLoginRequest): Promise<PortalSession> {
  // `anonymous`: sin cabecera `Authorization` y sin disparar el cierre de
  // sesion ante el 401, que aqui significa «codigo o PIN no validos» y no
  // «la sesion ha caducado».
  return requestJson<PortalSession>('/api/v1/me/login', { method: 'POST', body, anonymous: true })
}
