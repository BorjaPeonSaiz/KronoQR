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
import { bindSessionLifecycle } from '@/shared/session/sessionLifecycle'
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

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
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
    const stop = bindSessionLifecycle({ router, session })

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

    bindSessionLifecycle({ router, session })
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

    bindSessionLifecycle({ router, session })
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:invalid-credentials'))

    const wrapper = await mountView(LoginView, { pinia, router })

    await wrapper.find('input[type="text"]').setValue('E7K2M9XQ4')
    await wrapper.find('input[type="password"]').setValue('000000')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="session-expired"]').exists()).toBe(false)
    expect(session.endedReason).toBeNull()
  })

  it('no sigue una redireccion hacia otro origen', async () => {
    const pinia = createTestPinia()
    const router = createTestRouter()

    await router.push({ name: 'login', query: { redirect: '//otro-sitio.example/robo' } })
    stubFetch(() => jsonResponse(portalSession()))

    const wrapper = await mountView(LoginView, { pinia, router })

    await wrapper.find('input[type="text"]').setValue('E7K2M9XQ4')
    await wrapper.find('input[type="password"]').setValue('284016')
    await wrapper.find('form').trigger('submit')
    await settle()

    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/records'))
  })
})
