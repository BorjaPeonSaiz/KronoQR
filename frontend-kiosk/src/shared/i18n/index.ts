// Infraestructura de idiomas (RF-KI-05, doc 02 §3.5): ningun texto que vea una
// persona se escribe en una plantilla. Espanol e ingles desde el primer dia, y
// anadir un idioma nuevo es anadir un fichero, no tocar componentes.
//
// SELECTOR PERSISTENTE Y DETECCION AUTOMATICA, solo si la instalacion ofrece
// mas de un idioma; entonces, en este orden de prioridad:
//   1. lo que alguien eligio a mano en esta tablet,
//   2. lo que dice el navegador,
//   3. el idioma por defecto de la instalacion.
// Con uno solo (lo de serie: espanol) no hay eleccion ni deteccion.
//
// Se guarda en `localStorage` a proposito, y no contradice la prohibicion de
// usarlo para la cola: son dos caracteres cuya perdida no tiene ninguna
// consecuencia. La cola es registro legal sin escribir y va a IndexedDB.

import { resolveUiLocale, type LocalePolicy } from '@kronoqr/web-kit/branding'
import { createI18n } from 'vue-i18n'
import { readCachedLocalePolicy } from '@/shared/branding/useBranding'
import en from './locales/en.json'
import es from './locales/es.json'

export const SUPPORTED_LOCALES = ['es', 'en'] as const satisfies readonly [string, ...string[]]

export type AppLocale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: AppLocale = 'es'

const LOCALE_STORAGE_KEY = 'kronoqr.kiosk.locale'

/**
 * Hasta la 2.2.x la eleccion de un empleado se guardaba aqui y se quedaba para toda
 * la tablet. Ya no se guarda: se retira lo que dejaron versiones
 * anteriores, o una tablet actualizada seguiria arrancando en el idioma de la
 * ultima persona que lo cambio.
 */
export function clearStoredLocale(storage: Storage | null = safeStorage()): void {
  try {
    storage?.removeItem(LOCALE_STORAGE_KEY)
  } catch {
    // Sin almacenamiento no hay nada que retirar.
  }
}

export function isSupportedLocale(value: unknown): value is AppLocale {
  return typeof value === 'string' && SUPPORTED_LOCALES.some((locale) => locale === value)
}

export function readStoredLocale(storage: Storage | null = safeStorage()): AppLocale | null {
  if (storage === null) return null
  try {
    const stored = storage.getItem(LOCALE_STORAGE_KEY)
    return isSupportedLocale(stored) ? stored : null
  } catch {
    return null
  }
}

/**
 * Con un unico idioma ofrecido (`branding.locales.available`, de serie `[es]`),
 * ese y nada mas: ni la eleccion guardada ni el navegador lo cambian. Con
 * varios: la eleccion guardada, el navegador y el predeterminado de la
 * instalacion. La politica sale de la copia de la marca, sin red.
 */
export function initialLocale(
  browserLanguages: readonly string[] = typeof navigator === 'undefined' ? [] : navigator.languages,
  storage: Storage | null = safeStorage(),
  policy: LocalePolicy = readCachedLocalePolicy(),
): AppLocale {
  return resolveUiLocale<AppLocale>({
    policy,
    shipped: SUPPORTED_LOCALES,
    stored: readStoredLocale(storage),
    browser: browserLanguages,
  })
}

function safeStorage(): Storage | null {
  try {
    return globalThis.localStorage
  } catch {
    return null
  }
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
