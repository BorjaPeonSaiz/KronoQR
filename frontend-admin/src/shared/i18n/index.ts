// Infraestructura de idiomas (doc 02 §3.5): ningun texto que vea una persona
// se escribe en una plantilla. Espanol e ingles desde el primer dia, y anadir
// un idioma nuevo es anadir un fichero, no tocar componentes.
import { resolveUiLocale, type LocalePolicy } from '@kronoqr/web-kit/branding'
import { createI18n } from 'vue-i18n'
import en from './locales/en.json'
import es from './locales/es.json'

export const SUPPORTED_LOCALES = ['es', 'en'] as const satisfies readonly [string, ...string[]]

export type AppLocale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: AppLocale = 'es'

export function isSupportedLocale(value: unknown): value is AppLocale {
  return typeof value === 'string' && SUPPORTED_LOCALES.some((locale) => locale === value)
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

/**
 * Idioma de la interfaz segun la politica de la instalacion: con un solo
 * idioma ofrecido, ese; con varios, el de la persona, el del navegador o el
 * por defecto de la instalacion (`resolveUiLocale`, bloque 26).
 */
export function resolveAppLocale(inputs: {
  readonly policy: LocalePolicy
  readonly personal?: string | null
  readonly browser: readonly string[]
}): AppLocale {
  return resolveUiLocale<AppLocale>({ ...inputs, shipped: SUPPORTED_LOCALES })
}
