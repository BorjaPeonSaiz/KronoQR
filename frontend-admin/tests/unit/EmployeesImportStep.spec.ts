import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EmployeesImportStep from '@/features/onboarding/steps/EmployeesImportStep.vue'
import { useSetupStore } from '@/features/onboarding/setup.store'
import es from '@/shared/i18n/locales/es.json'
import { employeeImportReport, setupStatus, setupSteps } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubFetch } from './support/harness'

// Paso 6 (RF-GP-05): importacion masiva, dos fases. `validate` no escribe
// nada; `apply` exige `confirm_checksum` con el `sha256` de la validacion.

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

function csvFile(name = 'plantilla.csv'): File {
  return new File(['first_name,last_name\nYoussef,Amrani'], name, { type: 'text/csv' })
}

async function selectFile(wrapper: Awaited<ReturnType<typeof mountView>>): Promise<void> {
  const input = wrapper.find('input[type="file"]')
  const file = csvFile()

  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
}

describe('EmployeesImportStep', () => {
  it('valida sin escribir nada y enseña el informe linea a linea', async () => {
    const report = employeeImportReport()

    stubFetch((url, init) => {
      expect(String(url)).toContain('/employees/import')
      expect(init?.method).toBe('POST')
      // Multipart: nunca JSON. Vue Test Utils/jsdom no exponen `FormData` en
      // el `body` como cadena, asi que basta con comprobar que NO se fijo
      // `Content-Type` a mano (lo pone el navegador con el `boundary`).
      const headers = init?.headers as Headers | undefined

      expect(headers?.get('Content-Type')).toBeNull()

      return jsonResponse(report)
    })

    const wrapper = await mountView(EmployeesImportStep, { pinia: createTestPinia() })

    await selectFile(wrapper)
    await wrapper.find('[data-test="validate"]').trigger('click')
    await settle()

    expect(wrapper.find('[data-test="report-status"]').text()).toContain('Simulación')
    expect(wrapper.find('[data-test="import-row-2"]').text()).toContain('Youssef Amrani')
    expect(wrapper.find('[data-test="apply"]').exists()).toBe(true)
    // Sin avisos del fichero, no se pinta el aviso: nada que decir de mas.
    expect(wrapper.find('[data-test="file-warnings"]').exists()).toBe(false)
  })

  it('una columna que el importador no reconoce se enseña como aviso del fichero entero', async () => {
    // «e-mail» en vez de «email»: quien importa cree que cargo los correos y
    // no cargo ninguno si este aviso no se ve (motivo de la revision de la 5.5).
    const report = employeeImportReport({
      file: {
        ...employeeImportReport().file,
        warnings: [
          {
            code: 'unknown_column',
            severity: 'warning',
            column: 'e-mail',
            detail: 'La columna «e-mail» no se reconoce y se ha ignorado. ¿Querías decir «email»?',
          },
        ],
      },
    })

    stubFetch(() => jsonResponse(report))

    const wrapper = await mountView(EmployeesImportStep, { pinia: createTestPinia() })

    await selectFile(wrapper)
    await wrapper.find('[data-test="validate"]').trigger('click')
    await settle()

    const warnings = wrapper.find('[data-test="file-warnings"]')

    expect(warnings.exists()).toBe(true)
    expect(warnings.text()).toContain('e-mail')
    expect(warnings.text()).toContain('¿Querías decir «email»?')
  })

  it('truncado no deja aplicar, y lo dice', async () => {
    stubFetch(() => jsonResponse(employeeImportReport({ truncated: true })))

    const wrapper = await mountView(EmployeesImportStep, { pinia: createTestPinia() })

    await selectFile(wrapper)
    await wrapper.find('[data-test="validate"]').trigger('click')
    await settle()

    expect(wrapper.find('[data-test="truncated"]').text()).toBe(
      es.onboarding.steps.employees.truncated,
    )
    expect(wrapper.find('[data-test="apply"]').attributes('disabled')).toBeDefined()
  })

  it('aplica con el checksum de la validacion, y despues continua', async () => {
    const pinia = createTestPinia()
    const validated = employeeImportReport()
    const applied = employeeImportReport({ mode: 'apply' })
    let appliedChecksum: string | null = null

    stubFetch(async (_url, init) => {
      const body = init?.body as FormData

      if (body.get('mode') === 'apply') {
        appliedChecksum = String(body.get('confirm_checksum'))

        return jsonResponse(applied)
      }

      return jsonResponse(validated)
    })

    const wrapper = await mountView(EmployeesImportStep, { pinia })

    await selectFile(wrapper)
    await wrapper.find('[data-test="validate"]').trigger('click')
    await settle()

    await wrapper.find('[data-test="apply"]').trigger('click')
    await settle()

    expect(appliedChecksum).toBe(validated.file.sha256)
    expect(wrapper.find('[data-test="apply"]').exists()).toBe(false)

    stubFetch(() =>
      jsonResponse(setupStatus({ steps: setupSteps({ employees: { state: 'completed' } }) })),
    )

    await wrapper.find('[data-test="continue"]').trigger('click')
    await settle()

    expect(useSetupStore(pinia).stepState('employees')).toBe('completed')
  })

  it('seleccionar otro fichero invalida el informe anterior', async () => {
    stubFetch(() => jsonResponse(employeeImportReport()))

    const wrapper = await mountView(EmployeesImportStep, { pinia: createTestPinia() })

    await selectFile(wrapper)
    await wrapper.find('[data-test="validate"]').trigger('click')
    await settle()

    expect(wrapper.find('[data-test="apply"]').exists()).toBe(true)

    await selectFile(wrapper)

    expect(wrapper.find('[data-test="apply"]').exists()).toBe(false)
  })

  it('omitir marca el paso omitido sin haber importado nada', async () => {
    const pinia = createTestPinia()

    stubFetch(() =>
      jsonResponse(setupStatus({ steps: setupSteps({ employees: { state: 'skipped' } }) })),
    )

    const wrapper = await mountView(EmployeesImportStep, { pinia })

    await wrapper.find('[data-test="skip"]').trigger('click')
    await settle()

    expect(useSetupStore(pinia).stepState('employees')).toBe('skipped')
  })
})
