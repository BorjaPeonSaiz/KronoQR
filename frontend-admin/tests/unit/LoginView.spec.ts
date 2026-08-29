import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LoginView from '@/features/auth/LoginView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import type { FetchHandler } from './support/harness'
import {
  session as sessionFixture,
  twoFactorChallenge,
  twoFactorEnrolment,
} from './support/fixtures'
import {
  createTestPinia,
  createTestRouter,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

/** Enruta cada llamada al doble que le corresponde, por camino de la URL. */
function stubAuthFlow(handlers: Record<string, FetchHandler>): ReturnType<typeof stubFetch> {
  return stubFetch((url, init) => {
    const path = new URL(url, 'http://localhost').pathname
    const handler = Object.entries(handlers).find(([suffix]) => path.endsWith(suffix))?.[1]

    if (handler === undefined) {
      throw new Error(`Sin doble para ${path} en esta prueba`)
    }

    return handler(url, init)
  })
}

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

describe('LoginView — segundo factor (RS-06)', () => {
  it('con TOTP ya activo, pide el codigo y entra al verificarlo', async () => {
    stubAuthFlow({
      '/auth/login': () => jsonResponse(twoFactorChallenge({ enrolment_required: false }), 202),
      '/auth/2fa/verify': () => jsonResponse(sessionFixture()),
    })

    const pinia = createTestPinia()
    const wrapper = await mountView(LoginView, { pinia })

    await fillAndSubmit(wrapper)

    // Sesion todavia NO iniciada: el reto no es una sesion.
    expect(useSessionStore(pinia).isAuthenticated).toBe(false)
    expect(wrapper.text()).toContain(es.auth.twoFactor.codeHeading)
    expect(wrapper.find('input[name="code"]').attributes('inputmode')).toBe('numeric')
    expect(wrapper.find('input[name="code"]').attributes('autocomplete')).toBe('one-time-code')

    await wrapper.find('input[name="code"]').setValue('492013')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(useSessionStore(pinia).isAuthenticated).toBe(true)
  })

  it('sin TOTP todavia, da de alta el segundo factor con QR y secreto, y confirma con el primer codigo', async () => {
    const enrolment = twoFactorEnrolment()

    stubAuthFlow({
      '/auth/login': () => jsonResponse(twoFactorChallenge({ enrolment_required: true }), 202),
      '/auth/2fa/enrol': () => jsonResponse(enrolment),
      '/auth/2fa/confirm': () => jsonResponse(sessionFixture()),
    })

    const pinia = createTestPinia()
    const wrapper = await mountView(LoginView, { pinia })

    await fillAndSubmit(wrapper)
    await settle()

    expect(wrapper.text()).toContain(es.auth.twoFactor.enrolHeading)
    // El secreto se enseña en texto para quien no puede escanear el QR.
    expect(wrapper.find('[data-test="two-factor-secret"]').text()).toBe(enrolment.secret)
    expect(wrapper.find('svg[role="img"]').exists()).toBe(true)

    await wrapper.find('input[name="code"]').setValue('492013')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(useSessionStore(pinia).isAuthenticated).toBe(true)
  })

  it('un codigo equivocado da un aviso generico y vacia el campo, sin salir del paso', async () => {
    stubAuthFlow({
      '/auth/login': () => jsonResponse(twoFactorChallenge({ enrolment_required: false }), 202),
      '/auth/2fa/verify': () => problemResponse(401, 'urn:kronoqr:problem:invalid-credentials'),
    })

    const pinia = createTestPinia()
    const wrapper = await mountView(LoginView, { pinia })

    await fillAndSubmit(wrapper)

    await wrapper.find('input[name="code"]').setValue('000000')
    await wrapper.find('form').trigger('submit')
    await settle()

    // Sigue en el paso del codigo, con el mismo aviso generico que las
    // credenciales: nunca se distingue codigo equivocado de codigo reutilizado.
    expect(wrapper.text()).toContain(es.auth.twoFactor.codeHeading)
    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.invalidCredentials.title)
    expect((wrapper.find('input[name="code"]').element as HTMLInputElement).value).toBe('')
    expect(useSessionStore(pinia).isAuthenticated).toBe(false)
  })

  it('el bloqueo por intentos del codigo enseña cuanto hay que esperar', async () => {
    stubAuthFlow({
      '/auth/login': () => jsonResponse(twoFactorChallenge({ enrolment_required: false }), 202),
      '/auth/2fa/verify': () =>
        new Response(JSON.stringify({ type: 'urn:x', title: 'x', status: 429 }), {
          status: 429,
          headers: { 'Content-Type': 'application/problem+json', 'Retry-After': '60' },
        }),
    })

    const wrapper = await mountView(LoginView)

    await fillAndSubmit(wrapper)

    await wrapper.find('input[name="code"]').setValue('492013')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.rateLimited.title)
    expect(wrapper.find('[role="alert"]').text()).toContain('60')
  })

  it('un reto caducado o invalido vuelve al acceso con aviso, no al mismo paso', async () => {
    stubAuthFlow({
      '/auth/login': () => jsonResponse(twoFactorChallenge({ enrolment_required: false }), 202),
      '/auth/2fa/verify': () => problemResponse(401, 'urn:kronoqr:problem:unauthenticated'),
    })

    const pinia = createTestPinia()
    const wrapper = await mountView(LoginView, { pinia })

    await fillAndSubmit(wrapper)

    await wrapper.find('input[name="code"]').setValue('492013')
    await wrapper.find('form').trigger('submit')
    await settle()

    // De vuelta al primer paso, con el mismo aviso que una sesion caducada.
    expect(wrapper.text()).toContain(es.auth.heading)
    expect(wrapper.find('input[type="email"]').exists()).toBe(true)
    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.unauthenticated.title)
    expect(useSessionStore(pinia).isAuthenticated).toBe(false)
  })

  it('"Volver al acceso" abandona el reto sin llamar al servidor', async () => {
    const api = stubAuthFlow({
      '/auth/login': () => jsonResponse(twoFactorChallenge({ enrolment_required: false }), 202),
    })

    const wrapper = await mountView(LoginView)

    await fillAndSubmit(wrapper)
    expect(wrapper.text()).toContain(es.auth.twoFactor.codeHeading)

    await wrapper.find('button[type="button"]').trigger('click')
    await settle()

    expect(wrapper.text()).toContain(es.auth.heading)
    expect(wrapper.find('input[type="email"]').exists()).toBe(true)
    // Ni un fallo de red ni nada que auditar: el reto simplemente se abandona.
    expect(api.mock.calls.filter(([url]) => String(url).includes('2fa')).length).toBe(0)
  })

  it('el challenge_token nunca sobrevive a una recarga: nace vacio en cada montaje', async () => {
    stubAuthFlow({
      '/auth/login': () => jsonResponse(twoFactorChallenge({ enrolment_required: false }), 202),
    })

    const wrapper = await mountView(LoginView)

    await fillAndSubmit(wrapper)
    expect(wrapper.text()).toContain(es.auth.twoFactor.codeHeading)

    // El reto no vive en `sessionStorage`: no hay nada que una recarga real
    // pudiera resucitar. Simular la recarga es, por eso, simplemente montar
    // la vista de nuevo: empieza en el primer paso porque no hay estado que
    // recuperar de ningun sitio.
    expect(window.sessionStorage.getItem('kronoqr.admin.session')).toBeNull()

    const freshWrapper = await mountView(LoginView)

    expect(freshWrapper.text()).toContain(es.auth.heading)
    expect(freshWrapper.text()).not.toContain(es.auth.twoFactor.codeHeading)
  })
})
