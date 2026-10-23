// Marca de la instalacion (RF-PD-08, tarea 5.8). El store es una envoltura de
// dos lineas sobre `@kronoqr/web-kit/brandingState` (ADR-036): estas pruebas
// ejercitan esa logica compartida a traves del store del portal, con el mismo
// doble de `fetch` global que usa el resto de la suite (`requestJson` de
// `@kronoqr/web-kit/http` es lo que `createBrandingState` llama por defecto).
import { PRODUCT_BRANDING } from '@kronoqr/web-kit/branding'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useBrandingStore } from '@/shared/branding/branding.store'
import { createAppI18n } from '@/shared/i18n'
import { bindPortalLocale } from '@/shared/i18n/portalLocale'
import { brandingPayload } from './support/fixtures'
import { createTestPinia, jsonResponse, stubFetch } from './support/harness'

beforeEach(() => {
  window.localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.documentElement.removeAttribute('data-kq-branded')
  document.documentElement.removeAttribute('style')
  document.title = ''
})

describe('tienda de marca del portal', () => {
  it('empieza con la marca del producto y la pinta sin esperar a la red', () => {
    createTestPinia()
    const branding = useBrandingStore()

    expect(branding.current).toEqual(PRODUCT_BRANDING)

    branding.apply()

    expect(document.title).toBe('')
    expect(document.documentElement.getAttribute('data-kq-branded')).toBe('product')
  })

  it('sustituye el producto por la marca del servidor cuando llega y es valida', async () => {
    createTestPinia()
    stubFetch(() => jsonResponse(brandingPayload()))

    const branding = useBrandingStore()

    await branding.load()

    expect(branding.current.applicationName).toBe('Hotel Marina')
    expect(branding.current.logoUrl).toBe('/api/v1/branding/logo?v=3f9a1c2b7e4d')
    expect(document.title).toBe('')
    // La derivacion de tonos sobre los tokens `--kq-*` (`accentOverrides`) ya
    // esta probada exhaustivamente en `packages/web-kit`; aqui solo importa
    // que la marca del servidor llega y se pinta, no como.
  })

  it('sin red, se queda con el producto en vez de lanzar', async () => {
    createTestPinia()
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const branding = useBrandingStore()

    await expect(branding.load()).resolves.toBeUndefined()
    expect(branding.current).toEqual(PRODUCT_BRANDING)
  })

  it('una respuesta con una forma inesperada tampoco sustituye la marca vigente', async () => {
    createTestPinia()
    stubFetch(() => jsonResponse({ nombre: 'esto no es la marca' }))

    const branding = useBrandingStore()

    await branding.load()

    expect(branding.current).toEqual(PRODUCT_BRANDING)
  })

  it('MB4: tras cargar la marca, el siguiente arranque la pinta desde la copia, sin red', async () => {
    createTestPinia()
    stubFetch(() => jsonResponse(brandingPayload()))
    await useBrandingStore().load()

    // Otra carga completa de la pagina: store nuevo, red que no contesta.
    createTestPinia()
    document.title = ''
    vi.stubGlobal('fetch', () => new Promise(() => undefined))
    const store = useBrandingStore()
    store.apply()

    expect(store.current.applicationName).toBe('Hotel Marina')
    expect(document.title).toBe('')
  })

  it('MB4: una copia corrupta no impide arrancar con el producto', () => {
    window.localStorage.setItem('kronoqr.branding', '{roto')
    createTestPinia()

    expect(useBrandingStore().current).toEqual(PRODUCT_BRANDING)
  })
})

describe('idioma segun la politica de la marca (RF-PD-08)', () => {
  const browserEnglish = (): readonly string[] => ['en-GB']

  /** Ata el idioma como lo hace `main.ts`: politica de la marca + persona. */
  function bind(personal: string | null) {
    const pinia = createTestPinia()
    const branding = useBrandingStore(pinia)
    const i18n = createAppI18n()
    bindPortalLocale(i18n, () => ({ policy: branding.current.locales, personal }), browserEnglish)

    return { branding, i18n }
  }

  it('de serie solo hay espanol: el navegador en ingles no lo cambia', () => {
    const { i18n } = bind(null)

    expect(i18n.global.locale.value).toBe('es')
  })

  it('con un solo idioma, ni la marca recibida ni el idioma de la persona lo cambian', async () => {
    const { branding, i18n } = bind('en')
    stubFetch(() => jsonResponse(brandingPayload()))

    await branding.load()

    expect(branding.current.locales.available).toEqual(['es'])
    expect(i18n.global.locale.value).toBe('es')
  })

  it('al llegar una marca bilingue, el idioma se recalcula con el navegador', async () => {
    const { branding, i18n } = bind(null)
    stubFetch(() =>
      jsonResponse(brandingPayload({ locales: { default: 'es', available: ['es', 'en'] } })),
    )

    expect(i18n.global.locale.value).toBe('es')

    await branding.load()

    expect(i18n.global.locale.value).toBe('en')
  })

  it('con dos idiomas gana el de la persona sobre el del navegador', async () => {
    const { branding, i18n } = bind('es')
    stubFetch(() =>
      jsonResponse(brandingPayload({ locales: { default: 'es', available: ['es', 'en'] } })),
    )

    await branding.load()

    expect(i18n.global.locale.value).toBe('es')
  })
})
