import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LicenseStep from '@/features/onboarding/steps/LicenseStep.vue'
import { useSetupStore } from '@/features/onboarding/setup.store'
import { setupStatus, setupSteps } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

// Paso 6: licencia (RF-PD-03, ADR-019, regla dura 15). Reutiliza `LicenseView`
// (tarea 5.3) e incorpora «continuar» / «omitir por ahora».

const ABSENT_LICENSE = {
  data: {
    state: 'absent',
    severity: 'none',
    rejection_reason: null,
    customer_name: null,
    plan: null,
    license_id: null,
    valid_from: null,
    valid_until: null,
    issued_at: null,
    days_until_expiry: null,
    days_since_expiry: null,
    features: [],
    degraded_features: [],
    limits: [],
    activated_at: null,
    last_verified_at: null,
    key_fingerprint: null,
  },
  meta: { expiry_warning_days: 30, needs_notice: false, evaluated_at: '2026-09-02T09:00:00Z' },
}

function stepStatus(state: 'completed' | 'skipped') {
  return setupStatus({ steps: setupSteps({ license: { state } }) })
}

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('LicenseStep', () => {
  it('omitir marca el paso omitido: la licencia nunca es requisito de arranque', async () => {
    const pinia = createTestPinia()

    stubRoutes({
      '/setup/steps/license': () => jsonResponse(stepStatus('skipped')),
      '/license': () => jsonResponse(ABSENT_LICENSE),
    })

    const wrapper = await mountView(LicenseStep, { pinia })
    await settle()

    await wrapper.find('[data-test="skip"]').trigger('click')
    await settle()

    expect(useSetupStore(pinia).stepState('license')).toBe('skipped')
  })

  it('continuar marca el paso hecho', async () => {
    const pinia = createTestPinia()

    stubRoutes({
      '/setup/steps/license': () => jsonResponse(stepStatus('completed')),
      '/license': () => jsonResponse(ABSENT_LICENSE),
    })

    const wrapper = await mountView(LicenseStep, { pinia })
    await settle()

    await wrapper.find('[data-test="continue"]').trigger('click')
    await settle()

    expect(useSetupStore(pinia).stepState('license')).toBe('completed')
  })
})
