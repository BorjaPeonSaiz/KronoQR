// «Salir» cierra la sesion tambien en el servidor (RF-ID-05, RF-ID-07, PO1).
//
// Lo que se protege: en el ordenador compartido del centro un token vivo es el
// turno de otra persona leyendo estas horas. Y lo contrario, igual de
// importante: ningun desenlace de la llamada -204, 401, 403, 429, error de red
// o tiempo agotado- puede dejar la sesion local abierta.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { LOGOUT_TIMEOUT_MS, useSessionStore } from '@/features/login/session.store'
import { setAuthTokenProvider, setUnauthenticatedHandler } from '@kronoqr/web-kit/http'
import { portalSession } from './support/fixtures'
import { createTestPinia, jsonResponse, problemResponse, stubFetch } from './support/harness'

const STORAGE_KEY = 'kronoqr.portal.session'
const TOKEN = portalSession().token

async function signedInStore(): Promise<ReturnType<typeof useSessionStore>> {
  stubFetch(() => jsonResponse(portalSession()))

  const session = useSessionStore()

  await session.logIn({ employee_code: 'E7K2M9XQ4', pin: '284016' })
  setAuthTokenProvider(() => session.token)

  return session
}

beforeEach(() => {
  window.sessionStorage.clear()
  createTestPinia()
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  setAuthTokenProvider(() => null)
  setUnauthenticatedHandler(() => {})
})

describe('PortalSignOut, cierre de sesion en el servidor (RF-ID-05, RF-ID-07)', () => {
  it('llama a POST /me/logout con el Bearer de la sesion, sin cuerpo, y borra lo local', async () => {
    const session = await signedInStore()
    const spy = stubFetch(() => new Response(null, { status: 204 }))

    await session.signOut()

    expect(spy).toHaveBeenCalledTimes(1)

    const [url, init] = spy.mock.calls[0] as [string, RequestInit]
    const headers = new Headers(init.headers)

    expect(url).toBe('/api/v1/me/logout')
    expect(init.method).toBe('POST')
    expect(init.body).toBeUndefined()
    expect(headers.get('Authorization')).toBe(`Bearer ${TOKEN}`)
    expect(session.isAuthenticated).toBe(false)
    expect(session.token).toBeNull()
    expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it.each([
    ['401, ya no habia sesion', () => problemResponse(401, 'about:blank')],
    ['403, token de otro tipo', () => problemResponse(403, 'about:blank')],
    ['429, limite de nginx', () => problemResponse(429, 'about:blank')],
    ['500, fallo del servidor', () => problemResponse(500, 'about:blank')],
  ])('con un %s borra igualmente la sesion local', async (_name, respond) => {
    const session = await signedInStore()

    stubFetch(respond)
    await expect(session.signOut()).resolves.toBeUndefined()

    expect(session.isAuthenticated).toBe(false)
    expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('con un error de red borra igualmente la sesion local', async () => {
    const session = await signedInStore()

    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })
    await session.signOut()

    expect(session.isAuthenticated).toBe(false)
    expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('un 401 al salir no dispara el cierre global: lo gestiona la propia salida', async () => {
    const session = await signedInStore()
    const unauthenticated = vi.fn()

    setUnauthenticatedHandler(unauthenticated)
    stubFetch(() => problemResponse(401, 'about:blank'))
    await session.signOut()

    expect(unauthenticated).not.toHaveBeenCalled()
  })

  it('si el servidor no responde, sale igualmente al agotarse el tiempo maximo', async () => {
    const session = await signedInStore()

    vi.useFakeTimers()
    stubFetch(
      (_url, init) =>
        new Promise<Response>((_resolve, reject) => {
          init?.signal?.addEventListener('abort', () => reject(new DOMException('', 'AbortError')))
        }),
    )

    const leaving = session.signOut()

    // Justo antes del tiempo maximo, la sesion sigue ahi: se espera al servidor.
    await vi.advanceTimersByTimeAsync(LOGOUT_TIMEOUT_MS - 1)
    expect(session.isAuthenticated).toBe(true)

    await vi.advanceTimersByTimeAsync(1)
    await leaving

    expect(session.isAuthenticated).toBe(false)
    expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('sin sesion no llama a nadie y no falla', async () => {
    const spy = stubFetch(() => new Response(null, { status: 204 }))

    await useSessionStore().signOut()

    expect(spy).not.toHaveBeenCalled()
  })

  it('el token solo viaja en la cabecera, nunca en la URL', async () => {
    const session = await signedInStore()
    const spy = stubFetch(() => new Response(null, { status: 204 }))

    await session.signOut()

    expect(String(spy.mock.calls[0]?.[0])).not.toContain(TOKEN)
  })
})
