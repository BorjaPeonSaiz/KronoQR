// Selector de idioma del portal (D22, PO7-05, RF-PD-08): solo con dos o mas
// idiomas ofrecidos, como en el quiosco y el panel.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useBrandingStore } from '@/shared/branding/branding.store'
import { createAppI18n } from '@/shared/i18n'
import { useLocaleChoiceStore } from '@/shared/i18n/locale.store'
import { bindPortalLocale } from '@/shared/i18n/portalLocale'
import LanguageSelector from '@/shared/ui/LanguageSelector.vue'
import { brandingPayload } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, stubFetch } from './support/harness'

beforeEach(() => {
  window.localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

async function mountSelector(available: string[], personal: string | null = null) {
  const pinia = createTestPinia()

  stubFetch(() => jsonResponse(brandingPayload({ locales: { default: 'es', available } })))
  await useBrandingStore(pinia).load()

  const branding = useBrandingStore(pinia)
  const choice = useLocaleChoiceStore(pinia)
  const i18n = createAppI18n()

  bindPortalLocale(
    i18n,
    () => ({ policy: branding.current.locales, personal, stored: choice.chosen }),
    () => ['es-ES'],
  )

  const wrapper = await mountView(LanguageSelector, { pinia, i18n })

  return { wrapper, i18n }
}

describe('LanguageSelector', () => {
  it('con un solo idioma ofrecido no se pinta', async () => {
    const { wrapper } = await mountSelector(['es'])

    expect(wrapper.find('[data-test="language-selector"]').exists()).toBe(false)
  })

  it('con dos idiomas ofrecidos se pinta como grupo etiquetado con un boton por idioma', async () => {
    const { wrapper } = await mountSelector(['es', 'en'])
    const group = wrapper.find('[role="group"]')

    expect(group.attributes('aria-label')).toBe('Idioma')
    expect(group.findAll('button').map((button) => button.attributes('lang'))).toEqual(['es', 'en'])
    expect(group.findAll('button').map((button) => button.attributes('aria-pressed'))).toEqual([
      'true',
      'false',
    ])
  })

  it('elegir un idioma cambia el de la pantalla y manda sobre el de la persona', async () => {
    const { wrapper, i18n } = await mountSelector(['es', 'en'], 'es')

    await wrapper.findAll('button')[1]?.trigger('click')

    expect(i18n.global.locale.value).toBe('en')
    expect(wrapper.find('[role="group"]').attributes('aria-label')).toBe('Language')
    expect(wrapper.findAll('button')[1]?.attributes('aria-pressed')).toBe('true')
  })

  it('no guarda la eleccion en el navegador', async () => {
    const { wrapper } = await mountSelector(['es', 'en'])

    const before = Object.keys(window.localStorage)

    await wrapper.findAll('button')[1]?.trigger('click')

    // Solo queda la copia de la marca de antes; el idioma no se escribe.
    expect(Object.keys(window.localStorage)).toEqual(before)
    expect(window.sessionStorage.length).toBe(0)
  })

  it('si la instalacion deja de ofrecer el idioma elegido, vuelve al ofrecido', async () => {
    const pinia = createTestPinia()

    stubFetch(() =>
      jsonResponse(brandingPayload({ locales: { default: 'es', available: ['es', 'en'] } })),
    )
    await useBrandingStore(pinia).load()
    useLocaleChoiceStore(pinia).choose('en')

    const branding = useBrandingStore(pinia)
    const i18n = createAppI18n()

    bindPortalLocale(
      i18n,
      () => ({ policy: branding.current.locales, stored: useLocaleChoiceStore(pinia).chosen }),
      () => ['en'],
    )
    expect(i18n.global.locale.value).toBe('en')

    stubFetch(() =>
      jsonResponse(brandingPayload({ locales: { default: 'es', available: ['es'] } })),
    )
    await branding.load()

    expect(i18n.global.locale.value).toBe('es')
  })
})
