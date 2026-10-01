// Estado compartido de la marca (RF-PD-08, ADR-036): el producto mientras no
// llega nada, la marca del servidor cuando llega, y se aplica SIEMPRE, tambien
// cuando la red falla. Con cache en localStorage (MB4): se pinta la marca
// guardada al arrancar y se actualiza al llegar la respuesta. El componente
// BrandMark.vue no se prueba aqui: como el resto de componentes del paquete, lo
// ejercitan las vistas de cada SPA.

import { beforeEach, describe, expect, it } from 'vitest'
import { applyBranding, PRODUCT_BRANDING } from '../../src/branding'
import {
  BRANDING_CACHE_KEY,
  createBrandingState,
  readCachedBranding,
  writeCachedBranding,
} from '../../src/brandingState'

const HOTEL = {
  application_name: 'Hotel Marina',
  accent_color: null,
  logo_url: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
  locales: { default: 'es', available: ['es'] },
}

const OTHER = { ...HOTEL, application_name: 'Hotel Costa', accent_color: '#0f5c8c' }

/** Un `Storage` en memoria, para no depender del de jsdom ni compartirlo entre pruebas. */
function memoryStorage(initial: Record<string, string> = {}): Storage {
  const data = new Map(Object.entries(initial))
  return {
    get length() {
      return data.size
    },
    clear: () => data.clear(),
    getItem: (key) => data.get(key) ?? null,
    key: (index) => [...data.keys()][index] ?? null,
    removeItem: (key) => void data.delete(key),
    setItem: (key, value) => void data.set(key, value),
  }
}

/** Un `Storage` que lanza en cada operacion: navegador con el almacenamiento bloqueado. */
function brokenStorage(): Storage {
  const fail = (): never => {
    throw new DOMException('denied', 'SecurityError')
  }
  return {
    get length(): number {
      return fail()
    },
    clear: fail,
    getItem: fail,
    key: fail,
    removeItem: fail,
    setItem: fail,
  }
}

beforeEach(() => {
  applyBranding(PRODUCT_BRANDING, { mode: 'light', document, tokens: () => '#000000' })
})

describe('createBrandingState', () => {
  it('empieza con el producto y aplica lo que llega del servidor', async () => {
    const state = createBrandingState({
      mode: 'light',
      storage: memoryStorage(),
      fetchBranding: async () => HOTEL,
    })

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
      storage: memoryStorage(),
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
    const state = createBrandingState({
      mode: 'light',
      storage: memoryStorage(),
      fetchBranding: async () => payload,
    })
    await state.load()

    payload = { application_name: '' }
    await state.load()

    expect(state.current.value.applicationName).toBe('Hotel Marina')
  })
})

