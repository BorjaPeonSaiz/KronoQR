// La sesion que se acaba sola (V3-PO-1, V21-3-PO-3, V2-PO-4).
//
// Dos caminos y un destino: la caducidad de `expires_at` (temporizador) y el
// `401` con la sesion abierta terminan en el acceso, con el aviso, y al
// entrar de nuevo se vuelve a la ruta de origen.
import { requestJson, setAuthTokenProvider } from '@kronoqr/web-kit/http'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LoginView from '@/features/login/LoginView.vue'
import { useSessionStore } from '@/features/login/session.store'
import { registerAuthGuard } from '@/router/guards'
import es from '@/shared/i18n/locales/es.json'
import type { SessionLifecycleOptions } from '@/shared/session/sessionLifecycle'
import { bindSessionLifecycle } from '@/shared/session/sessionLifecycle'
import { useLocaleChoiceStore } from '@/shared/i18n/locale.store'
import { portalSession } from './support/fixtures'
import {
  createTestPinia,
  createTestRouter,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

const STORAGE_KEY = 'kronoqr.portal.session'
const NOW = new Date('2026-03-20T10:00:00.000Z')

// Cada prueba suelta lo que ata (gestor de 401, escucha de la pestana), pase o falle.
const stops: Array<() => void> = []

function bind(options: SessionLifecycleOptions): () => void {
  const stop = bindSessionLifecycle(options)

  stops.push(stop)

  return stop
}

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  stops.splice(0).forEach((stop) => stop())
  vi.useRealTimers()
  vi.unstubAllGlobals()
  setAuthTokenProvider(() => null)
})

function inTwoHours(): string {
  return new Date(NOW.getTime() + 2 * 3_600_000).toISOString()
}

async function openSession(expiresAt = inTwoHours()): Promise<ReturnType<typeof useSessionStore>> {
  stubFetch(() => jsonResponse(portalSession({ expires_at: expiresAt })))
  const session = useSessionStore()

  await session.logIn({ employee_code: 'E7K2M9XQ4', pin: '284016' })

  return session
}

describe('caducidad por tiempo', () => {
  it('cierra la sesion sola al llegar a expires_at, sin esperar a ninguna peticion', async () => {
    vi.useFakeTimers({ now: NOW })
    createTestPinia()
    const session = await openSession()

    expect(session.isAuthenticated).toBe(true)

    vi.advanceTimersByTime(2 * 3_600_000 - 1)
    expect(session.isAuthenticated).toBe(true)

    vi.advanceTimersByTime(1)

    expect(session.isAuthenticated).toBe(false)
    expect(session.endedReason).toBe('expired')
    expect(session.token).toBeNull()
    expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('al volver a la pestana comprueba la caducidad aunque el temporizador durmiera', async () => {
    vi.useFakeTimers({ now: NOW })
    createTestPinia()
    const router = createTestRouter()
    const session = await openSession()
    const stop = bind({ router, session })

    // El movil durmio: el reloj salta sin que el temporizador llegue a disparar.
    vi.setSystemTime(new Date(NOW.getTime() + 3 * 3_600_000))
    document.dispatchEvent(new Event('visibilitychange'))

    expect(session.isAuthenticated).toBe(false)
    expect(session.endedReason).toBe('expired')
    stop()
  })

  it('una sesion que cerro la persona con «Salir» no deja ningun aviso de caducidad', async () => {
    vi.useFakeTimers({ now: NOW })
    createTestPinia()
    const session = await openSession()

    stubFetch(() => new Response(null, { status: 204 }))
    await session.signOut()
    vi.advanceTimersByTime(3 * 3_600_000)

    expect(session.endedReason).toBeNull()
  })

  it('un 401 que llega despues de salir no se toma por una caducidad', async () => {
    createTestPinia()
    const session = useSessionStore()

    session.expire()

    expect(session.endedReason).toBeNull()
  })
})

describe('401 con la sesion abierta', () => {
  it('lleva al acceso con el aviso y, al entrar, vuelve a la ruta de origen', async () => {
    const pinia = createTestPinia()
    const router = createTestRouter()

    registerAuthGuard(router)
    const session = await openSession('2099-01-01T00:00:00Z')

    bind({ router, session })
    setAuthTokenProvider(() => session.token)

    await router.push('/export')
    await router.isReady()
    expect(router.currentRoute.value.name).toBe('my-export')

    // El servidor ya no reconoce el token (revocado o vencido de su lado).
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:unauthenticated'))
    await expect(requestJson('/api/v1/me/workdays')).rejects.toMatchObject({ status: 401 })
    await settle()

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query['redirect']).toBe('/export')
    expect(session.isAuthenticated).toBe(false)

    const wrapper = await mountView(LoginView, { pinia, router })

    expect(wrapper.find('[data-test="session-expired"]').text()).toContain(
      es.login.sessionExpired.title,
    )

    // Entra de nuevo: el aviso desaparece y vuelve a donde estaba.
    stubFetch(() => jsonResponse(portalSession()))
    await wrapper.find('input[type="text"]').setValue('E7K2M9XQ4')
    await wrapper.find('input[type="password"]').setValue('284016')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(router.currentRoute.value.name).toBe('my-export')
    expect(session.endedReason).toBeNull()
  })

  it('un 401 del propio acceso (credenciales malas) no se toma por una sesion caducada', async () => {
    const pinia = createTestPinia()
    const router = createTestRouter()
    const session = useSessionStore()

    bind({ router, session })
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:invalid-credentials'))

    const wrapper = await mountView(LoginView, { pinia, router })

    await wrapper.find('input[type="text"]').setValue('E7K2M9XQ4')
    await wrapper.find('input[type="password"]').setValue('000000')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="session-expired"]').exists()).toBe(false)
    expect(session.endedReason).toBeNull()
  })

  const REDIRECTS: ReadonlyArray<readonly [string, string, string]> = [
    ['otro origen con //', '//otro-sitio.example/robo', '/records'],
    ['barra invertida', '/\\otro-sitio.example', '/records'],
    ['tabulador', '/\totro-sitio.example', '/records'],
    ['salto de linea', '/records\nSet-Cookie: x', '/records'],
    ['URL absoluta', 'https://otro-sitio.example/records', '/records'],
    ['el propio acceso', '/login', '/records'],
    ['el acceso con otra capitalizacion', '/LOGIN', '/records'],
    ['una ruta que no existe', '/no-existe', '/records'],
    ['una ruta propia con consulta', '/export?x=1', '/export?x=1'],
  ]

  it.each(REDIRECTS)('redireccion: %s', async (_name, redirect, expected) => {
    const pinia = createTestPinia()
    const router = createTestRouter()

    await router.push({ name: 'login', query: { redirect } })
    stubFetch(() => jsonResponse(portalSession()))

    const wrapper = await mountView(LoginView, { pinia, router })

    await wrapper.find('input[type="text"]').setValue('E7K2M9XQ4')
    await wrapper.find('input[type="password"]').setValue('284016')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(router.currentRoute.value.fullPath).toBe(expected)
  })
})

