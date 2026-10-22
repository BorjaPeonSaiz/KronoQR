import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AdministratorStep from '@/features/onboarding/steps/AdministratorStep.vue'
import { useSessionStore } from '@/features/auth/session.store'
import { useSetupStore } from '@/features/onboarding/setup.store'
import es from '@/shared/i18n/locales/es.json'
import {
  session,
  setupStatus,
  setupSteps,
  twoFactorChallenge,
  twoFactorEnrolment,
} from './support/fixtures'
import { loadBrandingWithLocales } from './support/branding'
import { createTestPinia, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

// Paso 1 del asistente: el primer administrador (RF-PD-03, regla dura 6).

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

async function fillForm(wrapper: Awaited<ReturnType<typeof mountView>>): Promise<void> {
  await wrapper.find('input[autocomplete="name"]').setValue('Dirección del hotel')
  await wrapper.find('input[type="email"]').setValue('direccion@hotel.example')
  await wrapper.find('input[type="password"]').setValue('una-contrasena-larga-y-propia-1!')
  await wrapper.find('form').trigger('submit')
  await settle()
}

describe('AdministratorStep', () => {
  it('crea la cuenta, da de alta el segundo factor y refresca el estado del asistente', async () => {
    const pinia = createTestPinia()
    const enrolment = twoFactorEnrolment()

    stubRoutes({
      '/setup/administrator': () =>
        jsonResponse(twoFactorChallenge({ enrolment_required: true }), 201),
      '/auth/2fa/enrol': () => jsonResponse(enrolment),
      '/auth/2fa/confirm': () => jsonResponse(session()),
      // `refresh()` (llamado tras confirmar el segundo factor) ya tiene sesion
      // en ese momento —`session.applySession` va justo antes en
      // `onEnrolled`—, asi que `setup.store` pide `GET /setup/steps`
      // (autenticada), no `GET /setup/status` (revision de la 5.5).
      '/setup/steps': () =>
        jsonResponse(setupStatus({ steps: setupSteps({ administrator: { state: 'completed' } }) })),
    })

    const wrapper = await mountView(AdministratorStep, { pinia })

    await fillForm(wrapper)
    // Dos vueltas: la primera crea la cuenta y monta `TwoFactorEnrolPanel`; la
    // segunda es su propia carga del secreto TOTP (con importacion dinamica
    // del renderizador del QR), que ese componente dispara en su `onMounted`.
    // Con las vistas del router cargadas por import() ya no se calientan los
    // modulos de este arbol de antemano: se espera a la condicion, no a un
    // numero de vueltas.
    await vi.waitFor(() => {
      expect(wrapper.text()).toContain(es.auth.twoFactor.enrolHeading)
      expect(wrapper.find('[data-test="two-factor-secret"]').text()).toBe(enrolment.secret)
    })

    await wrapper.find('input[name="code"]').setValue('492013')
    await wrapper.find('form').trigger('submit')
    // La navegacion posterior carga la vista por import() dinamico: en Vitest
    // tarda mas que unos microtareas, asi que se espera a la condicion.
    await vi.waitFor(() => expect(useSessionStore(pinia).isAuthenticated).toBe(true))
    expect(useSetupStore(pinia).stepState('administrator')).toBe('completed')
  })

  it('un 409 explica que ya hay una cuenta y remite al acceso, sin reintentar la creacion', async () => {
    const pinia = createTestPinia()
    const spy = stubRoutes({
      '/setup/administrator': () =>
        new Response(
          JSON.stringify({
            type: 'urn:kronoqr:problem:conflict',
            title: 'Conflicto',
            status: 409,
            detail: 'Esta instalación ya tiene una cuenta de gestión.',
          }),
          { status: 409, headers: { 'Content-Type': 'application/problem+json' } },
        ),
    })

    const wrapper = await mountView(AdministratorStep, { pinia })

    await fillForm(wrapper)

    expect(wrapper.text()).toContain(es.onboarding.steps.administrator.alreadyExists)
    expect(wrapper.find('form').exists()).toBe(false)
    expect(
      spy.mock.calls.filter(([url]) => String(url).includes('/setup/administrator')).length,
    ).toBe(1)
  })

  it('con un solo idioma no envia locale: lo decide la instalacion', async () => {
    const pinia = createTestPinia()

    await loadBrandingWithLocales(['es'])

    const spy = stubRoutes({
      '/setup/administrator': () => jsonResponse(twoFactorChallenge(), 201),
    })
    const wrapper = await mountView(AdministratorStep, { pinia })

    await fillForm(wrapper)

    expect(administratorBody(spy)).not.toHaveProperty('locale')
  })

  it('registra la sesion con el nombre del panel de i18n (R7-PA-03)', async () => {
    const spy = stubRoutes({
      '/setup/administrator': () => jsonResponse(twoFactorChallenge(), 201),
    })
    const wrapper = await mountView(AdministratorStep, { pinia: createTestPinia() })

    await fillForm(wrapper)

    expect(administratorBody(spy)).toMatchObject({ device_name: es.app.title })
  })

  it('con dos idiomas envia el idioma del asistente', async () => {
    const pinia = createTestPinia()

    await loadBrandingWithLocales(['es', 'en'])

    const spy = stubRoutes({
      '/setup/administrator': () => jsonResponse(twoFactorChallenge(), 201),
    })
    const wrapper = await mountView(AdministratorStep, { pinia })

    await fillForm(wrapper)

    expect(administratorBody(spy)).toMatchObject({ locale: 'es' })
  })
})

function administratorBody(spy: ReturnType<typeof vi.fn>): Record<string, unknown> {
  const call = spy.mock.calls.find(([url]) => String(url).endsWith('/setup/administrator'))

  return JSON.parse(String(call?.[1]?.body)) as Record<string, unknown>
}
