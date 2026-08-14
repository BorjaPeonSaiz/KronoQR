import { describe, expect, it } from 'vitest'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { DEFAULT_LOCALE, isSupportedLocale, resolveLocale } from '@/shared/i18n'

/** Aplana un arbol de mensajes a la lista de sus claves con punto. */
function flatKeys(value: unknown, prefix = ''): string[] {
  if (typeof value !== 'object' || value === null) {
    return [prefix]
  }

  return Object.entries(value as Record<string, unknown>).flatMap(([key, nested]) =>
    flatKeys(nested, prefix === '' ? key : prefix + '.' + key),
  )
}

/** Aplana un arbol de mensajes a la lista de sus textos. */
function flatValues(value: unknown): string[] {
  if (typeof value !== 'object' || value === null) {
    return [String(value)]
  }

  return Object.values(value as Record<string, unknown>).flatMap(flatValues)
}

describe('idiomas de la aplicacion', () => {
  it('sirve espanol e ingles con exactamente las mismas claves', () => {
    expect(flatKeys(en).sort()).toEqual(flatKeys(es).sort())
  })

  it('no deja ningun texto vacio en ninguno de los dos idiomas', () => {
    expect(flatValues(es).every((text) => text.trim().length > 0)).toBe(true)
    expect(flatValues(en).every((text) => text.trim().length > 0)).toBe(true)
  })

  it('elige el primer idioma soportado de las preferencias del navegador', () => {
    expect(resolveLocale(['en-GB', 'es-ES'])).toBe('en')
    expect(resolveLocale(['fr-FR', 'es-ES'])).toBe('es')
  })

  it('cae al idioma por defecto cuando ninguna preferencia encaja', () => {
    expect(resolveLocale(['fr-FR', 'de-DE'])).toBe(DEFAULT_LOCALE)
    expect(resolveLocale([])).toBe(DEFAULT_LOCALE)
  })

  it('rechaza lo que no es un idioma soportado sin suponer que es texto', () => {
    expect(isSupportedLocale('es')).toBe(true)
    expect(isSupportedLocale('pt')).toBe(false)
    expect(isSupportedLocale(42)).toBe(false)
    expect(isSupportedLocale(undefined)).toBe(false)
  })
})
