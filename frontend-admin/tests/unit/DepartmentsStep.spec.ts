import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import DepartmentsStep from '@/features/onboarding/steps/DepartmentsStep.vue'
import { useSetupStore } from '@/features/onboarding/setup.store'
import es from '@/shared/i18n/locales/es.json'
import { department, setupStatus, setupSteps } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

// Paso 4: departamentos (RF-PD-03). Omitible.

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

function stepCompleted(step: 'departments', state: 'completed' | 'skipped') {
  return setupStatus({ steps: setupSteps({ [step]: { state } }) })
}

describe('DepartmentsStep', () => {
  it('sin ninguno todavia, lo dice antes de ofrecer el alta', async () => {
    stubRoutes({ '/departments': () => jsonResponse({ data: [] }) })

    const wrapper = await mountView(DepartmentsStep, { pinia: createTestPinia() })
    await settle()

    expect(wrapper.find('[data-test="no-departments"]').text()).toBe(
      es.onboarding.steps.departments.none,
    )
  })

  it('añade uno nuevo a la lista sin recargar toda la coleccion', async () => {
    stubRoutes({
      '/departments': (_url, init) => {
        if (init?.method === 'POST') {
          return jsonResponse(department({ id: 4, name: 'Pisos' }), 201)
        }

        return jsonResponse({ data: [department()] })
      },
    })

    const wrapper = await mountView(DepartmentsStep, { pinia: createTestPinia() })
    await settle()

    await wrapper.find('input[type="text"]').setValue('Pisos')
    await wrapper.find('form').trigger('submit')
    await settle()

    const items = wrapper.findAll('[data-test="department-list"] li')

    expect(items.map((item) => item.text())).toEqual(['Recepción', 'Pisos'])
  })

  it('omitir marca el paso como omitido, no como hecho', async () => {
    const pinia = createTestPinia()

    stubRoutes({
      // Mas especifica primero: `/setup/steps/departments` tambien termina en
      // «/departments», y el enrutado por sufijo del doble elige la primera
      // que encaja.
      '/setup/steps/departments': () => jsonResponse(stepCompleted('departments', 'skipped')),
      '/departments': () => jsonResponse({ data: [] }),
    })

    const wrapper = await mountView(DepartmentsStep, { pinia })
    await settle()

    await wrapper.find('[data-test="skip"]').trigger('click')
    await settle()

    expect(useSetupStore(pinia).stepState('departments')).toBe('skipped')
  })
})
