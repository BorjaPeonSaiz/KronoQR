import { vi } from 'vitest'
import { useBrandingStore } from '@/shared/branding/branding.store'
import { jsonResponse } from './harness'

/**
 * Deja en el almacen de la marca una instalacion con esos idiomas
 * (`branding.locales.available`), cargada como lo hace el panel: por
 * `GET /api/v1/branding`. Restaura `fetch` al terminar para que el llamador
 * instale el suyo despues.
 */
export async function loadBrandingWithLocales(available: readonly string[]): Promise<void> {
  vi.stubGlobal('fetch', async () =>
    jsonResponse({
      application_name: 'KronoQR',
      accent_color: null,
      logo_url: null,
      locales: { default: 'es', available },
      privacy_notice: { controller_name: null, policy_url: null },
    }),
  )
  await useBrandingStore().load()
  vi.unstubAllGlobals()
}
