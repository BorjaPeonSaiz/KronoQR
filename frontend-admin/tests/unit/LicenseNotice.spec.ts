import { beforeEach, describe, expect, it } from 'vitest'
import LicenseNotice from '@/features/settings/LicenseNotice.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import { createTestPinia, jsonResponse, mountView, settle, stubFetch } from './support/harness'

// El aviso PERSISTENTE de licencia (RF-PD-05, ADR-019, ADR-028).
//
// Las tres cosas que este componente tiene que hacer bien, y que se comprueban
// aqui una por una:
//
//  1. **No se puede descartar.** No hay boton de cerrar. ADR-028 lo exige por
//     escrito, y el motivo es que un aviso descartable sobre una licencia que
//     caduca en treinta dias se cierra el primer dia y ya nadie se entera.
//  2. **Cambia de tono al caducar, no de sitio**, y el texto pasa de «caduca el
//     X; esto se degradara» a «caducó el X; esto está degradado desde
//     entonces».
//  3. **Nunca dice que el sistema este parado**, porque no lo esta: cada
//     variante recuerda que se sigue fichando (regla dura 15).
//
// Y una cuarta: solo lo ve quien puede hacer algo con el.

function licenseBody(data: Record<string, unknown>, needsNotice = true) {
  return {
    data: {
      state: 'valid',
      severity: 'none',
      rejection_reason: null,
      customer_name: 'Hotel Ejemplo, S.L.',
      plan: 'estandar',
      license_id: 'lic-1',
      valid_from: '2026-01-01T00:00:00.000000Z',
      valid_until: '2026-12-31T23:59:59.000000Z',
      issued_at: '2025-12-15T10:00:00.000000Z',
      days_until_expiry: 199,
      days_since_expiry: null,
      features: [],
      degraded_features: [],
      limits: [
        { limit: 'max_employees', contracted: 80, actual: 62, exceeded: false, excess: 0 },
        { limit: 'max_devices', contracted: 3, actual: 2, exceeded: false, excess: 0 },
      ],
      activated_at: '2026-01-02T09:00:00.000000Z',
      last_verified_at: '2026-06-15T09:00:00.000000Z',
      key_fingerprint: '4b1e9c07a2d8',
      ...data,
    },
    meta: {
      expiry_warning_days: 30,
      needs_notice: needsNotice,
      evaluated_at: '2026-06-15T09:00:00.000000Z',
    },
  }
}

/**
 * Monta el aviso con una sesion que lleva —o no— el ambito de licencia.
 */
async function mountNotice(abilities: string[]) {
  const pinia = createTestPinia()
  const session = useSessionStore()

  session.user = {
    uuid: '01a05bea-0000-7000-8000-000000000001',
    name: 'Marta Admin',
    email: 'marta@example.test',
    locale: 'es',
    roles: ['admin'],
    abilities,
    scope: { kind: 'all', department_ids: [] },
  }

  return mountView(LicenseNotice, { pinia })
}

beforeEach(() => {
  stubFetch(() => jsonResponse(licenseBody({})))
})

describe('aviso persistente de licencia', () => {
  it('no se pinta con la licencia vigente', async () => {
    stubFetch(() => jsonResponse(licenseBody({}, false)))

    const wrapper = await mountNotice(['license:*'])
    await settle()

    // Un banner permanente en una instalacion sana se aprende a ignorar, y
    // entonces tampoco se lee el dia que dice algo.
    expect(wrapper.find('[data-test="license-notice"]').exists()).toBe(false)
  })

  it('avisa antes de caducar y dice que todavia no se ha perdido nada', async () => {
    stubFetch(() =>
      jsonResponse(
        licenseBody({ state: 'expiring_soon', severity: 'warning', days_until_expiry: 12 }),
      ),
    )

    const wrapper = await mountNotice(['license:*'])
    await settle()

    const notice = wrapper.find('[data-test="license-notice"]')

    expect(notice.exists()).toBe(true)
    expect(notice.attributes('data-severity')).toBe('warning')
    expect(notice.text()).toContain('Todavía no se ha degradado nada')
    // Persistente: no hay forma de cerrarlo.
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('al caducar cambia de tono y de texto, sin cambiar de sitio', async () => {
    stubFetch(() =>
      jsonResponse(
        licenseBody({
          state: 'expired',
          severity: 'critical',
          days_until_expiry: 0,
          days_since_expiry: 12,
        }),
      ),
    )

    const wrapper = await mountNotice(['license:*'])
    await settle()

    const notice = wrapper.find('[data-test="license-notice"]')

    expect(notice.attributes('data-severity')).toBe('critical')
    expect(notice.text()).toContain('están degradados desde entonces')
    // La prueba que faltaba y que habría cazado el fallo: la versión anterior
    // formateaba con el `d()` de vue-i18n sin `datetimeFormats`, así que el
    // banner decía «La licencia caducó el , hace 12 días».
    expect(notice.text()).toContain('31 dic 2026')
    expect(notice.text()).not.toContain('caducó el ,')
    // La frase que evita la llamada de soporte de las siete de la mañana.
    expect(notice.text()).toContain('El fichaje y el acceso al registro siguen funcionando')
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('nunca dice que el sistema este parado, en ningun estado', async () => {
    for (const state of ['expiring_soon', 'expired', 'absent', 'not_yet_valid', 'unverifiable']) {
      const text = es.license.notice[state as keyof typeof es.license.notice]

      expect(text).not.toContain('no funciona')
      expect(text).not.toContain('bloquea')
      expect(text).not.toContain('detenido')
    }
  })

  it('avisa tambien del exceso de plan, y aclara que no bloquea nada', async () => {
    stubFetch(() =>
      jsonResponse(
        licenseBody({
          severity: 'warning',
          limits: [
            { limit: 'max_employees', contracted: 50, actual: 53, exceeded: true, excess: 3 },
            { limit: 'max_devices', contracted: 3, actual: 2, exceeded: false, excess: 0 },
          ],
        }),
      ),
    )

    const wrapper = await mountNotice(['license:*'])
    await settle()

    const notice = wrapper.find('[data-test="license-notice"]')
    const main = wrapper.find('[data-test="license-notice-text"]')
    const excess = wrapper.find('[data-test="license-notice-excess"]')

    expect(excess.exists()).toBe(true)
    expect(excess.text()).toContain(es.license.limits.max_employees)
    expect(excess.text()).toContain('No bloquea nada')

    // El texto PRINCIPAL, no solo el sufijo. Con la licencia vigente y el plan
    // superado, el estado es `valid` y faltaba su texto: el banner imprimía la
    // clave de traducción en crudo, `license.notice.valid`.
    expect(main.text()).toBe(es.license.notice.valid)
    expect(notice.text()).not.toContain('license.notice.')
  })

  it('no se pinta para quien no puede renovar', async () => {
    // A un responsable de departamento un aviso de licencia solo le da ruido.
    const wrapper = await mountNotice(['attendance:read', 'incidents:*'])
    await settle()

    expect(wrapper.find('[data-test="license-notice"]').exists()).toBe(false)
  })

  it('lleva al detalle de la licencia', async () => {
    stubFetch(() => jsonResponse(licenseBody({ state: 'expired', severity: 'critical' })))

    const wrapper = await mountNotice(['license:*'])
    await settle()

    expect(wrapper.find('[data-test="license-notice-link"]').attributes('href')).toBe('/license')
  })
})
