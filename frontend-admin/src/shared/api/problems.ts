// Tipos de problema del contrato que el panel trata de forma transversal.
import { isApiError } from '@kronoqr/web-kit/http'

/** `403` que recibe una cuenta con contrasena temporal en cualquier ruta salvo las tres de salida (RF-ID-10). */
export const PASSWORD_CHANGE_REQUIRED_TYPE = 'urn:kronoqr:problem:password-change-required'

/** Si el fallo es el `403` «cambia primero tu contrasena temporal». */
export function isPasswordChangeRequired(error: unknown): boolean {
  return (
    isApiError(error) &&
    error.status === 403 &&
    error.problem?.type === PASSWORD_CHANGE_REQUIRED_TYPE
  )
}

/** `409` de `PATCH /site`: la zona horaria ya quedo fija con el primer fichaje (RN-23). */
export const SITE_TIME_ZONE_LOCKED_TYPE = 'urn:kronoqr:problem:site-time-zone-locked'

/** Si el fallo es el `409` «la zona horaria del centro ya no se puede cambiar». */
export function isSiteTimeZoneLocked(error: unknown): boolean {
  return (
    isApiError(error) && error.status === 409 && error.problem?.type === SITE_TIME_ZONE_LOCKED_TYPE
  )
}
