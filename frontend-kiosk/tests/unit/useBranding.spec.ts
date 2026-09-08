// Marca blanca del quiosco (RF-PD-08, tarea 5.8): nunca espera a la red
// (regla dura 19), aplica lo cacheado antes que nada y se guarda solo cuando
// el servidor contesta algo valido.

import { PRODUCT_BRANDING } from '@kronoqr/web-kit/branding'
import { readonly, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { applyCachedBranding, useBranding } from '@/shared/branding/useBranding'
import type { ApiClient, ApiResult } from '@/shared/api/client'
import type { Branding as RawBranding } from '@/shared/api/types'
import type {
  ConnectivityController,
  ConnectivityStatus,
} from '@/shared/connectivity/useConnectivity'

const STORAGE_KEY = 'kronoqr.kiosk.branding'

const HOTEL: RawBranding = {
  application_name: 'Hotel Marina',
  accent_color: null,
  logo_url: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
  locales: { default: 'es', available: ['es'] },
}

function fakeApi(fetchBranding: () => Promise<ApiResult<RawBranding>>): ApiClient {
  return {
    recordScan: vi.fn(),
    recordPinScan: vi.fn(),
    syncScanBatch: vi.fn(),
    fetchRoster: vi.fn(),
    sendHeartbeat: vi.fn(),
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    fetchBranding: vi.fn(fetchBranding),
  }
}

/** Controlador falso, con un `setStatus` para forzar la transicion a "online". */
function fakeConnectivity(): ConnectivityController & {
  setStatus(next: ConnectivityStatus): void
} {
  const status = ref<ConnectivityStatus>('online')
  return {
    status: readonly(status),
    pendingCount: readonly(ref(0)),
    reportReachability: vi.fn(),
    setPendingCount: vi.fn(),
    setStatus: (next) => {
      status.value = next
    },
  }
}

beforeEach(() => {
  localStorage.clear()
  document.title = ''
})

describe('useBranding (RF-PD-08)', () => {
  it('empieza con la marca del producto cuando no hay copia ni servidor', async () => {
    const api = fakeApi(async () => ({ outcome: 'failed', cause: 'offline' }))
    const controller = useBranding({ api })

    // Sincrono: no ha hecho falta esperar a ningun `await` para tener algo
    // que pintar (regla dura 19).
    expect(controller.current.value).toEqual(PRODUCT_BRANDING)

    await controller.refresh()
    expect(controller.current.value).toEqual(PRODUCT_BRANDING)
  })

  it('restaura la copia guardada SIN esperar al servidor', () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(HOTEL))
    const api = fakeApi(async () => ({ outcome: 'failed', cause: 'offline' }))

    const controller = useBranding({ api })

    expect(controller.current.value.applicationName).toBe('Hotel Marina')
    expect(document.title).toBe('Hotel Marina')
  })

  it('ignora una copia corrupta y se queda con el producto', () => {
    localStorage.setItem(STORAGE_KEY, '{esto no es json')
    const api = fakeApi(async () => ({ outcome: 'failed', cause: 'offline' }))

    const controller = useBranding({ api })

    expect(controller.current.value).toEqual(PRODUCT_BRANDING)
  })

  it('ignora una copia que no encaja con el contrato (version anterior, campo que falta...)', () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ application_name: '' }))
    const api = fakeApi(async () => ({ outcome: 'failed', cause: 'offline' }))

    const controller = useBranding({ api })

    expect(controller.current.value).toEqual(PRODUCT_BRANDING)
  })

  it('aplica la marca del servidor y la guarda para la proxima vez', async () => {
    const api = fakeApi(async () => ({ outcome: 'ok', data: HOTEL }))
    const controller = useBranding({ api })

    await vi.waitFor(() => expect(controller.current.value.applicationName).toBe('Hotel Marina'))

    expect(document.title).toBe('Hotel Marina')
    expect(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null')).toEqual(HOTEL)
  })

  it('un cuerpo que no encaja con el contrato NO borra la marca que ya habia', async () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(HOTEL))
    const api = fakeApi(async () => ({ outcome: 'ok', data: { rareza: true } as never }))

    const controller = useBranding({ api })
    await controller.refresh()

    expect(controller.current.value.applicationName).toBe('Hotel Marina')
  })

  it('un fallo de red no lanza y deja la marca que ya habia (regla dura 19)', async () => {
    const api = fakeApi(async () => {
      throw new Error('esto no deberia pasar nunca, pero si pasara, no debe tumbar nada')
    })

    const controller = useBranding({ api })

    await expect(controller.refresh()).resolves.toBeUndefined()
    expect(controller.current.value).toEqual(PRODUCT_BRANDING)
  })

  it('se vuelve a pedir al recuperar la red', async () => {
    const fetchBranding = vi.fn(async (): Promise<ApiResult<RawBranding>> => ({
      outcome: 'ok',
      data: HOTEL,
    }))
    const api: ApiClient = { ...fakeApi(fetchBranding), fetchBranding }
    const connectivity = fakeConnectivity()
    connectivity.setStatus('offline')

    useBranding({ api, connectivity })
    await vi.waitFor(() => expect(fetchBranding).toHaveBeenCalledTimes(1))

    connectivity.setStatus('online')
    await vi.waitFor(() => expect(fetchBranding).toHaveBeenCalledTimes(2))
  })

  it('sin conectividad no se vuelve a pedir por si sola: hace falta la transicion a online', async () => {
    const fetchBranding = vi.fn(async (): Promise<ApiResult<RawBranding>> => ({
      outcome: 'ok',
      data: HOTEL,
    }))
    const api: ApiClient = { ...fakeApi(fetchBranding), fetchBranding }

    useBranding({ api }) // sin `connectivity`: una sola peticion, al crearse.
    await vi.waitFor(() => expect(fetchBranding).toHaveBeenCalledTimes(1))

    await new Promise((resolve) => setTimeout(resolve, 10))
    expect(fetchBranding).toHaveBeenCalledTimes(1)
  })
})

describe('applyCachedBranding', () => {
  it('pinta la copia guardada antes de que exista ninguna peticion', () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(HOTEL))

    applyCachedBranding()

    expect(document.title).toBe('Hotel Marina')
  })

  it('sin copia guardada, aplica el nombre del producto', () => {
    applyCachedBranding()

    expect(document.title).toBe('KronoQR')
  })
})
