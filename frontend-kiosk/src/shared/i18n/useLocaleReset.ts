// El idioma que elige una persona es SUYO, no de la tablet.
//
// La tablet es compartida: quien pulsa «English» lo hace para fichar el, y el
// siguiente empleado no tiene por que encontrarsela en ingles. Cuando la
// confirmacion en pantalla se retira, el idioma vuelve al de serie de la
// instalacion: el mismo que se resolveria al arrancar sin eleccion guardada
// (el del navegador si la instalacion lo ofrece y, si no, su predeterminado).
//
// La eleccion NO se guarda en el dispositivo (`LanguageSelector`): una recarga
// tambien vuelve al de serie.

import type { LocalePolicy } from '@kronoqr/web-kit/branding'
import type { Ref } from 'vue'
import { watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { readCachedLocalePolicy } from '@/shared/branding/useBranding'
import { initialLocale } from '@/shared/i18n'

/** Idioma de serie de la instalacion: sin eleccion guardada, como al arrancar. */
export function installationLocale(policy: LocalePolicy = readCachedLocalePolicy()) {
  return initialLocale(typeof navigator === 'undefined' ? [] : navigator.languages, null, policy)
}

/**
 * Vuelve al idioma de serie cada vez que `confirmation` pasa de «hay una» a «no
 * hay ninguna»: es el final de ese fichaje. Una confirmacion que se sustituye por
 * otra sin pasar por `null` (dos personas seguidas) no cuenta: la pantalla no ha
 * quedado libre.
 */
export function useLocaleResetAfterScan(
  confirmation: Readonly<Ref<unknown>>,
  policy: () => LocalePolicy = readCachedLocalePolicy,
): void {
  const { locale } = useI18n()
  watch(
    () => confirmation.value !== null,
    (present, wasPresent) => {
      if (present || !wasPresent) return
      const next = installationLocale(policy())
      if (locale.value === next) return
      locale.value = next
      if (typeof document !== 'undefined') document.documentElement.lang = next
    },
  )
}
