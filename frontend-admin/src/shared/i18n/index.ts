// Infraestructura de idiomas (doc 02 §3.5): ningun texto que vea una persona
// se escribe en una plantilla. Espanol e ingles desde el primer dia, y anadir
// un idioma nuevo es anadir un fichero, no tocar componentes.
import { resolveUiLocale, type LocalePolicy } from '@kronoqr/web-kit/branding'
import { createI18n } from 'vue-i18n'
import type es from './locales/es.json'

export const SUPPORTED_LOCALES = ['es', 'en'] as const satisfies readonly [string, ...string[]]

export type AppLocale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: AppLocale = 'es'

export function isSupportedLocale(value: unknown): value is AppLocale {
  return typeof value === 'string' && SUPPORTED_LOCALES.some((locale) => locale === value)
}

/** La forma de los textos de un idioma (la del catalogo de referencia, el espanol). */
export type LocaleMessages = typeof es

type LocaleLoader = () => Promise<{ default: LocaleMessages }>

// CADA IDIOMA ES UN TROZO APARTE (V21-5-PA-4, V5-PA-5). Antes los dos catalogos
// entraban en el chunk de arranque -unos 70 KiB gzip- aunque cada persona usa
// uno y una instalacion solo en espanol nunca ve el ingles. Ahora solo se
// descarga el idioma que se va a pintar. `import()` con ruta literal por idioma:
// asi Vite genera un trozo por fichero.
const LOADERS: Readonly<Record<AppLocale, LocaleLoader>> = {
  es: () => import('./locales/es.json'),
  en: () => import('./locales/en.json'),
}

/**
 * Crea el `i18n` SIN textos. Quien arranca la aplicacion llama despues a
 * `ensureLocaleLoaded` y espera antes de montar; las pruebas pasan los
 * catalogos ya importados en `loaded`.
 */
export function createAppI18n(
  locale: AppLocale = DEFAULT_LOCALE,
  loaded: Partial<Record<AppLocale, LocaleMessages>> = {},
) {
  return createI18n({
    legacy: false,
    locale,
    fallbackLocale: DEFAULT_LOCALE,
    messages: loaded,
  })
}

export type AppI18n = ReturnType<typeof createAppI18n>

/** Descarga el idioma si aun no esta en el `i18n`. Idempotente; lanza si el trozo no llega. */
export async function ensureLocaleLoaded(i18n: AppI18n, locale: AppLocale): Promise<void> {
  if (i18n.global.availableLocales.includes(locale)) {
    return
  }

  const loaded = await LOADERS[locale]()

  i18n.global.setLocaleMessage(locale, loaded.default)
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
