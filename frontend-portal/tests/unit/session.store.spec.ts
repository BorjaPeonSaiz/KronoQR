import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/features/login/session.store'
import { setAuthTokenProvider, setUnauthenticatedHandler } from '@kronoqr/web-kit/http'
import { portalEmployee, portalSession } from './support/fixtures'
import { createTestPinia, jsonResponse, problemResponse, stubFetch } from './support/harness'

const STORAGE_KEY = 'kronoqr.portal.session'

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

describe('tienda de sesion del portal', () => {
  it('guarda el token y quien ha entrado al abrir sesion', async () => {
    stubFetch(() => jsonResponse(portalSession()))

    const session = useSessionStore()

    await session.logIn({ employee_code: 'E7K2M9XQ4', pin: '284016' })

    expect(session.isAuthenticated).toBe(true)
    expect(session.employee?.display_name).toBe('Lucía Gómez Ruiz')
  })

  it('nunca manda el PIN en el cuerpo con un campo distinto del que ha escrito quien entra', async () => {
    const spy = stubFetch(() => jsonResponse(portalSession()))

    await useSessionStore().logIn({ employee_code: 'E7K2M9XQ4', pin: '284016' })

    const body = JSON.parse((spy.mock.calls[0]?.[1] as RequestInit).body as string) as {
      employee_code: string
      pin: string
    }

    expect(body).toEqual({ employee_code: 'E7K2M9XQ4', pin: '284016' })
  })

  it('persiste en sessionStorage sus propios datos, pero nunca el PIN', async () => {
    stubFetch(() => jsonResponse(portalSession()))

    await useSessionStore().logIn({ employee_code: 'E7K2M9XQ4', pin: '284016' })

    const stored = window.sessionStorage.getItem(STORAGE_KEY) ?? ''

    // Son los datos de la propia persona (regla dura 21 protege a terceros, no
    // a quien ya los tiene delante en su propio dispositivo).
    expect(stored).toContain('Lucía Gómez Ruiz')
    expect(stored).toContain('token')
    // El PIN nunca llega a este fichero, acierte o falle.
    expect(stored).not.toContain('284016')
    expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('descarta un token caducado sin llamar al servidor', () => {
    window.sessionStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        token: 'viejo',
        expiresAt: '2000-01-01T00:00:00Z',
        employee: portalEmployee(),
      }),
    )
    createTestPinia()
    const spy = stubFetch(() => jsonResponse(portalEmployee()))

    const session = useSessionStore()

    expect(session.isAuthenticated).toBe(false)
    expect(spy).not.toHaveBeenCalled()
  })

  it('recupera una sesion todavia vigente sin pedir nada al servidor', () => {
    window.sessionStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        token: 'vigente',
        expiresAt: '2099-01-01T00:00:00Z',
        employee: portalEmployee({ display_name: 'Youssef Amrani' }),
      }),
    )
    createTestPinia()

    const session = useSessionStore()

    expect(session.isAuthenticated).toBe(true)
    expect(session.employee?.display_name).toBe('Youssef Amrani')
  })

  it('ignora un almacenamiento ilegible en lugar de impedir entrar', () => {
    window.sessionStorage.setItem(STORAGE_KEY, 'esto no es json')
    createTestPinia()

    expect(useSessionStore().isAuthenticated).toBe(false)
  })

  it('ignora un almacenamiento con la forma incompleta', () => {
    window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ token: 'x' }))
    createTestPinia()

    expect(useSessionStore().isAuthenticated).toBe(false)
  })

  it('rechaza cualquier rechazo del acceso con el mismo tipo de error, sin distinguir causa', async () => {
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:invalid-credentials'))

    const session = useSessionStore()

    await expect(
      session.logIn({ employee_code: 'E7K2M9XQ4', pin: '000000' }),
    ).rejects.toMatchObject({ kind: 'invalidCredentials' })
    expect(session.isAuthenticated).toBe(false)
  })

  it('cerrar sesion solo la olvida en este dispositivo: no hay endpoint de portal que la revoque', async () => {
    stubFetch(() => jsonResponse(portalSession()))

    const session = useSessionStore()

    await session.logIn({ employee_code: 'E7K2M9XQ4', pin: '284016' })
    const spy = stubFetch(() => jsonResponse({ ok: true }))

    session.signOutLocally()

    expect(session.isAuthenticated).toBe(false)
    expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull()
    expect(spy).not.toHaveBeenCalled()
  })
})
