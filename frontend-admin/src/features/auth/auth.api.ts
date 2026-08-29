// Los endpoints de la sesion de gestion (RF-ID-01, RS-06, contrato §/api/v1/auth).
//
// El empleado NO entra por aqui: su portal usa codigo de empleado y PIN
// (ADR-015, regla dura 12). Este panel es solo para personal de gestion.
import { request, requestJson } from '@kronoqr/web-kit/http'
import type {
  LoginRequest,
  ManagementUser,
  Session,
  TwoFactorChallenge,
  TwoFactorEnrolment,
} from '@/shared/api/types'

/**
 * `200` con la sesion ya emitida, o `202` con el reto de segundo factor
 * (RS-06: obligatorio para `admin`, `rrhh` y `auditor`, y para cualquier
 * cuenta que ya lo tenga activo). Las dos formas son deliberadamente distintas
 * —`token` frente a `challenge_token`— para que nada las confunda entre si
 * (ver `docs/api/openapi.yaml`, esquema `TwoFactorChallenge`).
 */
export type LoginOutcome = Session | TwoFactorChallenge

/** Distingue las dos formas de `LoginOutcome` sin arriesgar un `token` a medias. */
export function isTwoFactorChallenge(outcome: LoginOutcome): outcome is TwoFactorChallenge {
  return 'challenge_token' in outcome
}

export function logIn(body: LoginRequest): Promise<LoginOutcome> {
  // `anonymous`: sin cabecera `Authorization` y sin disparar el cierre de sesion
  // ante el 401, que aqui significa «credenciales no validas» y no «caduco».
  return requestJson<LoginOutcome>('/api/v1/auth/login', { method: 'POST', body, anonymous: true })
}

/**
 * Segundo factor ya activo: canjea el reto por la sesion de verdad.
 *
 * Se llama con el `challenge_token` del `202` de `logIn`, nunca con el de la
 * tienda de sesion: esa sesion todavia no existe. `anonymous` evita que un
 * codigo equivocado (`401`) dispare el manejador global de sesion caducada.
 */
export function verifyTwoFactor(challengeToken: string, code: string): Promise<Session> {
  return requestJson<Session>('/api/v1/auth/2fa/verify', {
    method: 'POST',
    body: { code },
    anonymous: true,
    token: challengeToken,
  })
}

/** Genera el secreto TOTP de la cuenta, pendiente de confirmar. */
export function enrolTwoFactor(challengeToken: string): Promise<TwoFactorEnrolment> {
  return requestJson<TwoFactorEnrolment>('/api/v1/auth/2fa/enrol', {
    method: 'POST',
    anonymous: true,
    token: challengeToken,
  })
}

/** Activa el secreto con el primer codigo del autenticador y emite la sesion. */
export function confirmTwoFactor(challengeToken: string, code: string): Promise<Session> {
  return requestJson<Session>('/api/v1/auth/2fa/confirm', {
    method: 'POST',
    body: { code },
    anonymous: true,
    token: challengeToken,
  })
}

export async function logOut(): Promise<void> {
  await request<null>('/api/v1/auth/logout', { method: 'POST' })
}

export function fetchCurrentUser(): Promise<ManagementUser> {
  return requestJson<ManagementUser>('/api/v1/auth/me')
}
