// Ajustes operativos (RF-PD-01, tarea 5.13) con el campo nuevo de la tarea
// 3.3: el codigo de servicio del quiosco (RF-KI-08). El resto de la pantalla
// (umbrales `ATTENDANCE_*`, idiomas) ya tenia cobertura de extremo a extremo
// en `tests/e2e/settings.spec.ts`; esta prueba se centra en lo que añade esta
// tarea: texto opcional, con forma fija en el propio panel y vacio siempre
// valido. Tambien cubre el campo de la tarea 3.5, «Fichaje de pausa»
// (`ATTENDANCE_BREAK_CLOCKING`, RF-AT-12).
import { afterEach, describe, expect, it, vi } from 'vitest'
import OperationalSettingsView from '@/features/settings/OperationalSettingsView.vue'
import es from '@/shared/i18n/locales/es.json'
import { jsonResponse, mountView, problemResponse, settle, stubFetch } from './support/harness'

/** El catalogo completo que devuelve `GET /api/v1/settings`, con un codigo de servicio ya puesto. */
function catalog(
  serviceCode = '48392017',
  breakClocking: 'enabled' | 'disabled' = 'disabled',
  weeklySummaryEmail: 'enabled' | 'disabled' = 'disabled',
  kioskUpdateWindow = '03:00-05:00',
  kioskUpdateQuietMinutes = 10,
): unknown {
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
      // RF-PR-06/RN-16, tarea 3.11, decision 7 de la ficha: ya en el enum
      // `SettingKey` del contrato (segunda vuelta, decision 17).
      {
        key: 'ATTENDANCE_PATTERN_WINDOW_SECONDS',
        value: 10,
        type: 'integer',
        impact: 'compliance_review',
        affects_worked_hours: false,
        source: 'product_default',
        constraints: { minimum: 0, maximum: 300 },
      },
      {
        key: 'ATTENDANCE_PATTERN_MIN_REPEATS',
        value: 3,
        type: 'integer',
        impact: 'compliance_review',
        affects_worked_hours: false,
        source: 'product_default',
        constraints: { minimum: 1, maximum: 30 },
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
        key: 'ATTENDANCE_BREAK_CLOCKING',
        value: breakClocking,
        type: 'text',
        impact: 'compliance_review',
        affects_worked_hours: false,
        source: breakClocking === 'disabled' ? 'product_default' : 'installation',
        constraints: { allowed: ['enabled', 'disabled'] },
      },
      // Resumen semanal (RF-PR-05) y ventana de actualizacion del quiosco
      // (RF-KI-07), tarea 3.12: TODAVIA no en el enum `SettingKey` del
      // contrato en el momento de escribir esta prueba (decision 9 de la
      // ficha), como avisa el comentario de la vista.
      {
        key: 'WEEKLY_SUMMARY_EMAIL',
        value: weeklySummaryEmail,
        type: 'text',
        impact: 'presentation',
        affects_worked_hours: false,
        source: weeklySummaryEmail === 'disabled' ? 'product_default' : 'installation',
        constraints: { allowed: ['disabled', 'enabled'] },
      },
      {
        key: 'KIOSK_UPDATE_WINDOW',
        value: kioskUpdateWindow,
        type: 'text',
        impact: 'presentation',
        affects_worked_hours: false,
        source: kioskUpdateWindow === '03:00-05:00' ? 'product_default' : 'installation',
        constraints: { pattern: '^([01]\\d|2[0-3]):[0-5]\\d-([01]\\d|2[0-3]):[0-5]\\d$' },
      },
      {
        key: 'KIOSK_UPDATE_QUIET_MINUTES',
        value: kioskUpdateQuietMinutes,
        type: 'integer',
        impact: 'presentation',
        affects_worked_hours: false,
        source: kioskUpdateQuietMinutes === 10 ? 'product_default' : 'installation',
        constraints: { minimum: 0, maximum: 120 },
      },
      // Cuadro de impacto y adopcion (RF-IN-08, tarea 3.13): TODAVIA no en el
      // enum `SettingKey` del contrato en el momento de escribir esta prueba
      // (`OperationalSettingsView.vue` lo explica), mismo criterio que
      // `WEEKLY_SUMMARY_EMAIL`/`KIOSK_UPDATE_*` de arriba. `0` de serie: «no
      // declarada».
      {
        key: 'BASELINE_MANUAL_HOURS_PER_MONTH',
        value: 0,
        type: 'integer',
        impact: 'presentation',
        affects_worked_hours: false,
        source: 'product_default',
        constraints: { minimum: 0, maximum: 10000 },
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

/**
 * El mismo catalogo, sin la fila de `KIOSK_UPDATE_QUIET_MINUTES` (segunda
 * vuelta de la tarea 3.12, hallazgo del revisor en la 3.11 aplicado aqui):
 * una clave sin fila propia tiene que verse como tal -`0`-, no como un valor
 * de serie plausible que nadie ha configurado.
 */
function catalogWithoutQuietMinutes(): unknown {
  const base = catalog() as { data: Array<{ key: string }>; meta: unknown }

  return {
    data: base.data.filter((entry) => entry.key !== 'KIOSK_UPDATE_QUIET_MINUTES'),
    meta: base.meta,
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

describe('OperationalSettingsView — fichaje de pausa (RF-AT-12, tarea 3.5)', () => {
  it('carga «Desactivado» de serie, con las tres consecuencias en el hint', async () => {
    stubFetch(() => jsonResponse(catalog('48392017', 'disabled')))

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    expect(wrapper.find('[data-test="break-clocking"]').element).toHaveProperty('value', 'disabled')
    expect(wrapper.text()).toContain(es.operationalSettings.hints.breakClocking)
  })

  it('activarlo y guardarlo manda solo esa clave', async () => {
    const fetchSpy = stubFetch((_url, init) =>
      init?.method === 'PATCH'
        ? jsonResponse(catalog('48392017', 'enabled'))
        : jsonResponse(catalog('48392017', 'disabled')),
    )

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    await wrapper.find('[data-test="break-clocking"]').setValue('enabled')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    const patch = fetchSpy.mock.calls.find(([, init]) => (init as RequestInit).method === 'PATCH')

    expect(patch).toBeDefined()
    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      settings: { ATTENDANCE_BREAK_CLOCKING: 'enabled' },
    })
  })

  it('persiste tras recargar', async () => {
    stubFetch((_url, init) =>
      init?.method === 'PATCH'
        ? jsonResponse(catalog('48392017', 'enabled'))
        : jsonResponse(catalog('48392017', 'enabled')),
    )

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    expect(wrapper.find('[data-test="break-clocking"]').element).toHaveProperty('value', 'enabled')
    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeDefined()
  })

  it('el 422 del servidor se muestra bajo el campo, con el nombre traducido', async () => {
    stubFetch((_url, init) =>
      init?.method === 'PATCH'
        ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: { 'settings.ATTENDANCE_BREAK_CLOCKING': ['El valor tiene que ser válido.'] },
          })
        : jsonResponse(catalog('48392017', 'disabled')),
    )

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    await wrapper.find('[data-test="break-clocking"]').setValue('enabled')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain(es.operationalSettings.fields.breakClocking)
    expect(wrapper.text()).toContain('El valor tiene que ser válido.')
  })
})

