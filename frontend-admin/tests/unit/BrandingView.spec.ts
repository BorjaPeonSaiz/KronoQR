import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { useSessionStore } from '@/features/auth/session.store'
import BrandingView from '@/features/settings/BrandingView.vue'
import es from '@/shared/i18n/locales/es.json'
import type { License } from '@/shared/api/types'
import { useBrandingStore } from '@/shared/branding/branding.store'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'
import { managementUser } from './support/fixtures'
import { installRealThemeTokens } from './support/theme'

// La pantalla de marca (RF-PD-08, tarea 5.8): nombre, color de acento y ruta
// del logotipo de la instalacion. Lo que puede salir mal el dia que alguien la
// usa: que se guarde un cambio que no se ha hecho, que el aviso de contraste
// bloquee lo que solo tiene que avisar, que el `422` del logotipo se lea con
// el nombre de la columna y no con el del campo, y que la cabecera del panel
// se quede con la marca vieja tras guardar.

const RECIEN_INSTALADO = {
  data: [
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
  ],
  meta: { unknown_keys: [], invalid_keys: [] },
}

/**
 * Un plan sin la funcionalidad de marca propia (ADR-023, tarea 5.8): lo que
 * enseña `GET /api/v1/license` cuando `white_label` no esta contratada.
 * `whiteLabel` deja poner `null` (funcionalidad activa, sin degradar) o el
 * motivo (`not_in_plan`, `license_expired`, ...) para las distintas pruebas.
 */
function license(whiteLabel: License['data']['degraded_features'][number] | null): License {
  return {
    data: {
      state: 'valid',
      severity: 'none',
      rejection_reason: null,
      customer_name: 'Hotel Marina, S.L.',
      plan: 'estandar',
      license_id: 'lic-1',
      valid_from: '2026-01-01T00:00:00.000000Z',
      valid_until: '2027-01-01T00:00:00.000000Z',
      issued_at: '2025-12-15T10:00:00.000000Z',
      days_until_expiry: 90,
      days_since_expiry: null,
      features: whiteLabel === null ? ['white_label'] : [],
      degraded_features: whiteLabel === null ? [] : [whiteLabel],
      limits: [],
      activated_at: '2026-01-01T09:00:00.000000Z',
      last_verified_at: '2026-01-01T09:00:00.000000Z',
      key_fingerprint: '4b1e9c07a2d8',
    },
    meta: { expiry_warning_days: 30, needs_notice: false, evaluated_at: '2026-01-01T09:00:00Z' },
  }
}

/** Sesion de `admin`: el unico rol que llega a esta pantalla y tambien a la licencia. */
function mountAsAdmin(pinia: ReturnType<typeof createTestPinia>) {
  const session = useSessionStore(pinia)

  session.user = managementUser({ roles: ['admin'], abilities: ['settings:*', 'license:*'] })
  session.token = 'un-token'
  session.status = 'authenticated'

  return mountView(BrandingView, { pinia })
}

let undoTheme: () => void

beforeEach(() => {
  undoTheme = installRealThemeTokens()
})

afterEach(() => {
  undoTheme()
})

