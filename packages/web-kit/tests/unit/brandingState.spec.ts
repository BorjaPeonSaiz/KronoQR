// Estado compartido de la marca (RF-PD-08, ADR-036): el producto mientras no
// llega nada, la marca del servidor cuando llega, y se aplica SIEMPRE, tambien
// cuando la red falla. El componente BrandMark.vue no se prueba aqui: como el
// resto de componentes del paquete, lo ejercitan las vistas de cada SPA.

import { describe, expect, it } from 'vitest'
import { PRODUCT_BRANDING } from '../../src/branding'
import { createBrandingState } from '../../src/brandingState'

const HOTEL = {
  application_name: 'Hotel Marina',
  accent_color: null,
  logo_url: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
  locales: { default: 'es', available: ['es'] },
}

describe('createBrandingState', () => {
  it('empieza con el producto y aplica lo que llega del servidor', async () => {
    const state = createBrandingState({ mode: 'light', fetchBranding: async () => HOTEL })

    expect(state.current.value).toEqual(PRODUCT_BRANDING)
    await state.load()

    expect(state.current.value.applicationName).toBe('Hotel Marina')
    expect(document.title).toBe('Hotel Marina')
    expect(document.head.querySelector('link[rel="icon"]')?.getAttribute('href')).toBe(
      HOTEL.logo_url,
    )
  })

  it('con la red caida se queda con el producto, lo aplica igual y no lanza', async () => {
    const state = createBrandingState({
      mode: 'light',
      fetchBranding: async () => {
        throw new TypeError('Failed to fetch')
      },
    })

    await expect(state.load()).resolves.toBeUndefined()
    expect(state.current.value).toEqual(PRODUCT_BRANDING)
    expect(document.title).toBe('KronoQR')
  })

  it('ignora una respuesta con otra forma y conserva la ultima marca valida', async () => {
    let payload: unknown = HOTEL
    const state = createBrandingState({ mode: 'light', fetchBranding: async () => payload })
    await state.load()

    payload = { application_name: '' }
    await state.load()

    expect(state.current.value.applicationName).toBe('Hotel Marina')
  })
})
