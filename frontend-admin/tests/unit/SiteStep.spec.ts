import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SiteStep from '@/features/onboarding/steps/SiteStep.vue'
import { useSessionStore } from '@/features/auth/session.store'
import { useSetupStore } from '@/features/onboarding/setup.store'
import { SITE, setupStatus, setupSteps } from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubRoutes,
} from './support/harness'

// Paso 3: el centro de trabajo (RF-PD-03, ADR-040). Paso DERIVADO: no admite
// `PUT /setup/steps/site`, se relee `GET /setup/steps` tras crearlo — con
// sesion, porque `POST /setup/site` ya la exige (contrato,
// `managementToken: ['employees:*']`): quien llega a este paso viene de haber
// confirmado el segundo factor del administrador en el paso 1.

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('SiteStep', () => {
  it('crea el centro y refresca el estado del asistente, sin marcarlo (paso derivado)', async () => {
    const pinia = createTestPinia()
    const session = useSessionStore(pinia)

    session.token = 'un-token'
    session.status = 'authenticated'

    let sitePosted: unknown = null

    stubRoutes({
      '/setup/site': (_url, init) => {
        sitePosted = init?.body !== undefined ? JSON.parse(String(init.body)) : null

        return jsonResponse(SITE, 201)
      },
      '/setup/steps': () =>
        jsonResponse(setupStatus({ steps: setupSteps({ site: { state: 'completed' } }) })),
    })

    const wrapper = await mountView(SiteStep, { pinia })

    await wrapper.find('input[type="text"]').setValue('Hotel Marina')
    // El segundo campo, la zona horaria, ya trae el valor de serie
    // `Europe/Madrid` (contrato, `CreateInstallationSiteRequest`).
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(sitePosted).toEqual({ name: 'Hotel Marina', timezone: 'Europe/Madrid' })
    expect(useSetupStore(pinia).stepState('site')).toBe('completed')
  })

  it('un 409 dice que ya hay un centro, sin escribir nada mas', async () => {
    stubRoutes({
      '/setup/site': () =>
        problemResponse(409, 'urn:kronoqr:problem:conflict', {
          detail: 'Esta instalación ya tiene su centro de trabajo.',
        }),
    })

    const wrapper = await mountView(SiteStep, { pinia: createTestPinia() })

    await wrapper.find('input[type="text"]').setValue('Hotel Marina')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })
})
