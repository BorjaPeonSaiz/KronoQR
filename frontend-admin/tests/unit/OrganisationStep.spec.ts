import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OrganisationStep from '@/features/onboarding/steps/OrganisationStep.vue'
import { useSetupStore } from '@/features/onboarding/setup.store'
import es from '@/shared/i18n/locales/es.json'
import { setupStatus, setupSteps } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

// Paso 2: datos de la organizacion (RF-PD-03, RF-PD-01). No omitible.

const SETTINGS_BODY = {
  data: [
    {
      key: 'BRANDING_APP_NAME',
      value: '',
      type: 'text',
      impact: 'presentation',
      affects_worked_hours: false,
      source: 'product_default',
    },
    {
      key: 'LOCALE_DEFAULT',
      value: 'es',
      type: 'text',
      impact: 'presentation',
      affects_worked_hours: false,
      source: 'product_default',
    },
    {
      key: 'LOCALE_AVAILABLE',
      value: ['es', 'en'],
      type: 'text_list',
      impact: 'presentation',
      affects_worked_hours: false,
      source: 'product_default',
    },
  ],
  meta: { unknown_keys: [], invalid_keys: [] },
}

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('OrganisationStep', () => {
  it('no deja continuar sin nombre de establecimiento', async () => {
    stubRoutes({ '/settings': () => jsonResponse(SETTINGS_BODY) })

    const wrapper = await mountView(OrganisationStep, { pinia: createTestPinia() })
    await settle()

    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('guarda solo lo que cambia y marca el paso hecho', async () => {
    const pinia = createTestPinia()
    let patchedBody: unknown = null

    stubRoutes({
      '/settings': (_url, init) => {
        if (init?.method === 'PATCH') {
          patchedBody = init.body !== undefined ? JSON.parse(String(init.body)) : null

          return jsonResponse(SETTINGS_BODY)
        }

        return jsonResponse(SETTINGS_BODY)
      },
      '/setup/steps/organisation': () =>
        jsonResponse(setupStatus({ steps: setupSteps({ organisation: { state: 'completed' } }) })),
    })

    const wrapper = await mountView(OrganisationStep, { pinia })
    await settle()

    await wrapper.find('input[type="text"]').setValue('Hotel Marina')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(patchedBody).toEqual({ settings: { BRANDING_APP_NAME: 'Hotel Marina' } })
    expect(useSetupStore(pinia).stepState('organisation')).toBe('completed')
  })

  it('lee el 422 con el nombre del campo en castellano', async () => {
    stubRoutes({
      '/settings': (_url, init) => {
        if (init?.method === 'PATCH') {
          return new Response(
            JSON.stringify({
              type: 'urn:kronoqr:problem:validation-failed',
              title: 'Hay datos que revisar',
              status: 422,
              errors: { 'settings.BRANDING_APP_NAME': ['Es demasiado larga.'] },
            }),
            { status: 422, headers: { 'Content-Type': 'application/problem+json' } },
          )
        }

        return jsonResponse(SETTINGS_BODY)
      },
    })

    const wrapper = await mountView(OrganisationStep, { pinia: createTestPinia() })
    await settle()

    await wrapper.find('input[type="text"]').setValue('Hotel Marina')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain(es.onboarding.steps.organisation.fields.appName)
  })
})
