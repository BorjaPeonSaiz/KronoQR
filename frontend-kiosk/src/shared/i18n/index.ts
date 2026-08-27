// Infraestructura de idiomas (RF-KI-05, doc 02 §3.5): ningun texto que vea una
// persona se escribe en una plantilla. Espanol e ingles desde el primer dia, y
// anadir un idioma nuevo es anadir un fichero, no tocar componentes.
//
// SELECTOR PERSISTENTE Y DETECCION AUTOMATICA, en ese orden de prioridad:
//   1. lo que alguien eligio a mano en esta tablet,
//   2. lo que dice el navegador,
//   3. el idioma por defecto.
//
// Se guarda en `localStorage` a proposito, y no contradice la prohibicion de
// usarlo para la cola: son dos caracteres cuya perdida no tiene ninguna
// consecuencia. La cola es registro legal sin escribir y va a IndexedDB.

import { createI18n } from 'vue-i18n'
import en from './locales/en.json'
import es from './locales/es.json'

export const SUPPORTED_LOCALES = ['es', 'en'] as const

export type AppLocale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: AppLocale = 'es'

const LOCALE_STORAGE_KEY = 'kronoqr.kiosk.locale'

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

export function readStoredLocale(storage: Storage | null = safeStorage()): AppLocale | null {
  if (storage === null) return null
  try {
    const stored = storage.getItem(LOCALE_STORAGE_KEY)
    return isSupportedLocale(stored) ? stored : null
  } catch {
    return null
  }
}

export function storeLocale(locale: AppLocale, storage: Storage | null = safeStorage()): void {
  if (storage === null) return
  try {
    storage.setItem(LOCALE_STORAGE_KEY, locale)
  } catch {
    // Almacenamiento lleno o deshabilitado: se pierde la preferencia, no el
    // idioma. Nada que un empleado delante de la tablet tenga que sufrir.
  }
}

/** Preferencia guardada, si no hay, la del navegador; si no, la de por defecto. */
export function initialLocale(
  browserLanguages: readonly string[] = typeof navigator === 'undefined' ? [] : navigator.languages,
  storage: Storage | null = safeStorage(),
): AppLocale {
  return readStoredLocale(storage) ?? resolveLocale(browserLanguages)
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
