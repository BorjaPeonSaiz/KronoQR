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

/**
 * Si el navegador pide alguno de los idiomas que trae esta aplicacion.
 *
 * Sirve para decidir quien elige el idioma inicial de una visita anonima
 * (nadie ha entrado todavia, `session.employee` es `null`): si el navegador
 * ya pedia un idioma soportado, esa es una preferencia real de la persona y
 * gana siempre. Si no pedia ninguno, `main.ts` puede ofrecer el
 * `locales.default` de la marca de la instalacion (RF-PD-08) en cuanto
 * llega: la instalacion sabe mejor que un idioma por omision fijo cual es el
 * idioma habitual de su plantilla.
 */
export function browserHasSupportedLocale(candidates: readonly string[]): boolean {
  return candidates.some((candidate) => isSupportedLocale(candidate.split('-')[0]?.toLowerCase()))
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
