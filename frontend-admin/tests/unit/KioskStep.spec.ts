import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import KioskStep from '@/features/onboarding/steps/KioskStep.vue'
import { useSetupStore } from '@/features/onboarding/setup.store'
import es from '@/shared/i18n/locales/es.json'
import { setupStatus, setupSteps } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

// Paso 7: primer quiosco (RF-PD-03). Los endpoints de emparejamiento son de la
// 5.6 y todavia no existen: este paso solo ofrece omitir.

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('KioskStep', () => {
  it('solo ofrece omitir, y explica que el emparejamiento llega en otra version', async () => {
    const wrapper = await mountView(KioskStep, { pinia: createTestPinia() })

    expect(wrapper.text()).toContain(es.onboarding.steps.kiosk.notYetAvailable)
    expect(wrapper.findAll('button')).toHaveLength(1)
  })

  it('omitir marca el paso omitido', async () => {
    const pinia = createTestPinia()

    stubRoutes({
      '/setup/steps/kiosk': () =>
        jsonResponse(setupStatus({ steps: setupSteps({ kiosk: { state: 'skipped' } }) })),
    })

    const wrapper = await mountView(KioskStep, { pinia })

    await wrapper.find('[data-test="skip"]').trigger('click')
    await settle()

    expect(useSetupStore(pinia).stepState('kiosk')).toBe('skipped')
  })
})
