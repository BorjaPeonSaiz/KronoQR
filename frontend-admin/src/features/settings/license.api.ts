// La licencia de la instalacion (RF-PD-04, RF-PD-05, tarea 5.3).
//
// Las formas salen del contrato; aqui no se inventa ninguna. Y NO SE VALIDA EL
// FORMATO DE LA CLAVE por cuenta propia: quien decide si vale es la firma
// ed25519 del servidor, y una expresion regular aqui seria una segunda fuente
// de verdad que se desincronizaria el dia que naciera `KQL2` — con el efecto de
// que el panel rechazara una clave legitima que el hotel acaba de pagar.
import { requestJson } from '@kronoqr/web-kit/http'
import type { License } from '@/shared/api/types'

export function fetchLicense(): Promise<License> {
  return requestJson<License>('/api/v1/license')
}

export function activateLicense(signedKey: string): Promise<License> {
  return requestJson<License>('/api/v1/license/activate', {
    method: 'POST',
    // Se manda tal cual, con sus espacios y sus saltos de linea: el servidor los
    // normaliza. Recortarlos aqui tambien funcionaria, pero seria una regla de
    // limpieza duplicada en dos sitios.
    body: { signed_key: signedKey },
  })
}
