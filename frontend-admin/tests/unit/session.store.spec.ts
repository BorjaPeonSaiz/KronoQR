import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/features/auth/session.store'
import { setAuthTokenProvider, setUnauthenticatedHandler } from '@/shared/api/http'
import { managementUser, session as sessionFixture } from './support/fixtures'
import { createTestPinia, jsonResponse, problemResponse, stubFetch } from './support/harness'

const STORAGE_KEY = 'kronoqr.admin.session'

beforeEach(() => {
  window.sessionStorage.clear()
  window.localStorage.clear()
  createTestPinia()
})

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthTokenProvider(() => null)
  setUnauthenticatedHandler(() => {})
})

describe('tienda de sesion', () => {
  it('guarda el token y el usuario al entrar', async () => {
    stubFetch(() => jsonResponse(sessionFixture()))

    const session = useSessionStore()

    await session.logIn({ email: 'rrhh@hotel.example', password: 'x' })

    expect(session.isAuthenticated).toBe(true)
    expect(session.displayName).toBe('Direccion RRHH')
    expect(session.can('employees:*')).toBe(true)
    expect(session.can('reports:legal')).toBe(false)
  })

  it('persiste el token en sessionStorage y NUNCA datos personales', async () => {
    stubFetch(() => jsonResponse(sessionFixture()))

    await useSessionStore().logIn({ email: 'rrhh@hotel.example', password: 'x' })

    const stored = window.sessionStorage.getItem(STORAGE_KEY) ?? ''

    expect(stored).toContain('token')
    expect(stored).not.toContain('rrhh@hotel.example')
    expect(stored).not.toContain('Direccion RRHH')
    expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('recupera la sesion preguntando quien es, que ademas valida el token', async () => {
    window.sessionStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({ token: 'un-token', expiresAt: '2099-01-01T00:00:00Z' }),
    )
    createTestPinia()
    stubFetch(() => jsonResponse(managementUser()))

    const session = useSessionStore()

    expect(session.status).toBe('unknown')
    await session.restore()

    expect(session.status).toBe('authenticated')
    expect(session.roles).toEqual(['rrhh'])
  })

  it('descarta un token caducado sin llamar al servidor', async () => {
    window.sessionStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({ token: 'viejo', expiresAt: '2000-01-01T00:00:00Z' }),
    )
    createTestPinia()
    const spy = stubFetch(() => jsonResponse(managementUser()))

    const session = useSessionStore()

    await session.restore()

    expect(spy).not.toHaveBeenCalled()
    expect(session.isAuthenticated).toBe(false)
  })

  it('descarta un token que el servidor rechaza', async () => {
    window.sessionStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({ token: 'revocado', expiresAt: '2099-01-01T00:00:00Z' }),
    )
    createTestPinia()
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:unauthenticated'))

    const session = useSessionStore()

    await session.restore()

    expect(session.isAuthenticated).toBe(false)
    expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('un corte de red al arrancar no invalida la sesion guardada', async () => {
    window.sessionStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({ token: 'valido', expiresAt: '2099-01-01T00:00:00Z' }),
    )
    createTestPinia()
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const session = useSessionStore()

    await session.restore()

    expect(session.token).toBe('valido')
    expect(session.status).toBe('unknown')
  })

  it('ignora un almacenamiento ilegible en lugar de impedir entrar', () => {
    window.sessionStorage.setItem(STORAGE_KEY, 'esto no es json')
    createTestPinia()

    expect(useSessionStore().isAuthenticated).toBe(false)
  })

  it('cierra la sesion aunque el servidor conteste que ya no existia', async () => {
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:unauthenticated'))

    const session = useSessionStore()

    await session.logOut()

    expect(session.isAuthenticated).toBe(false)
    expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull()
  })
})
