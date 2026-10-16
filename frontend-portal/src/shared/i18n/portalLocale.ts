// Ata el idioma que se muestra a la politica de idiomas de la instalacion
// (`branding.locales`, RF-PD-08) y a la persona que ha entrado
// (`employees.locale`). Vive aparte de `main.ts` para poder probarse.
import type { LocalePolicy } from '@kronoqr/web-kit/branding'
import { watch, type WatchHandle } from 'vue'
import type { createAppI18n } from './index'
import { resolvePortalLocale } from './index'

export interface PortalLocaleSource {
  readonly policy: LocalePolicy
  readonly personal: string | null | undefined
}

/**
 * Fija el idioma ahora y lo recalcula cada vez que cambia la politica (llega
 * la marca del servidor) o la persona (entra o sale). Con un unico idioma
 * ofrecido el resultado no depende ni del navegador ni de la persona.
 */
export function bindPortalLocale(
  i18n: ReturnType<typeof createAppI18n>,
  source: () => PortalLocaleSource,
  browser: () => readonly string[] = () => navigator.languages,
): WatchHandle {
  return watch(
    () => {
      const { policy, personal } = source()

      return resolvePortalLocale({ policy, personal, browser: browser() })
    },
    (locale) => {
      i18n.global.locale.value = locale
    },
    { immediate: true, flush: 'sync' },
  )
}
