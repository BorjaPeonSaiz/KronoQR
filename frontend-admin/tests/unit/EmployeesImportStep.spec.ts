import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EmployeesImportStep from '@/features/onboarding/steps/EmployeesImportStep.vue'
import { useSetupStore } from '@/features/onboarding/setup.store'
import es from '@/shared/i18n/locales/es.json'
import { EMPLOYEE_UUID, employeeImportReport, setupStatus, setupSteps } from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

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
    expect(wrapper.find('[data-test="apply"]').attributes('aria-disabled')).toBeDefined()
  })

  describe('aviso de altas sin PIN', () => {
    async function applyWith(create: number): Promise<Awaited<ReturnType<typeof mountView>>> {
      const validated = employeeImportReport()
      const applied = employeeImportReport({
        mode: 'apply',
        summary: { create, update: 0, unchanged: 0, reject: 0, rehire: 0 },
      })

      stubFetch(async (_url, init) =>
        jsonResponse((init?.body as FormData).get('mode') === 'apply' ? applied : validated),
      )

      const wrapper = await mountView(EmployeesImportStep, { pinia: createTestPinia() })

      await selectFile(wrapper)
      await wrapper.find('[data-test="validate"]').trigger('click')
      await settle()
      await wrapper.find('[data-test="apply"]').trigger('click')
      await settle()

      return wrapper
    }

    it('con 0 altas no avisa', async () => {
      const wrapper = await applyWith(0)

      expect(wrapper.find('[data-test="no-pin-notice"]').exists()).toBe(false)
    })

    it('con 1 alta usa el singular y y no enlaza (la guarda lo descartaria)', async () => {
      const wrapper = await applyWith(1)
      const notice = wrapper.find('[data-test="no-pin-notice"]')

      expect(notice.text()).toContain('1 persona sin PIN')
      expect(wrapper.find('[data-test="no-pin-link"]').exists()).toBe(false)
      expect(useSetupStore().importedWithoutPin).toBe(1)
    })

    it('con N altas usa el plural', async () => {
      const wrapper = await applyWith(3)

      expect(wrapper.find('[data-test="no-pin-notice"]').text()).toContain('3 personas sin PIN')
    })

    it('en la simulacion todavia no avisa', async () => {
      stubFetch(() => jsonResponse(employeeImportReport()))

      const wrapper = await mountView(EmployeesImportStep, { pinia: createTestPinia() })

      await selectFile(wrapper)
      await wrapper.find('[data-test="validate"]').trigger('click')
      await settle()

      expect(wrapper.find('[data-test="no-pin-notice"]').exists()).toBe(false)
    })
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

  it('aplica con el rehire_digest de la validacion si la hubo, y sin el si no', async () => {
    // RN-27: el servidor no readmite a nadie que no estuviera en el resumen
    // revisado, y para comprobarlo necesita la huella de vuelta.
    const digest = 'b'.repeat(64)
    const sent: Array<FormDataEntryValue | null> = []

    for (const validated of [
      employeeImportReport({ rehire_digest: digest }),
      employeeImportReport(),
    ]) {
      stubFetch(async (_url, init) => {
        const body = init?.body as FormData

        if (body.get('mode') === 'apply') {
          sent.push(body.get('confirm_rehire_digest'))

          return jsonResponse(employeeImportReport({ mode: 'apply' }))
        }

        return jsonResponse(validated)
      })

      const wrapper = await mountView(EmployeesImportStep, { pinia: createTestPinia() })

      await selectFile(wrapper)
      await wrapper.find('[data-test="validate"]').trigger('click')
      await settle()
      await wrapper.find('[data-test="apply"]').trigger('click')
      await settle()
    }

    expect(sent).toEqual([digest, null])
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

// La importacion que readmite (RN-27, ADR-062 §6, RF-GP-05, pedido del 18-10-2026):
// una fila cuyo documento es de una persona de baja se propone como readmision en
// el resumen previo, con aviso y no como el 409 de antes, y se aplica al confirmar.
describe('EmployeesImportStep: filas que readmiten (RN-27)', () => {
  const DIGEST = 'c'.repeat(64)

  function rehireReport(overrides: Partial<ReturnType<typeof employeeImportReport>> = {}) {
    return employeeImportReport({
      summary: { create: 0, update: 0, unchanged: 0, reject: 1, rehire: 1 },
      rehire_digest: DIGEST,
      rows: [
        {
          line: 2,
          label: 'Lucia Ferrer',
          outcome: 'rehire',
          employee_uuid: EMPLOYEE_UUID,
          changes: [],
          messages: [
            {
              code: 'rehire_proposed',
              severity: 'warning',
              column: null,
              detail: 'Esta persona esta de baja: al aplicar se le da de alta de nuevo.',
            },
            {
              code: 'rehire_ignores_identity_changes',
              severity: 'warning',
              column: 'email',
              detail: 'El correo del fichero no se aplica.',
            },
          ],
        },
        {
          line: 3,
          label: 'Marta Vidal',
          outcome: 'reject',
          employee_uuid: null,
          changes: [],
          messages: [
            {
              code: 'rehire_identity_mismatch',
              severity: 'error',
              column: null,
              detail: 'Coincide con una persona de baja solo por el correo.',
            },
          ],
        },
      ],
      ...overrides,
    })
  }

  async function validated(report = rehireReport()) {
    stubFetch(() => jsonResponse(report))

    const wrapper = await mountView(EmployeesImportStep, { pinia: createTestPinia() })

    await selectFile(wrapper)
    await wrapper.find('[data-test="validate"]').trigger('click')
    await settle()

    return wrapper
  }

  it('enseña la fila como «Dar de alta de nuevo», con el aviso del servidor y su «que hacer»', async () => {
    const wrapper = await validated()
    const row = wrapper.find('[data-test="import-row-2"]')

    expect(row.text()).toContain(es.onboarding.steps.employees.outcomes.rehire)
    expect(row.text()).toContain('al aplicar se le da de alta de nuevo')
    expect(row.text()).toContain(es.onboarding.steps.employees.messageAdvice.rehire_proposed)
    expect(row.text()).toContain(
      es.onboarding.steps.employees.messageAdvice.rehire_ignores_identity_changes,
    )
  })

  it('cuenta las readmisiones en el resumen y pide revisarlas antes de aplicar', async () => {
    const wrapper = await validated()

    expect(wrapper.find('[data-test="report-status"]').text()).toContain('1 altas de nuevo')

    const notice = wrapper.find('[data-test="rehire-notice"]')

    expect(notice.attributes('role')).toBe('status')
    expect(notice.text()).toContain('1 persona de baja')
    // Los dos avisos del propietario (31-10-2026), tambien aqui.
    expect(notice.text()).toContain('los fichajes anteriores a esa fecha se rechazan')
    expect(notice.text()).toContain('espera a que los quioscos estén sincronizados')
  })

  it('el rechazo por identidad dice que hacer: corregir el documento o el nombre, o hacerlo desde la ficha', async () => {
    const wrapper = await validated()
    const row = wrapper.find('[data-test="import-row-3"]')

    expect(row.text()).toContain(es.onboarding.steps.employees.outcomes.reject)
    expect(row.text()).toContain('Coincide con una persona de baja solo por el correo.')
    expect(row.find('[data-test="message-advice"]').text()).toBe(
      es.onboarding.steps.employees.messageAdvice.rehire_identity_mismatch,
    )
  })

  it('un mensaje que no es de la readmision no lleva «que hacer» añadido', async () => {
    const wrapper = await validated(
      employeeImportReport({
        rows: [
          {
            line: 2,
            label: 'Marta Vidal',
            outcome: 'update',
            employee_uuid: EMPLOYEE_UUID,
            changes: [],
            messages: [
              {
                code: 'hired_at_not_updated',
                severity: 'warning',
                column: 'hired_at',
                detail: 'x',
              },
            ],
          },
        ],
      }),
    )

    expect(wrapper.find('[data-test="message-advice"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="rehire-notice"]').exists()).toBe(false)
  })

  it('al aplicar manda el rehire_digest y cuenta que se ha dado de alta de nuevo y como imprimir la tarjeta', async () => {
    const sent: Array<FormDataEntryValue | null> = []

    stubFetch(async (_url, init) => {
      const body = init?.body as FormData

      if (body.get('mode') === 'apply') {
        sent.push(body.get('confirm_rehire_digest'))

        return jsonResponse(rehireReport({ mode: 'apply' }))
      }

      return jsonResponse(rehireReport())
    })

    const wrapper = await mountView(EmployeesImportStep, { pinia: createTestPinia() })

    await selectFile(wrapper)
    await wrapper.find('[data-test="validate"]').trigger('click')
    await settle()
    await wrapper.find('[data-test="apply"]').trigger('click')
    await settle()

    expect(sent).toEqual([DIGEST])
    expect(wrapper.find('[data-test="report-status"]').text()).toContain('1 dadas de alta de nuevo')
    expect(wrapper.find('[data-test="rehired-notice"]').text()).toContain('tarjeta nueva pendiente')
    expect(wrapper.find('[data-test="rehire-notice"]').exists()).toBe(false)
  })

  it('si el servidor responde 409 explica que lo revisado ya no es lo que se aplicaria, y obliga a validar de nuevo', async () => {
    stubFetch(async (_url, init) => {
      const body = init?.body as FormData

      return body.get('mode') === 'apply'
        ? problemResponse(409, 'urn:kronoqr:problem:conflict')
        : jsonResponse(rehireReport())
    })

    const wrapper = await mountView(EmployeesImportStep, {
      pinia: createTestPinia(),
      attachTo: document.body,
    })

    await selectFile(wrapper)
    await wrapper.find('[data-test="validate"]').trigger('click')
    await settle()
    await wrapper.find('[data-test="apply"]').trigger('click')
    await settle()

    const notice = wrapper.find('[data-test="import-stale"]')

    expect(notice.attributes('role')).toBe('alert')
    expect(notice.text()).toContain(es.onboarding.steps.employees.stale.title)
    expect(notice.text()).toContain('Pulsa «Validar» otra vez')
    // El informe viejo ya no vale: «Aplicar» desaparece hasta que se vuelva a validar.
    expect(wrapper.find('[data-test="apply"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="import-row-2"]').exists()).toBe(false)
    // Y el foco no se pierde con el boton: pasa a «Validar», lo siguiente que hay que hacer.
    expect(document.activeElement).toBe(wrapper.find('[data-test="validate"]').element)

    await wrapper.find('[data-test="validate"]').trigger('click')
    await settle()

    expect(wrapper.find('[data-test="import-stale"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="apply"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('un fallo de red no es el aviso de resumen obsoleto: es el aviso general', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const wrapper = await mountView(EmployeesImportStep, { pinia: createTestPinia() })

    await selectFile(wrapper)
    await wrapper.find('[data-test="validate"]').trigger('click')
    await settle()

    expect(wrapper.find('[data-test="import-stale"]').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
  })
})
