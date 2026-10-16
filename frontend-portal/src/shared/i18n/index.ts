// Infraestructura de idiomas (doc 02 §3.5): ningun texto que vea una persona
// se escribe en una plantilla. Espanol e ingles desde el primer dia, y anadir
// un idioma nuevo es anadir un fichero, no tocar componentes.
import { type LocalePolicy, resolveUiLocale } from '@kronoqr/web-kit/branding'
import { createI18n } from 'vue-i18n'
import en from './locales/en.json'
import es from './locales/es.json'

export const SUPPORTED_LOCALES = ['es', 'en'] as const satisfies readonly [string, ...string[]]

export type AppLocale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: AppLocale = 'es'

export function isSupportedLocale(value: unknown): value is AppLocale {
  return typeof value === 'string' && SUPPORTED_LOCALES.some((locale) => locale === value)
}

/**
 * El idioma con el que se pinta el portal. Lo decide `resolveUiLocale` del
 * web-kit con la politica de la marca de la instalacion (RF-PD-08): con un
 * unico idioma ofrecido (`LOCALE_AVAILABLE = [es]`, de serie) es ese y ni el
 * navegador ni `employees.locale` lo cambian; con varios, sigue el de la
 * persona que ha entrado, despues el del navegador y al final el de la
 * instalacion. El portal no tiene selector, asi que no hay eleccion guardada.
 */
export function resolvePortalLocale(inputs: {
  readonly policy: LocalePolicy
  readonly personal?: string | null | undefined
  readonly browser: readonly string[]
}): AppLocale {
  return resolveUiLocale<AppLocale>({
    policy: inputs.policy,
    personal: inputs.personal ?? null,
    browser: inputs.browser,
    shipped: SUPPORTED_LOCALES,
  })
}

export const messages = { es, en }

export function createAppI18n(locale: AppLocale = DEFAULT_LOCALE) {
  return createI18n({
    legacy: false,
    locale,
    fallbackLocale: DEFAULT_LOCALE,
    messages,
  })
}
