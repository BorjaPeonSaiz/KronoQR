import { describe, expect, it } from 'vitest'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { isSupportedLocale, resolvePortalLocale } from '@/shared/i18n'

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

  it('rechaza lo que no es un idioma soportado sin suponer que es texto', () => {
    expect(isSupportedLocale('es')).toBe(true)
    expect(isSupportedLocale('pt')).toBe(false)
    expect(isSupportedLocale(42)).toBe(false)
    expect(isSupportedLocale(undefined)).toBe(false)
  })

  describe('con un solo idioma ofrecido (de serie, RF-PD-08)', () => {
    const policy = { default: 'es', available: ['es'] }

    it('es siempre el espanol, aunque el navegador pida ingles', () => {
      expect(resolvePortalLocale({ policy, browser: ['en-GB', 'en'] })).toBe('es')
    })

    it('ignora el idioma de la persona si no esta entre los ofrecidos', () => {
      expect(resolvePortalLocale({ policy, personal: 'en', browser: ['en-GB'] })).toBe('es')
    })
  })

  describe('con dos idiomas ofrecidos', () => {
    const policy = { default: 'es', available: ['es', 'en'] }

    it('el idioma de la persona gana al del navegador', () => {
      expect(resolvePortalLocale({ policy, personal: 'es', browser: ['en-GB'] })).toBe('es')
      expect(resolvePortalLocale({ policy, personal: 'en', browser: ['es-ES'] })).toBe('en')
    })

    it('sin persona, sigue el primer idioma del navegador que coincide', () => {
      expect(resolvePortalLocale({ policy, browser: ['en-GB', 'es-ES'] })).toBe('en')
      expect(resolvePortalLocale({ policy, browser: ['fr-FR', 'es-ES'] })).toBe('es')
    })

    it('si nada encaja, usa el idioma por defecto de la instalacion', () => {
      expect(resolvePortalLocale({ policy, browser: ['fr-FR', 'de-DE'] })).toBe('es')
      expect(resolvePortalLocale({ policy: { ...policy, default: 'en' }, browser: [] })).toBe('en')
    })

    it('un idioma de la persona que la version no trae se ignora', () => {
      expect(resolvePortalLocale({ policy, personal: 'pt', browser: ['en-GB'] })).toBe('en')
    })
  })
})
