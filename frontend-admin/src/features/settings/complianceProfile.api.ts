// Perfil de cumplimiento del centro (RF-PD-07, regla dura 14).
//
// Las formas salen del contrato; aqui no se inventa ninguna. Y NO SE VALIDA
// NADA por cuenta propia mas alla de lo que el formulario necesita para no
// mandar basura: los limites de cada umbral los declara el servidor y son los
// que acaban en el `422`. Una segunda copia de los maximos en TypeScript es
// como se acaba con un panel que acepta lo que la API rechaza.
import { requestJson } from '@kronoqr/web-kit/http'
import type { ComplianceProfile, UpdateComplianceProfileRequest } from '@/shared/api/types'

export function fetchComplianceProfile(): Promise<ComplianceProfile> {
  return requestJson<ComplianceProfile>('/api/v1/compliance-profile')
}

export function updateComplianceProfile(
  changes: UpdateComplianceProfileRequest,
): Promise<ComplianceProfile> {
  return requestJson<ComplianceProfile>('/api/v1/compliance-profile', {
    method: 'PATCH',
    body: changes,
  })
}
