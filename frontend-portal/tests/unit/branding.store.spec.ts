// Marca de la instalacion (RF-PD-08, tarea 5.8). El store es una envoltura de
// dos lineas sobre `@kronoqr/web-kit/brandingState` (ADR-036): estas pruebas
// ejercitan esa logica compartida a traves del store del portal, con el mismo
// doble de `fetch` global que usa el resto de la suite (`requestJson` de
// `@kronoqr/web-kit/http` es lo que `createBrandingState` llama por defecto).
import { PRODUCT_BRANDING } from '@kronoqr/web-kit/branding'
import { afterEach, describe, expect, it } from 'vitest'
import { useBrandingStore } from '@/shared/branding/branding.store'
import { brandingPayload } from './support/fixtures'
import { createTestPinia, jsonResponse, stubFetch } from './support/harness'

afterEach(() => {
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

    expect(document.title).toBe(PRODUCT_BRANDING.applicationName)
    expect(document.documentElement.getAttribute('data-kq-branded')).toBe('product')
  })

  it('sustituye el producto por la marca del servidor cuando llega y es valida', async () => {
    createTestPinia()
    stubFetch(() => jsonResponse(brandingPayload()))

    const branding = useBrandingStore()

    await branding.load()

    expect(branding.current.applicationName).toBe('Hotel Marina')
    expect(branding.current.logoUrl).toBe('/api/v1/branding/logo?v=3f9a1c2b7e4d')
    expect(document.title).toBe('Hotel Marina')
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
})
