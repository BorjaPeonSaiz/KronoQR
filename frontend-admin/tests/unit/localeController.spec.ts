// Arranque y cambio de idioma con catalogos por idioma (V21-5-PA-4).
import { describe, expect, it, vi } from 'vitest'
import { createAppI18n } from '@/shared/i18n'
import type { AppI18n, AppLocale } from '@/shared/i18n'
import { createLocaleController, renderBootFailure } from '@/shared/i18n/localeController'

function setup(
  options: { wanted?: () => AppLocale; failing?: AppLocale[]; failOnce?: AppLocale } = {},
) {
  const i18n = createAppI18n('es')
  const calls: AppLocale[] = []
  const gates = new Map<AppLocale, () => void>()
  let failedOnce = false

  const load = vi.fn(async (target: AppI18n, locale: AppLocale) => {
    calls.push(locale)

    if (options.failing?.includes(locale)) {
      throw new Error('red caida')
    }

    if (options.failOnce === locale && !failedOnce) {
      failedOnce = true
      throw new Error('red caida una vez')
    }

    if (gates.has(locale)) {
      await new Promise<void>((resolve) => gates.set(locale, resolve))
    }

    target.global.setLocaleMessage(locale, { marker: locale } as never)
  })
  const controller = createLocaleController(i18n, options.wanted ?? (() => 'es'), load)

  return { i18n, controller, calls, gates }
}

describe('createLocaleController', () => {
  it('una descarga lenta de un idioma que ya no es el deseado no pisa al bueno', async () => {
    let wanted: AppLocale = 'en'
    const { i18n, controller, gates } = setup({ wanted: () => wanted })

    gates.set('en', () => undefined)
    const slow = controller.applyLocale('en')

    // Mientras baja el ingles, la marca de la instalacion fija el español.
    wanted = 'es'
    await controller.applyLocale('es')
    expect(i18n.global.locale.value).toBe('es')

    gates.get('en')?.()
    await slow

    expect(i18n.global.locale.value).toBe('es')
  })

  it('aplica el idioma cuando sigue siendo el deseado al terminar', async () => {
    const { i18n, controller } = setup({ wanted: () => 'en' })

    await controller.applyLocale('en')

    expect(i18n.global.locale.value).toBe('en')
  })

  it('un cambio cuyo catalogo no llega deja el idioma anterior, sin lanzar', async () => {
    const { i18n, controller } = setup({ wanted: () => 'en', failing: ['en'] })

    await expect(controller.applyLocale('en')).resolves.toBeUndefined()
    expect(i18n.global.locale.value).toBe('es')
  })

  it('el arranque reintenta una vez si el catalogo no llega', async () => {
    const { i18n, controller, calls } = setup({ failOnce: 'en', wanted: () => 'en' })

    expect(await controller.startup('en')).toBe('ok')
    expect(calls).toEqual(['en', 'en'])
    expect(i18n.global.locale.value).toBe('en')
  })

  it('si el idioma deseado cambia durante el arranque, se acaba en el deseado', async () => {
    let wanted: AppLocale = 'es'
    const { i18n, controller } = setup({ wanted: () => wanted })

    const starting = controller.startup('es')

    wanted = 'en'
    await starting

    expect(i18n.global.locale.value).toBe('en')
  })

  it('si el catalogo de arranque no llega, cae al de reserva (es) en vez de montar sin textos', async () => {
    const { i18n, controller, calls } = setup({ failing: ['en'] })

    expect(await controller.startup('en')).toBe('fallback')
    expect(calls).toEqual(['en', 'en', 'es'])
    expect(i18n.global.locale.value).toBe('es')
  })

  it('si tampoco llega el de reserva, no se monta: el arranque falla', async () => {
    const { controller } = setup({ failing: ['en', 'es'] })

    expect(await controller.startup('en')).toBe('failed')
  })

  it('con es de arranque que falla no hay reserva distinta: falla tras el reintento', async () => {
    const { controller, calls } = setup({ failing: ['es'] })

    expect(await controller.startup('es')).toBe('failed')
    expect(calls).toEqual(['es', 'es'])
  })
})

describe('renderBootFailure', () => {
  it('pinta un aviso visible y bilingue con salida, sin necesitar el catalogo', () => {
    const root = document.createElement('div')

    renderBootFailure(root)

    const alert = root.querySelector('[role="alert"]')

    expect(alert?.textContent).toContain('No se han podido cargar los textos')
    expect(alert?.textContent).toContain('could not be loaded')
    expect(root.querySelector('button')?.textContent).toContain('Reload')
  })

  it('sin contenedor no hace nada', () => {
    expect(() => renderBootFailure(null)).not.toThrow()
  })
})