describe('cache de la marca en localStorage (MB4)', () => {
  it('guarda la marca valida al llegar la respuesta, en la forma del contrato', async () => {
    const storage = memoryStorage()
    const state = createBrandingState({
      mode: 'light',
      storage,
      fetchBranding: async () => ({ ...OTHER, ignored_field: 'x'.repeat(100) }),
    })

    await state.load()

    const saved: unknown = JSON.parse(storage.getItem(BRANDING_CACHE_KEY) ?? 'null')
    expect(saved).toEqual(OTHER)
  })

  it('al arrancar parte de la marca guardada y la pinta sin esperar a la red', () => {
    const storage = memoryStorage({ [BRANDING_CACHE_KEY]: JSON.stringify(HOTEL) })
    const state = createBrandingState({
      mode: 'light',
      storage,
      fetchBranding: () => new Promise(() => undefined),
    })

    expect(state.current.value.applicationName).toBe('Hotel Marina')
    state.apply()
    expect(document.title).toBe('Hotel Marina')
  })

  it('al llegar la respuesta actualiza la marca y la copia', async () => {
    const storage = memoryStorage({ [BRANDING_CACHE_KEY]: JSON.stringify(HOTEL) })
    const state = createBrandingState({ mode: 'light', storage, fetchBranding: async () => OTHER })

    await state.load()

    expect(state.current.value.applicationName).toBe('Hotel Costa')
    expect(document.title).toBe('Hotel Costa')
    expect(readCachedBranding(storage, BRANDING_CACHE_KEY)?.applicationName).toBe('Hotel Costa')
  })

  it('con la red caida conserva la marca guardada, aplicada', async () => {
    const storage = memoryStorage({ [BRANDING_CACHE_KEY]: JSON.stringify(HOTEL) })
    const state = createBrandingState({
      mode: 'light',
      storage,
      fetchBranding: async () => {
        throw new TypeError('Failed to fetch')
      },
    })

    await state.load()

    expect(state.current.value.applicationName).toBe('Hotel Marina')
    expect(document.title).toBe('Hotel Marina')
  })

  it('una respuesta invalida no pisa la copia buena', async () => {
    const storage = memoryStorage({ [BRANDING_CACHE_KEY]: JSON.stringify(HOTEL) })
    const state = createBrandingState({
      mode: 'light',
      storage,
      fetchBranding: async () => ({ application_name: '' }),
    })

    await state.load()

    expect(readCachedBranding(storage, BRANDING_CACHE_KEY)?.applicationName).toBe('Hotel Marina')
  })

  it.each([
    ['JSON roto', '{no es json'],
    ['un valor que no es un objeto', '"KronoQR"'],
    ['un color invalido', JSON.stringify({ ...HOTEL, accent_color: 'javascript:alert(1)' })],
    [
      'un logotipo de otro origen',
      JSON.stringify({ ...HOTEL, logo_url: 'https://evil.example/p.png' }),
    ],
    ['una copia desmesurada', JSON.stringify({ ...HOTEL, application_name: 'x'.repeat(10_000) })],
  ])('con %s en la copia arranca con el producto, sin lanzar', (_case, raw) => {
    const state = createBrandingState({
      mode: 'light',
      storage: memoryStorage({ [BRANDING_CACHE_KEY]: raw }),
      fetchBranding: async () => HOTEL,
    })

    expect(state.current.value).toEqual(PRODUCT_BRANDING)
  })

  it('con el almacenamiento bloqueado funciona igual, solo que sin copia', async () => {
    const state = createBrandingState({
      mode: 'light',
      storage: brokenStorage(),
      fetchBranding: async () => HOTEL,
    })

    expect(state.current.value).toEqual(PRODUCT_BRANDING)
    await expect(state.load()).resolves.toBeUndefined()
    expect(state.current.value.applicationName).toBe('Hotel Marina')
  })

  it('con el almacenamiento desactivado (null) tampoco lee ni escribe', async () => {
    const state = createBrandingState({
      mode: 'light',
      storage: null,
      fetchBranding: async () => HOTEL,
    })

    await state.load()

    expect(state.current.value.applicationName).toBe('Hotel Marina')
    expect(readCachedBranding(null, BRANDING_CACHE_KEY)).toBeNull()
    expect(() => writeCachedBranding(null, BRANDING_CACHE_KEY, PRODUCT_BRANDING)).not.toThrow()
  })

  it('sin `storage` explicito usa el localStorage del navegador y respeta una clave propia', async () => {
    window.localStorage.removeItem('kronoqr.test.branding')
    const state = createBrandingState({
      mode: 'light',
      storageKey: 'kronoqr.test.branding',
      fetchBranding: async () => HOTEL,
    })

    await state.load()

    expect(window.localStorage.getItem('kronoqr.test.branding')).not.toBeNull()
    window.localStorage.removeItem('kronoqr.test.branding')
  })

  it('no guarda una marca que no cabe en el tope', () => {
    const storage = memoryStorage()

    writeCachedBranding(storage, BRANDING_CACHE_KEY, {
      ...PRODUCT_BRANDING,
      applicationName: 'x'.repeat(10_000),
    })

    expect(storage.getItem(BRANDING_CACHE_KEY)).toBeNull()
  })

  it('un disco lleno al guardar no lanza ni tumba la marca de la sesion', async () => {
    const full = memoryStorage()
    full.setItem = () => {
      throw new DOMException('full', 'QuotaExceededError')
    }
    const state = createBrandingState({
      mode: 'light',
      storage: full,
      fetchBranding: async () => HOTEL,
    })

    await state.load()

    expect(state.current.value.applicationName).toBe('Hotel Marina')
  })
})
