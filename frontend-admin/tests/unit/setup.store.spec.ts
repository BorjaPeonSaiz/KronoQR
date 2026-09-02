import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/features/auth/session.store'
import { useSetupStore } from '@/features/onboarding/setup.store'
import { setupCompletion, setupStatus, setupSteps } from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  problemResponse,
  stubFetch,
  stubRoutes,
} from './support/harness'

// Estado del asistente de puesta en marcha (RF-PD-03), compartido entre la
// guarda de rutas y `OnboardingView`.

beforeEach(() => {
  createTestPinia()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('setup.store', () => {
  it('carga el estado una sola vez, salvo que se fuerce', async () => {
    const spy = stubFetch(() => jsonResponse(setupStatus()))
    const store = useSetupStore()

    await store.load()
    await store.load()

    expect(spy).toHaveBeenCalledTimes(1)
    expect(store.loaded).toBe(true)
    expect(store.available).toBe(true)

    await store.load(true)
    expect(spy).toHaveBeenCalledTimes(2)
  })

  it('un fallo de red no deja el estado como cargado, para reintentar en la siguiente navegacion', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const store = useSetupStore()

    await store.load()

    expect(store.loaded).toBe(false)
    expect(store.error).not.toBeNull()
    // Sin datos, `available` no bloquea: una instalacion que ya funciona no
    // puede quedar inaccesible por un corte pasajero del endpoint publico.
    expect(store.available).toBe(false)
  })

  it('recordStep adopta el estado que devuelve el PUT, sin una segunda lectura', async () => {
    const afterMark = setupStatus({ steps: [] })

    stubFetch(() => jsonResponse(afterMark))

    const store = useSetupStore()

    await store.recordStep('license', 'skipped')

    expect(store.status).toEqual(afterMark)
    expect(store.loaded).toBe(true)
  })

  it('refresh vuelve a leer el estado, para los pasos derivados que no admiten PUT', async () => {
    const spy = stubFetch(() => jsonResponse(setupStatus()))
    const store = useSetupStore()

    await store.refresh()

    expect(spy).toHaveBeenCalledTimes(1)
    expect(store.loaded).toBe(true)
  })

  it('complete cierra el asistente y adopta el resumen', async () => {
    const completion = setupCompletion()

    stubFetch(() => jsonResponse(completion))

    const store = useSetupStore()
    const result = await store.complete()

    expect(result).toEqual(completion)
    expect(store.status).toEqual(completion.status)
    expect(store.available).toBe(false)
  })

  it('stepState lee el estado de un paso concreto', async () => {
    stubFetch(() => jsonResponse(setupStatus({ steps: [] })))

    const store = useSetupStore()

    await store.load()
    expect(store.stepState('license')).toBeNull()

    stubFetch(() => jsonResponse(setupStatus({ steps: setupSteps() })))
    await store.load(true)
    expect(store.stepState('license')).toBe('pending')
  })

  it('sin sesion, load pide el estado publico y no conoce los pasos', async () => {
    // `GET /setup/status` es publica y NUNCA trae `steps` (revision de la
    // 5.5): sin sesion es lo unico que se puede pedir, y el store lo refleja
    // en `stepsKnown` en vez de fingir una lista vacia de pasos resueltos.
    const spy = stubRoutes({ '/setup/status': () => jsonResponse(setupStatus()) })
    const store = useSetupStore()

    await store.load()

    expect(spy.mock.calls.some(([url]) => String(url).includes('/setup/steps'))).toBe(false)
    expect(store.stepsKnown).toBe(false)
    expect(store.steps).toEqual([])
  })

  it('con sesion, load pide los pasos de verdad', async () => {
    const session = useSessionStore()

    session.token = 'un-token'
    session.status = 'authenticated'

    const spy = stubRoutes({
      '/setup/steps': () => jsonResponse(setupStatus({ steps: setupSteps() })),
    })
    const store = useSetupStore()

    await store.load()

    expect(spy.mock.calls.some(([url]) => String(url).includes('/setup/status'))).toBe(false)
    expect(store.stepsKnown).toBe(true)
    expect(store.stepState('license')).toBe('pending')
  })

  it('un 409 al completar no cambia el estado', async () => {
    stubFetch(() =>
      problemResponse(409, 'urn:kronoqr:problem:conflict', {
        detail: 'Faltan pasos por resolver.',
      }),
    )

    const store = useSetupStore()

    await expect(store.complete()).rejects.toBeTruthy()
    expect(store.status).toBeNull()
  })
})
