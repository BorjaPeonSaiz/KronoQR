// Aviso de privacidad del quiosco en la pantalla de marca (RF-KI-09, RL-09,
// RF-PD-01): responsable del tratamiento y URL de la politica, editables junto
// a la marca. Vacio = texto generico del quiosco. La validacion de cliente
// repite la del contrato; el 422 del servidor sale bajo el campo.
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import BrandingView from '@/features/settings/BrandingView.vue'
import es from '@/shared/i18n/locales/es.json'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'
import { installRealThemeTokens } from './support/theme'

const BASE = {
  type: 'text',
  impact: 'presentation',
  affects_worked_hours: false,
  source: 'product_default',
}

const CATALOG = {
  data: [
    { ...BASE, key: 'BRANDING_APP_NAME', value: 'KronoQR', constraints: { maximum_length: 60 } },
    { ...BASE, key: 'BRANDING_ACCENT_COLOR', value: '#b8542a' },
    { ...BASE, key: 'BRANDING_LOGO_PATH', value: '' },
    { ...BASE, key: 'PRIVACY_CONTROLLER_NAME', value: '', constraints: { maximum_length: 160 } },
    { ...BASE, key: 'PRIVACY_POLICY_URL', value: '', constraints: { maximum_length: 512 } },
  ],
  meta: { unknown_keys: [], invalid_keys: [] },
}

let undoTheme: () => void

beforeEach(() => {
  createTestPinia()
  undoTheme = installRealThemeTokens()
})

afterEach(() => {
  undoTheme()
})

const CONTROLLER = '[data-test="privacy-controller-name"]'
const URL_FIELD = '[data-test="privacy-policy-url"]'
const SAVE = '[data-test="save"]'

