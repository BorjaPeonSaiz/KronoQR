// Ajustes operativos (RF-PD-01, tarea 5.13) con el campo nuevo de la tarea
// 3.3: el codigo de servicio del quiosco (RF-KI-08). El resto de la pantalla
// (umbrales `ATTENDANCE_*`, idiomas) ya tenia cobertura de extremo a extremo
// en `tests/e2e/settings.spec.ts`; esta prueba se centra en lo que añade esta
// tarea: texto opcional, con forma fija en el propio panel y vacio siempre
// valido.
import { afterEach, describe, expect, it, vi } from 'vitest'
import OperationalSettingsView from '@/features/settings/OperationalSettingsView.vue'
import es from '@/shared/i18n/locales/es.json'
import { jsonResponse, mountView, problemResponse, settle, stubFetch } from './support/harness'

/** El catalogo completo que devuelve `GET /api/v1/settings`, con un codigo de servicio ya puesto. */
function catalog(serviceCode = '48392017'): unknown {
  return {
    data: [
      {
        key: 'ATTENDANCE_MAX_SHIFT_HOURS',
        value: 12,
        type: 'integer',
        impact: 'compliance_review',
        affects_worked_hours: false,
        source: 'product_default',
        constraints: { minimum: 1, maximum: 24 },
      },
      {
        key: 'ATTENDANCE_DEBOUNCE_SECONDS',
        value: 60,
        type: 'integer',
        impact: 'worked_hours',
        affects_worked_hours: true,
        source: 'product_default',
        constraints: { minimum: 0, maximum: 3600 },
      },
      {
        key: 'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES',
        value: 15,
        type: 'integer',
        impact: 'compliance_review',
        affects_worked_hours: false,
        source: 'product_default',
        constraints: { minimum: 1, maximum: 1440 },
      },
      {
        key: 'ATTENDANCE_MIN_TRANSIT_SECONDS',
        value: 120,
        type: 'integer',
        impact: 'compliance_review',
        affects_worked_hours: false,
        source: 'product_default',
        constraints: { minimum: 0, maximum: 3600 },
      },
      {
        key: 'BRANDING_APP_NAME',
        value: 'KronoQR',
        type: 'text',
        impact: 'presentation',
        affects_worked_hours: false,
        source: 'product_default',
        constraints: { maximum_length: 60 },
      },
      {
        key: 'BRANDING_ACCENT_COLOR',
        value: '#b8542a',
        type: 'text',
        impact: 'presentation',
        affects_worked_hours: false,
        source: 'product_default',
        constraints: { pattern: '^#[0-9a-fA-F]{6}$' },
      },
      {
        key: 'BRANDING_LOGO_PATH',
        value: '',
        type: 'text',
        impact: 'presentation',
        affects_worked_hours: false,
        source: 'product_default',
      },
      {
        key: 'KIOSK_SERVICE_CODE',
        value: serviceCode,
        type: 'text',
        impact: 'presentation',
        affects_worked_hours: false,
        source: serviceCode === '' ? 'product_default' : 'installation',
      },
      {
        key: 'LOCALE_DEFAULT',
        value: 'es',
        type: 'text',
        impact: 'presentation',
        affects_worked_hours: false,
        source: 'product_default',
        constraints: { allowed: ['es', 'en'] },
      },
      {
        key: 'LOCALE_AVAILABLE',
        value: ['es', 'en'],
        type: 'text_list',
        impact: 'presentation',
        affects_worked_hours: false,
        source: 'product_default',
        constraints: { allowed: ['es', 'en'] },
      },
    ],
    meta: { unknown_keys: [], invalid_keys: [] },
  }
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('OperationalSettingsView — código de servicio del quiosco (RF-KI-08)', () => {
  it('carga el codigo ya guardado', async () => {
    stubFetch(() => jsonResponse(catalog('48392017')))

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    expect(wrapper.find('[data-test="kiosk-service-code"]').element).toHaveProperty(
      'value',
      '48392017',
    )
  })

  it('vacio es siempre valido: sin codigo, la pantalla de diagnostico se abre sin el', async () => {
    stubFetch(() => jsonResponse(catalog('')))

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    expect(wrapper.find('[data-test="kiosk-service-code"]').element).toHaveProperty('value', '')
    // El campo vacio, tal cual llego, no cuenta como cambio: no hay nada que guardar.
    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeDefined()
  })

  it('un codigo con letras o fuera de 8-12 cifras se rechaza en el propio panel, sin llegar al servidor', async () => {
    stubFetch(() => jsonResponse(catalog('')))

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    await wrapper.find('[data-test="kiosk-service-code"]').setValue('12A45')
    await settle()

    expect(wrapper.text()).toContain(es.operationalSettings.errors.notAServiceCode)
    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeDefined()
  })

  it('borrar un codigo existente es un cambio valido: manda cadena vacia', async () => {
    const fetchSpy = stubFetch((_url, init) =>
      init?.method === 'PATCH' ? jsonResponse(catalog('')) : jsonResponse(catalog('48392017')),
    )

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    await wrapper.find('[data-test="kiosk-service-code"]').setValue('')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    const patch = fetchSpy.mock.calls.find(([, init]) => (init as RequestInit).method === 'PATCH')

    expect(patch).toBeDefined()
    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      settings: { KIOSK_SERVICE_CODE: '' },
    })
  })

  it('guardar un codigo nuevo manda solo esa clave', async () => {
    const fetchSpy = stubFetch((_url, init) =>
      init?.method === 'PATCH' ? jsonResponse(catalog('91827364')) : jsonResponse(catalog('')),
    )

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    await wrapper.find('[data-test="kiosk-service-code"]').setValue('91827364')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    const patch = fetchSpy.mock.calls.find(([, init]) => (init as RequestInit).method === 'PATCH')

    expect(patch).toBeDefined()
    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      settings: { KIOSK_SERVICE_CODE: '91827364' },
    })
  })

  it('el 422 del servidor se muestra bajo el campo del codigo, no con el nombre de la columna', async () => {
    stubFetch((_url, init) =>
      init?.method === 'PATCH'
        ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: { 'settings.KIOSK_SERVICE_CODE': ['Ese código ya lo usa otra instalación.'] },
          })
        : jsonResponse(catalog('')),
    )

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    await wrapper.find('[data-test="kiosk-service-code"]').setValue('91827364')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain(es.operationalSettings.fields.kioskServiceCode)
    expect(wrapper.text()).toContain('Ese código ya lo usa otra instalación.')
  })
})