describe('BrandingView', () => {
  it('carga los valores de GET /settings', async () => {
    stubFetch(() => jsonResponse(RECIEN_INSTALADO))

    const wrapper = await mountView(BrandingView)
    await settle()

    expect(wrapper.find('[data-test="app-name"]').element).toHaveProperty('value', 'KronoQR')
    expect(wrapper.find('[data-test="accent-color-hex"]').element).toHaveProperty(
      'value',
      '#b8542a',
    )
    expect(wrapper.find('[data-test="logo-path"]').element).toHaveProperty('value', '')
  })

  it('no deja guardar mientras no haya ningun cambio', async () => {
    stubFetch(() => jsonResponse(RECIEN_INSTALADO))

    const wrapper = await mountView(BrandingView)
    await settle()

    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeDefined()
  })

  it('avisa cuando el color escrito no llega al contraste minimo, sin bloquear el guardado', async () => {
    stubFetch(() => jsonResponse(RECIEN_INSTALADO))

    const wrapper = await mountView(BrandingView)
    await settle()

    expect(wrapper.find('[data-test="contrast-warnings"]').exists()).toBe(false)

    // Un amarillo palido: no alcanza 4.5:1 como texto de marca en ningun
    // fondo del sistema visual (doc 06 §2).
    await wrapper.find('[data-test="accent-color-hex"]').setValue('#f5e663')

    expect(wrapper.find('[data-test="contrast-warnings"]').exists()).toBe(true)
    // Es un aviso, no un bloqueo (doc 06 §7): el boton sigue habilitado.
    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeUndefined()
  })

  it('manda solo las claves que han cambiado', async () => {
    const fetchSpy = stubFetch((_url, init) =>
      init?.method === 'PATCH'
        ? jsonResponse({
            data: [{ ...RECIEN_INSTALADO.data[0], value: 'Hotel Marina', source: 'installation' }],
            meta: { unknown_keys: [], invalid_keys: [] },
          })
        : jsonResponse(RECIEN_INSTALADO),
    )

    const wrapper = await mountView(BrandingView)
    await settle()

    await wrapper.find('[data-test="app-name"]').setValue('Hotel Marina')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    const patch = fetchSpy.mock.calls.find(([, init]) => (init as RequestInit).method === 'PATCH')

    expect(patch).toBeDefined()
    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      settings: { BRANDING_APP_NAME: 'Hotel Marina' },
    })
  })

  it('el boton de volver al color del producto escribe el hexadecimal del producto', async () => {
    stubFetch(() => jsonResponse(RECIEN_INSTALADO))

    const wrapper = await mountView(BrandingView)
    await settle()

    await wrapper.find('[data-test="accent-color-hex"]').setValue('#0f5c8c')
    await wrapper.find('[data-test="reset-accent"]').trigger('click')

    expect(wrapper.find('[data-test="accent-color-hex"]').element).toHaveProperty(
      'value',
      '#b8542a',
    )
  })

  it('enseña el 422 del logotipo bajo el campo de la ruta, no con el nombre de la columna', async () => {
    stubFetch((_url, init) =>
      init?.method === 'PATCH'
        ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: {
              'settings.BRANDING_LOGO_PATH': ['El fichero no es un PNG ni un SVG valido.'],
            },
          })
        : jsonResponse(RECIEN_INSTALADO),
    )

    const wrapper = await mountView(BrandingView)
    await settle()

    await wrapper.find('[data-test="logo-path"]').setValue('/var/kronoqr/branding/logo.txt')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain(es.branding.fields.logoPath)
    expect(wrapper.text()).toContain('El fichero no es un PNG ni un SVG valido.')
  })

  it('tras guardar, recarga la marca compartida para que la cabecera se actualice en el acto', async () => {
    let brandingCalls = 0

    stubFetch((url, init) => {
      if (init?.method === 'PATCH') {
        return jsonResponse({
          data: [{ ...RECIEN_INSTALADO.data[0], value: 'Hotel Marina', source: 'installation' }],
          meta: { unknown_keys: [], invalid_keys: [] },
        })
      }

      if (new URL(url, 'http://localhost').pathname === '/api/v1/branding') {
        brandingCalls += 1

        return jsonResponse({
          application_name: 'Hotel Marina',
          accent_color: null,
          logo_url: null,
          locales: { default: 'es', available: ['es'] },
        })
      }

      return jsonResponse(RECIEN_INSTALADO)
    })

    const pinia = createTestPinia()
    const wrapper = await mountView(BrandingView, { pinia })
    await settle()

    await wrapper.find('[data-test="app-name"]').setValue('Hotel Marina')
    await wrapper.find('[data-test="save"]').trigger('submit')
    await settle()

    expect(brandingCalls).toBeGreaterThan(0)
    expect(useBrandingStore(pinia).current.applicationName).toBe('Hotel Marina')
  })

  it('enseña el error de carga sin dejar el formulario a medias', async () => {
    stubFetch(() => problemResponse(404, 'urn:kronoqr:problem:not-found'))

    const wrapper = await mountView(BrandingView)
    await settle()

    expect(wrapper.find('[data-test="save"]').exists()).toBe(false)
    expect(wrapper.text()).not.toBe('')
  })
})

