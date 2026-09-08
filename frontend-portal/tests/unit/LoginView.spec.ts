import { PRODUCT_BRANDING } from '@kronoqr/web-kit/branding'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LoginView from '@/features/login/LoginView.vue'
import { useSessionStore } from '@/features/login/session.store'
import { useBrandingStore } from '@/shared/branding/branding.store'
import type { Branding } from '@/shared/api/types'
import es from '@/shared/i18n/locales/es.json'
import { brandingPayload, portalSession } from './support/fixtures'
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

async function fillAndSubmit(
  wrapper: Awaited<ReturnType<typeof mountView>>,
  pin = '284016',
): Promise<void> {
  await wrapper.find('input[type="text"]').setValue('E7K2M9XQ4')
  await wrapper.find('input[type="password"]').setValue(pin)
  await wrapper.find('form').trigger('submit')
  await settle()
}

/**
 * Simula `GET /api/v1/branding` y carga el store, igual que hace `main.ts` al
 * arrancar. El componente `BrandMark` de `@kronoqr/web-kit` no lee ninguna
 * tienda: recibe la marca por prop, asi que la pantalla necesita el store ya
 * cargado antes de montarse.
 */
async function loadBranding(
  pinia: ReturnType<typeof createTestPinia>,
  overrides: Partial<Branding> = {},
): Promise<void> {
  stubFetch((url) =>
    url.includes('/api/v1/branding') ? jsonResponse(brandingPayload(overrides)) : jsonResponse({}),
  )
  await useBrandingStore(pinia).load()
}

describe('LoginView', () => {
  it('sin marca configurada enseña el nombre del producto encima del titulo', async () => {
    const wrapper = await mountView(LoginView)

    expect(wrapper.text()).toContain(PRODUCT_BRANDING.applicationName)
    expect(wrapper.find('img').exists()).toBe(false)

    const nameIndex = wrapper.text().indexOf(PRODUCT_BRANDING.applicationName)
    const headingIndex = wrapper.text().indexOf(es.login.heading)

    expect(nameIndex).toBeGreaterThanOrEqual(0)
    expect(nameIndex).toBeLessThan(headingIndex)
  })

  it('con logotipo configurado lo enseña con su nombre como alt, encima del titulo', async () => {
    const pinia = createTestPinia()

    await loadBranding(pinia)

    const wrapper = await mountView(LoginView, { pinia })

    const img = wrapper.find('[data-testid="brand-mark"]')
    const heading = wrapper.find('h1')

    expect(img.element.tagName).toBe('IMG')
    expect(img.attributes('alt')).toBe('Hotel Marina')
    // El logotipo va antes del titulo en el DOM (orden de aparicion).
    expect(
      img.element.compareDocumentPosition(heading.element) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
  })

  it('un nombre de 60 caracteres se trunca en lugar de salirse de la pantalla en un movil', async () => {
    const pinia = createTestPinia()
    // 60 caracteres: el maximo del contrato (Branding.application_name).
    const longName = 'H'.repeat(59) + 'o'

    await loadBranding(pinia, { application_name: longName, accent_color: null, logo_url: null })

    const wrapper = await mountView(LoginView, { pinia })
    const name = wrapper.find('[data-testid="brand-mark"]')

    expect(name.element.tagName).toBe('P')
    expect(name.text()).toBe(longName)
    expect(name.classes()).toEqual(expect.arrayContaining(['truncate', 'min-w-0', 'max-w-full']))
    // El nombre completo sigue disponible aunque el texto se recorte visualmente.
    expect(name.attributes('title')).toBe(longName)
  })

  it('etiqueta los dos campos y no usa el marcador de posicion como etiqueta', async () => {
    const wrapper = await mountView(LoginView)

    const codeId = wrapper.find('input[type="text"]').attributes('id')
    const pinId = wrapper.find('input[type="password"]').attributes('id')
    const labels = wrapper.findAll('label').map((label) => label.attributes('for'))

    expect(labels).toContain(codeId)
    expect(labels).toContain(pinId)
  })

  it('dice que la recuperacion la hace RRHH, nunca por correo', async () => {
    const wrapper = await mountView(LoginView)

    expect(wrapper.text()).toContain(es.login.forgotPin)
  })

  it('abre sesion y lleva a mi registro cuando no se pedia otra pantalla', async () => {
    stubFetch(() => jsonResponse(portalSession()))

    const pinia = createTestPinia()
    const router = createTestRouter()

    await router.push('/login')

    const wrapper = await mountView(LoginView, { pinia, router })

    await fillAndSubmit(wrapper)

    expect(useSessionStore(pinia).isAuthenticated).toBe(true)
    expect(router.currentRoute.value.path).toBe('/records')
  })

  it('lleva a la pantalla que se pedia antes de exigir el acceso', async () => {
    stubFetch(() => jsonResponse(portalSession()))

    const router = createTestRouter()

    await router.push('/login?redirect=/export')

    const wrapper = await mountView(LoginView, { router })

    await fillAndSubmit(wrapper)

    expect(router.currentRoute.value.path).toBe('/export')
  })

  it('no distingue un codigo inexistente de un PIN incorrecto ni de un bloqueo por intentos', async () => {
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:invalid-credentials'))

    const wrapper = await mountView(LoginView)

    await fillAndSubmit(wrapper)

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.invalidCredentials.title)
    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.invalidCredentials.advice)
  })

  it('no enseña ningun tiempo de espera para el bloqueo del PIN: el servidor no lo revela', async () => {
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:invalid-credentials'))

    const wrapper = await mountView(LoginView)

    await fillAndSubmit(wrapper)

    const fixedPrefix = es.errors.retryAfter.slice(0, es.errors.retryAfter.indexOf('{'))

    expect(wrapper.text()).not.toContain(fixedPrefix)
  })

  it('el limite de peticiones por IP si dice cuanto esperar, que es un mecanismo distinto', async () => {
    stubFetch(
      () =>
        new Response(JSON.stringify({ type: 'urn:x', title: 'x', status: 429 }), {
          status: 429,
          headers: { 'Content-Type': 'application/problem+json', 'Retry-After': '30' },
        }),
    )

    const wrapper = await mountView(LoginView)

    await fillAndSubmit(wrapper)

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.rateLimited.title)
    expect(wrapper.find('[role="alert"]').text()).toContain('30')
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

  it('vacia el PIN tras cualquier intento, acierte o falle', async () => {
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:invalid-credentials'))

    const wrapper = await mountView(LoginView)

    await fillAndSubmit(wrapper)

    expect((wrapper.find('input[type="password"]').element as HTMLInputElement).value).toBe('')
  })

  it('no deja enviar un PIN que no tiene seis digitos', async () => {
    const spy = stubFetch(() => jsonResponse(portalSession()))
    const wrapper = await mountView(LoginView)

    await wrapper.find('input[type="text"]').setValue('E7K2M9XQ4')
    await wrapper.find('input[type="password"]').setValue('123')
    await settle(1)

    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('form').trigger('submit')
    await settle(1)

    expect(spy).not.toHaveBeenCalled()
  })
})