describe('BrandingView — aviso de privacidad del quiosco (RF-KI-09, RL-09, RF-PD-01)', () => {
  it('enseña los dos campos con su ayuda: se muestran en la tablet antes de fichar (art. 13 RGPD)', async () => {
    stubFetch(() => jsonResponse(CATALOG))

    const wrapper = await mountView(BrandingView)
    await settle()

    expect(wrapper.find(CONTROLLER).element).toHaveProperty('value', '')
    expect(wrapper.find(URL_FIELD).element).toHaveProperty('value', '')
    expect(wrapper.text()).toContain(es.branding.fields.privacyControllerName)
    expect(wrapper.text()).toContain(es.branding.fields.privacyPolicyUrl)
    expect(wrapper.text()).toContain('art. 13 RGPD')
    expect(wrapper.text()).toContain(es.branding.hints.privacyControllerName)
  })

  it('manda solo las dos claves nuevas, recortadas', async () => {
    const fetchSpy = stubFetch(() => jsonResponse(CATALOG))

    const wrapper = await mountView(BrandingView)
    await settle()

    await wrapper.find(CONTROLLER).setValue('  Hotel Marina, S.L.  ')
    await wrapper.find(URL_FIELD).setValue('https://hotel.example/privacidad')
    await wrapper.find(SAVE).trigger('submit')
    await settle()

    const patch = fetchSpy.mock.calls.find(([, init]) => (init as RequestInit).method === 'PATCH')

    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      settings: {
        PRIVACY_CONTROLLER_NAME: 'Hotel Marina, S.L.',
        PRIVACY_POLICY_URL: 'https://hotel.example/privacidad',
      },
    })
  })

  it('vaciar un valor ya guardado manda la cadena vacia (vuelve al texto generico)', async () => {
    const stored = {
      ...CATALOG,
      data: CATALOG.data.map((entry) =>
        entry.key === 'PRIVACY_POLICY_URL' ? { ...entry, value: 'https://hotel.example/p' } : entry,
      ),
    }
    const fetchSpy = stubFetch(() => jsonResponse(stored))

    const wrapper = await mountView(BrandingView)
    await settle()

    await wrapper.find(URL_FIELD).setValue('')
    await wrapper.find(SAVE).trigger('submit')
    await settle()

    const patch = fetchSpy.mock.calls.find(([, init]) => (init as RequestInit).method === 'PATCH')

    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      settings: { PRIVACY_POLICY_URL: '' },
    })
  })

  it.each([
    ['javascript:alert(1)', 'notAUrl'],
    ['ftp://hotel.example/p', 'notAUrl'],
    ['http://hotel.example/p', 'notAUrl'],
    ['https://hotel.es@malo.tld/x', 'notAUrl'],
    ['https://user:clave@hotel.example/x', 'notAUrl'],
    ['https://hotel.example/política', 'notAUrl'],
    ['https://hotel.example/con espacio', 'notAUrl'],
    [`https://hotel.example/${'a'.repeat(500)}`, 'tooLong'],
  ] as const)('la URL «%s» no deja guardar y lo dice bajo el campo', async (value, issue) => {
    stubFetch(() => jsonResponse(CATALOG))

    const wrapper = await mountView(BrandingView)
    await settle()

    await wrapper.find(URL_FIELD).setValue(value)

    expect(wrapper.find(URL_FIELD).attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain(es.branding.errors.privacyUrl[issue])
    expect(wrapper.find(SAVE).attributes('disabled')).toBeDefined()
  })

  it('enseña el host al que apunta la URL antes de guardar, y nada si es invalida o vacia', async () => {
    stubFetch(() => jsonResponse(CATALOG))

    const wrapper = await mountView(BrandingView)
    await settle()

    expect(wrapper.find('[data-test="privacy-url-host"]').exists()).toBe(false)

    await wrapper.find(URL_FIELD).setValue('https://hotel.es/privacidad?x=1')
    expect(wrapper.get('[data-test="privacy-url-host"]').text()).toBe('Se mostrará: hotel.es')

    await wrapper.find(URL_FIELD).setValue('https://hotel.es@malo.tld/x')
    expect(wrapper.find('[data-test="privacy-url-host"]').exists()).toBe(false)
  })

  it('un responsable de mas de 160 caracteres o con salto de linea no deja guardar', async () => {
    stubFetch(() => jsonResponse(CATALOG))

    const wrapper = await mountView(BrandingView)
    await settle()

    await wrapper.find(CONTROLLER).setValue('x'.repeat(161))
    expect(wrapper.text()).toContain(es.branding.errors.privacyController.tooLong)
    expect(wrapper.find(SAVE).attributes('disabled')).toBeDefined()

    await wrapper.find(CONTROLLER).setValue('Hotel\u0009Marina')
    expect(wrapper.text()).toContain(es.branding.errors.privacyController.controlChars)
    expect(wrapper.find(SAVE).attributes('disabled')).toBeDefined()

    // Caracteres Unicode de formato (Cf): inversion bidi y anchura cero.
    await wrapper.find(CONTROLLER).setValue('Hotel‮Marina')
    expect(wrapper.text()).toContain(es.branding.errors.privacyController.controlChars)
    await wrapper.find(CONTROLLER).setValue('Hotel​Marina')
    expect(wrapper.find(SAVE).attributes('disabled')).toBeDefined()

    await wrapper.find(CONTROLLER).setValue('x'.repeat(160))
    expect(wrapper.find(SAVE).attributes('disabled')).toBeUndefined()
  })

  it('pinta bajo cada campo el 422 del servidor, con el nombre del campo y no el de la columna', async () => {
    stubFetch((_url, init) =>
      init?.method === 'PATCH'
        ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: {
              'settings.PRIVACY_POLICY_URL': ['La direccion no tiene un anfitrion valido.'],
              'settings.PRIVACY_CONTROLLER_NAME': ['El responsable no es valido.'],
            },
          })
        : jsonResponse(CATALOG),
    )

    const wrapper = await mountView(BrandingView)
    await settle()

    await wrapper.find(CONTROLLER).setValue('Hotel Marina')
    await wrapper.find(URL_FIELD).setValue('https://a/b')
    await wrapper.find(SAVE).trigger('submit')
    await settle()

    expect(wrapper.text()).toContain('La direccion no tiene un anfitrion valido.')
    expect(wrapper.text()).toContain('El responsable no es valido.')
    expect(wrapper.text()).toContain(es.branding.fields.privacyPolicyUrl)
  })
})