// --- Marca propia licenciable (ADR-023) --------------------------------------
//
// La marca blanca ES una funcionalidad accesoria (`white_label` en el enum
// `LicenseFeature`): sin plan que la cubra, o con la licencia caducada, el
// backend sigue guardando y auditando lo que se escribe aqui, pero
// `GET /api/v1/branding` responde con la marca del producto hasta que se
// renueve (ADR-023). Esta pantalla lo dice arriba, sin bloquear el guardado.

describe('BrandingView — marca propia licenciable (ADR-023)', () => {
  it('con la marca propia fuera del plan, avisa y enlaza a la licencia, sin bloquear el guardado', async () => {
    stubFetch((url) => {
      const path = new URL(url, 'http://localhost').pathname

      return path === '/api/v1/license'
        ? jsonResponse(
            license({
              feature: 'white_label',
              restriction: 'not_in_plan',
              since: null,
              implemented: true,
            }),
          )
        : jsonResponse(RECIEN_INSTALADO)
    })

    const pinia = createTestPinia()
    const wrapper = await mountAsAdmin(pinia)
    await settle()

    const notice = wrapper.find('[data-test="license-restriction"]')

    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('no está incluida en el plan contratado')
    expect(notice.text()).toContain(es.license.notice.action)

    // Persistente, pero NO bloqueante: sin cambios el boton sigue deshabilitado
    // por la misma regla de siempre («nada que guardar»), no por la licencia.
    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeDefined()
    await wrapper.find('[data-test="app-name"]').setValue('Hotel Marina')
    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeUndefined()
  })

  it('con la licencia caducada, cambia el motivo del aviso', async () => {
    stubFetch((url) => {
      const path = new URL(url, 'http://localhost').pathname

      return path === '/api/v1/license'
        ? jsonResponse(
            license({
              feature: 'white_label',
              restriction: 'license_expired',
              since: '2026-06-01T00:00:00.000000Z',
              implemented: true,
            }),
          )
        : jsonResponse(RECIEN_INSTALADO)
    })

    const pinia = createTestPinia()
    const wrapper = await mountAsAdmin(pinia)
    await settle()

    expect(wrapper.find('[data-test="license-restriction"]').text()).toContain(
      'la licencia ha caducado',
    )
  })

  it('con la marca propia incluida en el plan, no avisa', async () => {
    stubFetch((url) => {
      const path = new URL(url, 'http://localhost').pathname

      return path === '/api/v1/license'
        ? jsonResponse(license(null))
        : jsonResponse(RECIEN_INSTALADO)
    })

    const pinia = createTestPinia()
    const wrapper = await mountAsAdmin(pinia)
    await settle()

    expect(wrapper.find('[data-test="license-restriction"]').exists()).toBe(false)
  })

  it('sin ambito de licencia, no pide GET /api/v1/license y no avisa', async () => {
    const licenseRequests: string[] = []

    stubFetch((url) => {
      const path = new URL(url, 'http://localhost').pathname

      if (path === '/api/v1/license') {
        licenseRequests.push(path)
      }

      return jsonResponse(RECIEN_INSTALADO)
    })

    const pinia = createTestPinia()
    const session = useSessionStore(pinia)

    // `settings:*` sin `license:*`: llega a la pantalla, pero no a la licencia.
    session.user = managementUser({ roles: ['admin'], abilities: ['settings:*'] })
    session.token = 'un-token'
    session.status = 'authenticated'

    const wrapper = await mountView(BrandingView, { pinia })
    await settle()

    expect(licenseRequests).toEqual([])
    expect(wrapper.find('[data-test="license-restriction"]').exists()).toBe(false)
  })
})
