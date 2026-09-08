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

/**
 * El valor de una clave de tipo `text` dentro del catalogo ya cargado, o
 * cadena vacia si no esta o no es una cadena.
 *
 * Compartida por el paso de organizacion del asistente (tarea 5.5) y la
 * pantalla de marca (tarea 5.8): las dos leen el mismo catalogo por el mismo
 * sitio para no divergir, como ya avisa la cabecera de este fichero.
 */
export function stringValue(catalog: InstallationSettings, key: string): string {
  const found = catalog.data.find((entry) => entry.key === key)

  return typeof found?.value === 'string' ? found.value : ''
}
