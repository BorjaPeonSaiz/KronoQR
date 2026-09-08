import { PRODUCT_BRANDING } from '@kronoqr/web-kit/branding'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useBrandingStore } from '@/shared/branding/branding.store'
import { createTestPinia, jsonResponse, stubFetch } from './support/harness'

// El estado de la marca de la instalacion (RF-PD-08, tarea 5.8), compartido
// por la cabecera (`AppShellView`), el acceso (`LoginView`) y la pantalla que
// la edita (`BrandingView`).
//
// Desde ADR-036 el store es una envoltura de dos lineas sobre
// `createBrandingState` (`@kronoqr/web-kit/brandingState`): estas pruebas
// comprueban el contrato publico (`current`, `apply()`, `load()`) tal como lo
// consume el resto del panel, no la implementacion -que ya prueba
// `packages/web-kit/tests/unit/brandingState.spec.ts`-. `stubFetch` sigue
// bastando: el `fetchBranding` por omision de `createBrandingState` llama a
// `requestJson`, que usa el `fetch` global.

const HOTEL_BRANDING = {
  application_name: 'Hotel Marina',
  accent_color: '#0f5c8c',
  logo_url: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
  locales: { default: 'es', available: ['es'] },
}

beforeEach(() => {
  createTestPinia()
  document.title = ''
  document.documentElement.removeAttribute('data-kq-branded')
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('branding.store', () => {
  it('empieza con la marca del producto, antes de pedir nada', () => {
    const store = useBrandingStore()

    expect(store.current).toEqual(PRODUCT_BRANDING)
  })

  it('apply() pinta la marca actual sin esperar a la red', () => {
    const store = useBrandingStore()

    store.apply()

    expect(document.title).toBe(PRODUCT_BRANDING.applicationName)
    expect(document.documentElement.getAttribute('data-kq-branded')).toBe('product')
  })

  it('load() sustituye la marca cuando el servidor responde una valida', async () => {
    stubFetch(() => jsonResponse(HOTEL_BRANDING))

    const store = useBrandingStore()

    await store.load()

    expect(store.current.applicationName).toBe('Hotel Marina')
    expect(store.current.accentColor).toBe('#0f5c8c')
    expect(store.current.logoUrl).toBe(HOTEL_BRANDING.logo_url)
    // El titulo lo pone `applyBranding` sin depender de ningun token de color:
    // se comprueba aqui. Derivar los tonos del acento SI exige leer
    // `theme.css` sobre el documento, y eso ya lo prueba
    // `packages/web-kit/tests/unit/branding.spec.ts` con un resolutor de
    // tokens de mentira; en jsdom, sin la hoja de estilos real cargada, no
    // hay nada que leer.
    expect(document.title).toBe('Hotel Marina')
  })

  it('un fallo de red se ignora en silencio: se queda la marca del producto', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const store = useBrandingStore()

    await expect(store.load()).resolves.toBeUndefined()
    expect(store.current).toEqual(PRODUCT_BRANDING)
  })

  it('una respuesta que no pasa parseBranding no toca lo que ya habia', async () => {
    stubFetch(() => jsonResponse({ application_name: '' }))

    const store = useBrandingStore()

    await store.load()

    expect(store.current).toEqual(PRODUCT_BRANDING)
  })

  it('un 429 tampoco lanza, y la marca se queda como estaba', async () => {
    stubFetch(
      () =>
        new Response(JSON.stringify({ type: 'about:blank', title: 'Too many', status: 429 }), {
          status: 429,
          headers: { 'Content-Type': 'application/problem+json' },
        }),
    )

    const store = useBrandingStore()

    await expect(store.load()).resolves.toBeUndefined()
    expect(store.current).toEqual(PRODUCT_BRANDING)
  })
})
