// Infraestructura de idiomas (doc 02 §3.5): ningun texto que vea una persona
// se escribe en una plantilla. Espanol e ingles desde el primer dia, y anadir
// un idioma nuevo es anadir un fichero, no tocar componentes.
import { createI18n } from 'vue-i18n'
import en from './locales/en.json'
import es from './locales/es.json'

export const SUPPORTED_LOCALES = ['es', 'en'] as const

export type AppLocale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: AppLocale = 'es'

export function isSupportedLocale(value: unknown): value is AppLocale {
  return typeof value === 'string' && SUPPORTED_LOCALES.some((locale) => locale === value)
}

/**
 * Elige el primer idioma soportado de la lista de preferencias del navegador.
 * Acepta etiquetas completas ('es-ES') y cae al idioma por defecto si ninguna
 * encaja: el quiosco nunca se queda sin textos.
 */
export function resolveLocale(candidates: readonly string[]): AppLocale {
  for (const candidate of candidates) {
    const base = candidate.split('-')[0]?.toLowerCase()
    if (isSupportedLocale(base)) {
      return base
    }
  }

  return DEFAULT_LOCALE
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