describe('«Salir» con un 401 concurrente', () => {
  it('un 401 que llega mientras se espera al servidor no se toma por una caducidad', async () => {
    createTestPinia()
    const session = await openSession('2099-01-01T00:00:00Z')

    let release: (response: Response) => void = () => {}

    stubFetch(
      () =>
        new Promise<Response>((resolve) => {
          release = resolve
        }),
    )

    const leaving = session.signOut()

    // Mientras el logout sigue en vuelo, otra peticion recibe su 401.
    session.expire()
    release(new Response(null, { status: 204 }))
    await leaving

    expect(session.endedReason).toBeNull()
    expect(session.isAuthenticated).toBe(false)
  })
})

describe('caducidad medida con la hora del servidor', () => {
  it('un dispositivo con el reloj adelantado no cierra la sesion al instante', async () => {
    // El servidor dice que son las 10:00 y la sesion dura 2 h; el movil cree que son las 13:00.
    vi.useFakeTimers({ now: new Date('2026-03-20T13:00:00.000Z') })
    createTestPinia()
    stubFetch(
      () =>
        new Response(JSON.stringify(portalSession({ expires_at: '2026-03-20T12:00:00.000Z' })), {
          status: 200,
          headers: {
            'Content-Type': 'application/json',
            Date: 'Fri, 20 Mar 2026 10:00:00 GMT',
          },
        }),
    )
    const session = useSessionStore()

    await session.logIn({ employee_code: 'E7K2M9XQ4', pin: '284016' })

    expect(session.isAuthenticated).toBe(true)

    vi.advanceTimersByTime(2 * 3_600_000 - 1)
    expect(session.isAuthenticated).toBe(true)

    vi.advanceTimersByTime(1)
    expect(session.isAuthenticated).toBe(false)
    expect(session.endedReason).toBe('expired')
  })

  it('un dispositivo con el reloj atrasado no la deja abierta de mas', async () => {
    vi.useFakeTimers({ now: new Date('2026-03-20T07:00:00.000Z') })
    createTestPinia()
    stubFetch(
      () =>
        new Response(JSON.stringify(portalSession({ expires_at: '2026-03-20T12:00:00.000Z' })), {
          status: 200,
          headers: {
            'Content-Type': 'application/json',
            Date: 'Fri, 20 Mar 2026 10:00:00 GMT',
          },
        }),
    )
    const session = useSessionStore()

    await session.logIn({ employee_code: 'E7K2M9XQ4', pin: '284016' })
    vi.advanceTimersByTime(2 * 3_600_000)

    expect(session.isAuthenticated).toBe(false)
  })

  it('sin cabecera Date se usa la caducidad del servidor tal cual', async () => {
    vi.useFakeTimers({ now: NOW })
    createTestPinia()
    const session = await openSession()

    vi.advanceTimersByTime(2 * 3_600_000)

    expect(session.isAuthenticated).toBe(false)
  })
})

describe('idioma elegido a mano', () => {
  it('se vacia al salir y al caducar, no al entrar', async () => {
    const pinia = createTestPinia()
    const choice = useLocaleChoiceStore(pinia)
    const session = await openSession('2099-01-01T00:00:00Z')

    choice.choose('en')
    expect(choice.chosen).toBe('en')

    stubFetch(() => jsonResponse(portalSession()))
    await session.logIn({ employee_code: 'E7K2M9XQ4', pin: '284016' })
    expect(choice.chosen).toBe('en')

    session.expire()
    expect(choice.chosen).toBeNull()

    await session.logIn({ employee_code: 'E7K2M9XQ4', pin: '284016' })
    choice.choose('en')
    stubFetch(() => new Response(null, { status: 204 }))
    await session.signOut()

    expect(choice.chosen).toBeNull()
  })
})
