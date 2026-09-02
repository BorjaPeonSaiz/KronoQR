import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ReviewStep from '@/features/onboarding/ReviewStep.vue'
import { useSetupStore } from '@/features/onboarding/setup.store'
import { setupCompletion, setupSteps } from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

// Revision final antes de cerrar el asistente (RF-PD-03): el asistente no se
// cierra solo, hace falta el «Finalizar» explicito.

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

const RESOLVED_STEPS = setupSteps({
  administrator: { state: 'completed' },
  organisation: { state: 'completed' },
  site: { state: 'completed' },
  departments: { state: 'skipped' },
  compliance_profile: { state: 'completed' },
  employees: { state: 'skipped' },
  license: { state: 'skipped' },
  kiosk: { state: 'skipped' },
})

describe('ReviewStep', () => {
  it('enseña los ocho pasos con su estado', async () => {
    const wrapper = await mountView(ReviewStep, {
      props: { steps: RESOLVED_STEPS },
      pinia: createTestPinia(),
    })

    expect(wrapper.findAll('[data-test="review-list"] li')).toHaveLength(8)
    expect(wrapper.find('[data-test="review-departments"]').text()).toContain('Omitido')
    expect(wrapper.find('[data-test="review-compliance_profile"]').text()).toContain('Hecho')
  })

  it('finalizar cierra el asistente en el store', async () => {
    const pinia = createTestPinia()
    const completion = setupCompletion()

    stubFetch(() => jsonResponse(completion))

    const wrapper = await mountView(ReviewStep, {
      props: { steps: RESOLVED_STEPS },
      pinia,
    })

    await wrapper.find('[data-test="complete-setup"]').trigger('click')
    await settle()

    const setup = useSetupStore(pinia)

    expect(setup.available).toBe(false)
    expect(setup.completion).toEqual(completion)
  })

  it('un 409 (queda algun paso sin resolver) se enseña sin cerrar el asistente', async () => {
    const pinia = createTestPinia()

    stubFetch(() =>
      problemResponse(409, 'urn:kronoqr:problem:conflict', {
        detail: 'Faltan pasos por resolver: compliance_profile.',
      }),
    )

    const wrapper = await mountView(ReviewStep, {
      props: { steps: RESOLVED_STEPS },
      pinia,
    })

    await wrapper.find('[data-test="complete-setup"]').trigger('click')
    await settle()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(useSetupStore(pinia).completion).toBeNull()
  })
})
