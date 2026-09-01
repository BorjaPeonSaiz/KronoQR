import { beforeEach, describe, expect, it } from 'vitest'
import LicenseView from '@/features/settings/LicenseView.vue'
import es from '@/shared/i18n/locales/es.json'
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { jsonResponse, mountView, problemResponse, settle, stubFetch } from './support/harness'

// La pantalla de licencia (RF-PD-04, RF-PD-05, ADR-019, ADR-028).
//
// Lo que se comprueba aqui es lo que puede salir mal el dia que a un cliente le
// caduca la licencia:
//
//  - Que la promesa del producto —el registro horario no depende de la
//    licencia— este siempre a la vista, en cualquier estado. Es la respuesta a
//    la pregunta con la que se llega a esta pantalla.
//  - Que **no se enseñe nunca la clave firmada**, solo su huella.
//  - Que lo degradado diga **por que** y **desde cuando**, y que no se anuncie
//    la perdida de funcionalidades que esta version todavia no tiene.
//  - Que un exceso de plan se enseñe **con la aclaracion de que no ha bloqueado
//    nada** (ADR-028).
//  - Que un `422` al activar se lea con el nombre del campo en castellano.

const KEY_FINGERPRINT = '4b1e9c07a2d8'

function licenseBody(overrides: Record<string, unknown> = {}, meta: Record<string, unknown> = {}) {
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
      features: ['advanced_reports', 'realtime_presence'],
      degraded_features: [],
      limits: [
        { limit: 'max_employees', contracted: 80, actual: 62, exceeded: false, excess: 0 },
        { limit: 'max_devices', contracted: 3, actual: 2, exceeded: false, excess: 0 },
      ],
      activated_at: '2026-01-02T09:00:00.000000Z',
      last_verified_at: '2026-06-15T09:00:00.000000Z',
      key_fingerprint: KEY_FINGERPRINT,
      ...overrides,
    },
    meta: {
      expiry_warning_days: 30,
      needs_notice: false,
      evaluated_at: '2026-06-15T09:00:00.000000Z',
      ...meta,
    },
  }
}

beforeEach(() => {
  clearAnnouncement()
})

