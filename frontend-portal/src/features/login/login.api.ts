// El unico endpoint anonimo del portal (RF-ID-06, ADR-015).
//
// Codigo de empleado y PIN, nunca correo ni contrasena (regla dura 12). El
// empleado de gestion NO entra por aqui: eso es `POST /api/v1/auth/login`, del
// panel, que este portal ni siquiera importa.
import { request, requestJsonWithHeaders } from '@kronoqr/web-kit/http'
import type { PortalLoginRequest, PortalSession } from '@/shared/api/types'

/** La sesion y la hora del servidor (cabecera `Date`), `null` si no llega o no se entiende. */
export interface PortalLoginResult {
  readonly session: PortalSession
  readonly serverNow: Date | null
}

export async function logInToPortal(body: PortalLoginRequest): Promise<PortalLoginResult> {
  // `anonymous`: sin cabecera `Authorization` y sin disparar el cierre de
  // sesion ante el 401, que aqui significa «codigo o PIN no validos» y no
  // «la sesion ha caducado».
  const { body: session, headers } = await requestJsonWithHeaders<PortalSession>(
    '/api/v1/me/login',
    { method: 'POST', body, anonymous: true },
  )
  const parsed = new Date(headers.get('Date') ?? '')

  return { session, serverNow: Number.isNaN(parsed.getTime()) ? null : parsed }
}

/**
 * Cierra en el servidor la sesion de portal (`POST /api/v1/me/logout`).
 *
 * El token se pasa explicito y la peticion es `anonymous`: un `401` aqui
 * significa «ya no habia sesion», que es justo lo que se quiere, y no debe
 * disparar el manejador global que cierra y redirige por su cuenta. Sin
 * cuerpo. Quien llama decide que hacer con el resultado: la salida local no
 * depende de el.
 */
export async function logOutOfPortal(token: string, signal: AbortSignal): Promise<void> {
  await request<null>('/api/v1/me/logout', { method: 'POST', anonymous: true, token, signal })
}
