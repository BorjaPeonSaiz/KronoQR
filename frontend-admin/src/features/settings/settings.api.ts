// El catalogo de configuracion de la instalacion (RF-PD-01). Las formas salen
// del contrato; aqui no se inventa ninguna.
//
// Vive en `settings/` y no en `onboarding/` aunque hoy solo lo consuma el paso
// de organizacion del asistente (tarea 5.5): es el mismo catalogo que
// gobernara la pantalla de marca de la tarea 5.8, y las dos deben leer y
// escribir por el mismo sitio para no divergir.
import { requestJson } from '@kronoqr/web-kit/http'
import type { InstallationSettings, UpdateSettingsRequest } from '@/shared/api/types'

export function fetchInstallationSettings(): Promise<InstallationSettings> {
  return requestJson<InstallationSettings>('/api/v1/settings')
}

export function updateInstallationSettings(
  changes: UpdateSettingsRequest['settings'],
): Promise<InstallationSettings> {
  return requestJson<InstallationSettings>('/api/v1/settings', {
    method: 'PATCH',
    body: { settings: changes },
  })
}
