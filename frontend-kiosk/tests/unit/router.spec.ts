import { afterEach, describe, expect, it } from 'vitest'
import { createAppRouter, routes } from '@/router'

const DEVICE_TOKEN_KEY = 'kronoqr.kiosk.device_token'

afterEach(() => {
  localStorage.removeItem(DEVICE_TOKEN_KEY)
})

describe('rutas de la aplicacion', () => {
  it('resuelve la ruta raiz cuando la tablet esta emparejada', async () => {
    localStorage.setItem(DEVICE_TOKEN_KEY, 'device-token-de-prueba')
    const router = createAppRouter()
    await router.push('/')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('home')
  })

  it('declara todas sus rutas con nombre', () => {
    expect(routes.every((route) => route.name !== undefined)).toBe(true)
  })
})

describe('guardia de emparejamiento (RF-PD-06)', () => {
  it('sin token de dispositivo, cualquier ruta redirige a /pair', async () => {
    const router = createAppRouter()
    await router.push('/')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('pair')
  })

  it('sin token, /pin tambien redirige a /pair: no hay nada que fichar sin vincular', async () => {
    const router = createAppRouter()
    await router.push('/pin')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('pair')
  })

  it('con token de dispositivo, /pair redirige a la pantalla de fichaje', async () => {
    localStorage.setItem(DEVICE_TOKEN_KEY, 'device-token-de-prueba')
    const router = createAppRouter()
    await router.push('/pair')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('home')
  })

  it('con token, /pin se deja en paz', async () => {
    localStorage.setItem(DEVICE_TOKEN_KEY, 'device-token-de-prueba')
    const router = createAppRouter()
    await router.push('/pin')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('pin')
  })

  it('la guardia se reevalua en cada navegacion: revocar el token a mitad de sesion manda a /pair', async () => {
    localStorage.setItem(DEVICE_TOKEN_KEY, 'device-token-de-prueba')
    const router = createAppRouter()
    await router.push('/')
    await router.isReady()
    expect(router.currentRoute.value.name).toBe('home')

    // El panel desvincula el dispositivo: el token desaparece del almacenamiento.
    localStorage.removeItem(DEVICE_TOKEN_KEY)
    await router.push('/pin')

    expect(router.currentRoute.value.name).toBe('pair')
  })
})
