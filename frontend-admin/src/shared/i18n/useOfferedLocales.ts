// Idiomas que la instalacion ofrece (`branding.locales.available`) y que este
// panel trae traducidos. Con uno solo no hay campo de idioma en ningun
// formulario: el servidor aplica `LOCALE_DEFAULT`.
import { offeredLocales } from '@kronoqr/web-kit/branding'
import { computed, type ComputedRef } from 'vue'
import { useBrandingStore } from '../branding/branding.store'
import { DEFAULT_LOCALE, SUPPORTED_LOCALES, type AppLocale } from './index'

export interface OfferedLocales {
  readonly locales: ComputedRef<readonly AppLocale[]>
  /** `true` solo si hay mas de un idioma entre los que elegir. */
  readonly multilingual: ComputedRef<boolean>
  /** El idioma por defecto de la instalacion si se ofrece; si no, el primero ofrecido. */
  readonly defaultLocale: ComputedRef<AppLocale>
}

export function useOfferedLocales(): OfferedLocales {
  const branding = useBrandingStore()
  const locales = computed(() => offeredLocales(branding.current.locales, SUPPORTED_LOCALES))

  const defaultLocale = computed(() => {
    const wanted = branding.current.locales.default
    return locales.value.find((locale) => locale === wanted) ?? locales.value[0] ?? DEFAULT_LOCALE
  })

  return { locales, multilingual: computed(() => locales.value.length > 1), defaultLocale }
}
