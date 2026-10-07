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
