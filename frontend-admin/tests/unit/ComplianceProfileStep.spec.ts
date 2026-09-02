import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ComplianceProfileStep from '@/features/onboarding/steps/ComplianceProfileStep.vue'
import { useSetupStore } from '@/features/onboarding/setup.store'
import es from '@/shared/i18n/locales/es.json'
import { setupStatus, setupSteps } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

// Paso 5: perfil de cumplimiento (RF-PD-03, RL-21). NO omitible: reutiliza
// `ComplianceProfileView` (tarea 5.2) y solo añade la confirmacion de que se
// ha revisado.

const PROFILE = {
  data: {
    id: 1,
    name: 'ES-hosteleria',
    jurisdiction: 'ES',
    min_rest_hours: 12,
    max_daily_hours: 9,
    max_weekly_hours: 40,
    break_required_after_hours: 6,
    week_starts_on: 1,
    holiday_calendar: [],
    retention_years: 4,
    is_default: true,
    source: 'installation_default',
    updated_at: null,
  },
}

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('ComplianceProfileStep', () => {
  it('enseña el aviso de RL-21 y no ofrece omitir', async () => {
    stubRoutes({ '/compliance-profile': () => jsonResponse(PROFILE) })

    const wrapper = await mountView(ComplianceProfileStep, { pinia: createTestPinia() })
    await settle()

    expect(wrapper.text()).toContain(es.compliance.detectionWarning)
    expect(wrapper.findAll('button').every((button) => !button.text().includes('Omitir'))).toBe(
      true,
    )
  })

  it('confirmar marca el paso hecho aunque no se cambie ningun umbral', async () => {
    const pinia = createTestPinia()

    stubRoutes({
      '/compliance-profile': () => jsonResponse(PROFILE),
      '/setup/steps/compliance_profile': () =>
        jsonResponse(
          setupStatus({ steps: setupSteps({ compliance_profile: { state: 'completed' } }) }),
        ),
    })

    const wrapper = await mountView(ComplianceProfileStep, { pinia })
    await settle()

    await wrapper.find('[data-test="confirm-compliance-profile"]').trigger('click')
    await settle()

    expect(useSetupStore(pinia).stepState('compliance_profile')).toBe('completed')
  })
})