describe('pantalla de licencia', () => {
  it('enseña siempre que el registro horario no depende de la licencia', async () => {
    stubFetch(() => jsonResponse(licenseBody()))

    const wrapper = await mountView(LicenseView)
    await settle()

    // La promesa de ADR-019, arriba y sin buscarla: es la respuesta a la
    // pregunta con la que se llega («¿ha dejado de funcionar algo?»).
    expect(wrapper.find('[data-test="never-degraded"]').text()).toBe(es.license.neverDegraded)
    expect(wrapper.find('[data-test="state"]').attributes('data-state')).toBe('valid')
    expect(wrapper.find('[data-test="customer"]').text()).toBe('Hotel Ejemplo, S.L.')
    expect(wrapper.find('[data-test="plan"]').text()).toBe('estandar')
  })

  it('enseña el día concreto de la vigencia, no un hueco', async () => {
    // La prueba que faltaba y que habría cazado el fallo: la versión anterior
    // formateaba con el `d()` de vue-i18n, y esta aplicación no declara
    // `datetimeFormats`, así que **devolvía cadena vacía**. La pantalla decía
    // «Vigencia: – » y el aviso «caducó el , hace 12 días».
    //
    // Se afirma el día en UTC a propósito: la vigencia la fija el fabricante al
    // emitir y es la que aparece en la factura (ver `license.dates.ts`).
    stubFetch(() => jsonResponse(licenseBody()))

    const wrapper = await mountView(LicenseView)
    await settle()

    const validity = wrapper.find('[data-test="validity"]').text()

    expect(validity).toContain('1 ene 2026')
    expect(validity).toContain('31 dic 2026')
    expect(validity).not.toContain('—')
  })

  it('nunca enseña la clave firmada, solo su huella', async () => {
    stubFetch(() => jsonResponse(licenseBody()))

    const wrapper = await mountView(LicenseView)
    await settle()

    expect(wrapper.find('[data-test="fingerprint"]').text()).toBe(KEY_FINGERPRINT)
    // Se busca la FORMA de una clave —prefijo mas carga util— y no la cadena
    // «KQL1.» a secas, que aparece a proposito en la ayuda del formulario para
    // que quien pega sepa que esta pegando lo correcto.
    expect(wrapper.html()).not.toMatch(/KQL1\.[A-Za-z0-9_-]{10,}/)
  })

  it('con la licencia caducada dice desde cuando y que se ha perdido', async () => {
    stubFetch(() =>
      jsonResponse(
        licenseBody(
          {
            state: 'expired',
            severity: 'critical',
            days_until_expiry: 0,
            days_since_expiry: 166,
            degraded_features: [
              {
                feature: 'advanced_reports',
                restriction: 'license_expired',
                since: '2026-12-31T23:59:59.000000Z',
                implemented: true,
              },
              // Esta todavia no existe en esta version: no se le anuncia al
              // cliente la perdida de algo que no ha visto nunca.
              {
                feature: 'impact_dashboard',
                restriction: 'license_expired',
                since: '2026-12-31T23:59:59.000000Z',
                implemented: false,
              },
            ],
          },
          { needs_notice: true },
        ),
      ),
    )

    const wrapper = await mountView(LicenseView)
    await settle()

    expect(wrapper.find('[data-test="state"]').attributes('data-severity')).toBe('critical')
    expect(wrapper.find('[data-test="degraded-advanced_reports"]').exists()).toBe(true)
    const degradedText = wrapper.find('[data-test="degraded-advanced_reports"]').text()

    // El día concreto, no un hueco: es la mitad de «desde cuándo» que exige
    // ADR-019 de una degradación honesta.
    expect(degradedText).toContain('caducada')
    expect(degradedText).toContain('31 dic 2026')
    expect(wrapper.find('[data-test="degraded-impact_dashboard"]').exists()).toBe(false)
  })

  it('con la licencia proxima a caducar dice que todavia no se ha perdido nada', async () => {
    stubFetch(() =>
      jsonResponse(
        licenseBody(
          { state: 'expiring_soon', severity: 'warning', days_until_expiry: 12 },
          { needs_notice: true },
        ),
      ),
    )

    const wrapper = await mountView(LicenseView)
    await settle()

    expect(wrapper.find('[data-test="state-detail"]').text()).toContain(
      'Todavía no se ha degradado nada',
    )
    expect(wrapper.find('[data-test="degraded-none"]').text()).toBe(es.license.degradedNone)
  })

  it('enseña el exceso de plan y aclara que no ha bloqueado nada', async () => {
    stubFetch(() =>
      jsonResponse(
        licenseBody(
          {
            severity: 'warning',
            limits: [
              { limit: 'max_employees', contracted: 50, actual: 53, exceeded: true, excess: 3 },
              { limit: 'max_devices', contracted: 3, actual: 2, exceeded: false, excess: 0 },
            ],
          },
          { needs_notice: true },
        ),
      ),
    )

    const wrapper = await mountView(LicenseView)
    await settle()

    expect(wrapper.find('[data-test="limit-max_employees"]').attributes('data-exceeded')).toBe(
      'true',
    )
    expect(wrapper.find('[data-test="limit-max_devices"]').attributes('data-exceeded')).toBe(
      'false',
    )
    // ADR-028: quien lo ve esta buscando una explicacion a un problema, y este
    // no lo es.
    expect(wrapper.find('[data-test="excess-note"]').text()).toBe(es.license.excessNote)
  })

  it('explica por que una clave guardada no se puede verificar', async () => {
    stubFetch(() =>
      jsonResponse(
        licenseBody(
          {
            state: 'unverifiable',
            severity: 'critical',
            rejection_reason: 'no_public_key',
            customer_name: null,
            plan: null,
            valid_from: null,
            valid_until: null,
            days_until_expiry: null,
          },
          { needs_notice: true },
        ),
      ),
    )

    const wrapper = await mountView(LicenseView)
    await settle()

    // El motivo distingue «revisa el despliegue» de «pide otra clave», que son
    // dos llamadas distintas a dos personas distintas.
    expect(wrapper.find('[data-test="rejection"]').text()).toBe(es.license.rejections.no_public_key)
  })

  it('activa una clave y refresca el estado sin recargar', async () => {
    let activated = false

    stubFetch((_url, init) => {
      if (init?.method === 'POST') {
        activated = true

        return jsonResponse(licenseBody({ customer_name: 'Hotel Renovado, S.L.' }))
      }

      return jsonResponse(
        licenseBody({ state: 'expired', severity: 'critical' }, { needs_notice: true }),
      )
    })

    const wrapper = await mountView(LicenseView)
    await settle()

    await wrapper.find('[data-test="signed-key"]').setValue('KQL1.carga.firma')
    await wrapper.find('[data-test="activate"]').trigger('submit')
    await settle()

    expect(activated).toBe(true)
    expect(wrapper.find('[data-test="activated"]').text()).toBe(es.license.activated)
    // Lo que se pinta es lo que quedo escrito, sin recomponerlo desde lo que se
    // envio.
    expect(wrapper.find('[data-test="customer"]').text()).toBe('Hotel Renovado, S.L.')
  })

  it('lee el 422 de una clave rechazada con el nombre del campo en castellano', async () => {
    stubFetch((_url, init) => {
      if (init?.method === 'POST') {
        return problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
          errors: {
            signed_key: ['La clave está incompleta o cortada. Vuelve a copiarla entera.'],
          },
        })
      }

      return jsonResponse(licenseBody())
    })

    const wrapper = await mountView(LicenseView)
    await settle()

    await wrapper.find('[data-test="signed-key"]').setValue('KQL1.rota')
    await wrapper.find('[data-test="activate"]').trigger('submit')
    await settle()

    const html = wrapper.html()

    expect(html).toContain(es.license.fields.signedKey)
    expect(html).toContain('incompleta o cortada')
  })

  it('no deja activar con el campo vacio', async () => {
    stubFetch(() => jsonResponse(licenseBody()))

    const wrapper = await mountView(LicenseView)
    await settle()

    expect(wrapper.find('[data-test="activate"]').attributes('disabled')).toBeDefined()
  })
})
