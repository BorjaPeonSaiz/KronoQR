import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OnboardingView from '@/features/onboarding/OnboardingView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import { department, setupCompletion, setupStatus, setupSteps } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

// El asistente de puesta en marcha (RF-PD-03): que estado enseña que pantalla.

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('OnboardingView', () => {
  it('sin sesion, enseña el primer paso especial: el primer administrador', async () => {
    // `GET /setup/status` es publica y NUNCA trae `steps` (revision de la
    // 5.5): es lo unico que hay antes de que exista ninguna cuenta, y es
    // justo lo que necesita el primer paso, que es derivado.
    stubRoutes({ '/setup/status': () => jsonResponse(setupStatus()) })

    const wrapper = await mountView(OnboardingView)
    await settle()

    expect(wrapper.text()).toContain(es.onboarding.steps.administrator.heading)
    // Sin `steps`, no hay progreso que enseñar: la barra solo aparece con
    // sesion (`setup.stepsKnown`).
    expect(wrapper.find('[data-test="progress"]').exists()).toBe(false)
  })

  it('con sesion, salta al primer paso pendiente cuando los anteriores ya estan resueltos', async () => {
    const pinia = createTestPinia()
    const session = useSessionStore(pinia)

    session.token = 'un-token'
    session.status = 'authenticated'

    stubRoutes({
      '/setup/steps': () =>
        jsonResponse(
          setupStatus({
            steps: setupSteps({
              administrator: { state: 'completed' },
              organisation: { state: 'completed' },
              site: { state: 'completed' },
            }),
          }),
        ),
      '/departments': () => jsonResponse({ data: [department()] }),
    })

    const wrapper = await mountView(OnboardingView, { pinia })
    await settle()

    expect(wrapper.text()).toContain(es.onboarding.steps.departments.heading)
    expect(wrapper.text()).not.toContain(es.onboarding.steps.administrator.intro)
  })

  it('con la instalacion ya en marcha, no vuelve a abrir el asistente', async () => {
    stubRoutes({
      '/setup/status': () => jsonResponse(setupStatus({ available: false })),
    })

    const wrapper = await mountView(OnboardingView)
    await settle()

    expect(wrapper.text()).toContain(es.onboarding.alreadyDone.heading)
  })

  it('con todos los pasos resueltos, enseña la revision final y luego el cierre', async () => {
    const pinia = createTestPinia()
    const session = useSessionStore(pinia)

    session.token = 'un-token'
    session.status = 'authenticated'

    const resolved = setupStatus({
      steps: setupSteps({
        administrator: { state: 'completed' },
        organisation: { state: 'completed' },
        site: { state: 'completed' },
        departments: { state: 'skipped' },
        compliance_profile: { state: 'completed' },
        employees: { state: 'skipped' },
        license: { state: 'skipped' },
        kiosk: { state: 'skipped' },
      }),
    })
    const completion = setupCompletion()

    stubRoutes({
      '/setup/steps': () => jsonResponse(resolved),
      '/setup/complete': () => jsonResponse(completion),
    })

    const wrapper = await mountView(OnboardingView, { pinia })
    await settle()

    expect(wrapper.text()).toContain(es.onboarding.review.heading)

    await wrapper.find('[data-test="complete-setup"]').trigger('click')
    await settle()

    expect(wrapper.text()).toContain(es.onboarding.completion.heading)
    expect(wrapper.text()).toContain(
      es.onboarding.completion.credentialsPending.replace('{count}', '42'),
    )
  })
})