describe('OperationalSettingsView — resumen semanal por correo (RF-PR-05, tarea 3.12)', () => {
  it('carga «Desactivado» de serie', async () => {
    stubFetch(() => jsonResponse(catalog('48392017', 'disabled', 'disabled')))

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    expect(wrapper.find('[data-test="weekly-summary-email"]').element).toHaveProperty(
      'value',
      'disabled',
    )
  })

  it('activarlo y guardarlo manda solo esa clave', async () => {
    const fetchSpy = stubFetch((_url, init) =>
      init?.method === 'PATCH'
        ? jsonResponse(catalog('48392017', 'disabled', 'enabled'))
        : jsonResponse(catalog('48392017', 'disabled', 'disabled')),
    )

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    await wrapper.find('[data-test="weekly-summary-email"]').setValue('enabled')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    const patch = fetchSpy.mock.calls.find(([, init]) => (init as RequestInit).method === 'PATCH')

    expect(patch).toBeDefined()
    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      settings: { WEEKLY_SUMMARY_EMAIL: 'enabled' },
    })
  })
})

describe('OperationalSettingsView — ventana de actualizacion del quiosco (RF-KI-07, tarea 3.12)', () => {
  it('carga la ventana y los minutos de serie', async () => {
    stubFetch(() => jsonResponse(catalog('48392017', 'disabled', 'disabled', '03:00-05:00', 10)))

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    expect(wrapper.find('[data-test="kiosk-update-window"]').element).toHaveProperty(
      'value',
      '03:00-05:00',
    )
    expect(wrapper.find('[data-test="kiosk-update-quiet-minutes"]').element).toHaveProperty(
      'value',
      '10',
    )
  })

  it('sin fila propia de KIOSK_UPDATE_QUIET_MINUTES, se ve 0 explicito y no un valor de serie plausible', async () => {
    stubFetch(() => jsonResponse(catalogWithoutQuietMinutes()))

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    expect(wrapper.find('[data-test="kiosk-update-quiet-minutes"]').element).toHaveProperty(
      'value',
      '0',
    )
  })

  it('una ventana que cruza la medianoche es un cambio valido', async () => {
    const fetchSpy = stubFetch((_url, init) =>
      init?.method === 'PATCH'
        ? jsonResponse(catalog('48392017', 'disabled', 'disabled', '23:00-02:00', 10))
        : jsonResponse(catalog('48392017', 'disabled', 'disabled', '03:00-05:00', 10)),
    )

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    await wrapper.find('[data-test="kiosk-update-window"]').setValue('23:00-02:00')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    const patch = fetchSpy.mock.calls.find(([, init]) => (init as RequestInit).method === 'PATCH')

    expect(patch).toBeDefined()
    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      settings: { KIOSK_UPDATE_WINDOW: '23:00-02:00' },
    })
  })

  it('un formato invalido se rechaza en el propio panel, sin llegar al servidor', async () => {
    stubFetch(() => jsonResponse(catalog()))

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    await wrapper.find('[data-test="kiosk-update-window"]').setValue('25:99-05:00')
    await settle()

    expect(wrapper.text()).toContain(es.operationalSettings.errors.invalidUpdateWindowFormat)
    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeDefined()
  })

  it('cambiar los minutos de silencio manda solo esa clave', async () => {
    const fetchSpy = stubFetch((_url, init) =>
      init?.method === 'PATCH'
        ? jsonResponse(catalog('48392017', 'disabled', 'disabled', '03:00-05:00', 20))
        : jsonResponse(catalog()),
    )

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    await wrapper.find('[data-test="kiosk-update-quiet-minutes"]').setValue('20')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    const patch = fetchSpy.mock.calls.find(([, init]) => (init as RequestInit).method === 'PATCH')

    expect(patch).toBeDefined()
    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      settings: { KIOSK_UPDATE_QUIET_MINUTES: 20 },
    })
  })

  it('unos minutos fuera de rango se rechazan con el mensaje del servidor', async () => {
    stubFetch((_url, init) =>
      init?.method === 'PATCH'
        ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: {
              'settings.KIOSK_UPDATE_QUIET_MINUTES': [
                'El valor tiene que estar entre 0 y 120 minutos.',
              ],
            },
          })
        : jsonResponse(catalog()),
    )

    const wrapper = await mountView(OperationalSettingsView)
    await settle()

    await wrapper.find('[data-test="kiosk-update-quiet-minutes"]').setValue('200')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain(es.operationalSettings.fields.kioskUpdateQuietMinutes)
    expect(wrapper.text()).toContain('El valor tiene que estar entre 0 y 120 minutos.')
  })
})
