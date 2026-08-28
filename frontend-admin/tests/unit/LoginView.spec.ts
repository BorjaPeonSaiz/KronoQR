import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LoginView from '@/features/auth/LoginView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import { session as sessionFixture } from './support/fixtures'
import {
  createTestPinia,
  createTestRouter,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

async function fillAndSubmit(wrapper: Awaited<ReturnType<typeof mountView>>): Promise<void> {
  await wrapper.find('input[type="email"]').setValue('rrhh@hotel.example')
  await wrapper.find('input[type="password"]').setValue('una-contrasena-larga')
  await wrapper.find('form').trigger('submit')
  await settle()
}

describe('LoginView', () => {
  it('etiqueta los dos campos y no usa el marcador de posicion como etiqueta', async () => {
    const wrapper = await mountView(LoginView)

    const emailId = wrapper.find('input[type="email"]').attributes('id')
    const passwordId = wrapper.find('input[type="password"]').attributes('id')
    const labels = wrapper.findAll('label').map((label) => label.attributes('for'))

    expect(labels).toContain(emailId)
    expect(labels).toContain(passwordId)
  })

  it('abre sesion y lleva a donde se queria ir', async () => {
    stubFetch(() => jsonResponse(sessionFixture()))

    const pinia = createTestPinia()
    const router = createTestRouter()

    await router.push('/login?redirect=/credentials')

    const wrapper = await mountView(LoginView, { pinia, router })

    await fillAndSubmit(wrapper)

    expect(useSessionStore(pinia).isAuthenticated).toBe(true)
    expect(router.currentRoute.value.path).toBe('/credentials')
  })

  it('no distingue una contrasena incorrecta de una cuenta que no existe', async () => {
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:invalid-credentials'))

    const wrapper = await mountView(LoginView)

    await fillAndSubmit(wrapper)

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.invalidCredentials.title)
    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.invalidCredentials.advice)
  })

  it('explica el bloqueo por intentos con el tiempo de espera', async () => {
    stubFetch(
      () =>
        new Response(JSON.stringify({ type: 'urn:x', title: 'x', status: 429 }), {
          status: 429,
          headers: { 'Content-Type': 'application/problem+json', 'Retry-After': '60' },
        }),
    )

    const wrapper = await mountView(LoginView)

    await fillAndSubmit(wrapper)

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.rateLimited.title)
    expect(wrapper.find('[role="alert"]').text()).toContain('60')
  })

  it('dice que ha pasado y que hacer cuando no hay red', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const wrapper = await mountView(LoginView)

    await fillAndSubmit(wrapper)

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.advice)
  })

  it('vacia la contrasena tras un fallo, para no dejarla escrita en pantalla', async () => {
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:invalid-credentials'))

    const wrapper = await mountView(LoginView)

    await fillAndSubmit(wrapper)

    expect((wrapper.find('input[type="password"]').element as HTMLInputElement).value).toBe('')
  })

  it('redirige a la plantilla cuando no se pedia ninguna pantalla concreta', async () => {
    stubFetch(() => jsonResponse(sessionFixture()))

    const router = createTestRouter()

    await router.push('/login')

    const wrapper = await mountView(LoginView, { router })

    await fillAndSubmit(wrapper)

    expect(router.currentRoute.value.path).toBe('/employees')
  })
})
