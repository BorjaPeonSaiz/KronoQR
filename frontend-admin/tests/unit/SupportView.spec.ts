// Pantalla «Soporte» (RF-PD-09, RF-PD-11, ADR-020): el paquete de diagnostico
// y los accesos temporales de soporte.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SupportView from '@/features/support/SupportView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import {
  SITE,
  SUPPORT_GRANT_UUID,
  managementUser,
  supportGrant,
  supportGrantCollection,
} from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubRoutes,
} from './support/harness'

function bundleResponse(filename: string): Response {
  return new Response(JSON.stringify({ manifest: { schema_version: 1 } }), {
    status: 200,
    headers: {
      'Content-Type': 'application/json',
      'Content-Disposition': `attachment; filename=${filename}`,
    },
  })
}

/**
 * Monta la pantalla con una sesion ya autenticada. Por omision, `admin`
 * (abilities `['*']`): es el unico rol que llega aqui de verdad (doc 02
 * §7.3), y sin sesion los dos bloques se ocultan por cortesia (regla dura 18,
 * `canGenerateDiagnostics`/`canManageSupportGrants` en `SupportView.vue`).
 */
async function mountSupportView(
  abilities: string[] = ['*'],
): Promise<Awaited<ReturnType<typeof mountView>>> {
  const pinia = createTestPinia()
  const session = useSessionStore(pinia)

  session.token = 'token'
  session.status = 'authenticated'
  session.user = managementUser({ abilities })

  const wrapper = await mountView(SupportView, { pinia })

  await settle()

  return wrapper
}

let createObjectURL: ReturnType<typeof vi.fn>
let revokeObjectURL: ReturnType<typeof vi.fn>

beforeEach(() => {
  clearAnnouncement()
  createObjectURL = vi.fn(() => 'blob:kronoqr')
  revokeObjectURL = vi.fn()
  // Se AÑADEN los dos metodos al `URL` de verdad -no se sustituye el global
  // entero-: `stubRoutes` (`./support/harness`) usa `new URL(...)` para leer
  // la ruta de cada peticion, y reemplazar `URL` por un objeto plano (como
  // hace `LegalExportView.spec.ts`, que nunca llama a `new URL()`) lo
  // dejaría sin constructor y cada peticion caeria en un `ApiError` de red.
  Object.defineProperty(URL, 'createObjectURL', {
    value: createObjectURL,
    writable: true,
    configurable: true,
  })
  Object.defineProperty(URL, 'revokeObjectURL', {
    value: revokeObjectURL,
    writable: true,
    configurable: true,
  })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})

afterEach(() => {
  Reflect.deleteProperty(URL as unknown as object, 'createObjectURL')
  Reflect.deleteProperty(URL as unknown as object, 'revokeObjectURL')
  vi.restoreAllMocks()
})

describe('pantalla de soporte', () => {
  it('enseña los dos bloques: el paquete de diagnostico y los accesos de soporte', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/support/grants': () => jsonResponse(supportGrantCollection([])),
    })

    const wrapper = await mountSupportView()

    expect(wrapper.find('h1').text()).toBe(es.support.heading)
    expect(wrapper.text()).toContain(es.support.diagnostics.heading)
    expect(wrapper.text()).toContain(es.support.grant.heading)
  })

  it('sin ninguno de los dos ambitos, no enseña ningun bloque (cortesia, regla dura 18)', async () => {
    stubRoutes({ '/site': () => jsonResponse(SITE) })

    const wrapper = await mountSupportView([])

    expect(wrapper.find('[data-test="diagnostics-block"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="grants-block"]').exists()).toBe(false)
  })

  it('la casilla de datos personales esta desmarcada por defecto, y al marcarla enseña el aviso y el selector de dias', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/support/grants': () => jsonResponse(supportGrantCollection([])),
    })

    const wrapper = await mountSupportView()

    const checkbox = wrapper.find<HTMLInputElement>('[data-test="include-personal-data"]')

    expect(checkbox.element.checked).toBe(false)
    expect(wrapper.find('[data-test="personal-data-warning"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="period-days"]').exists()).toBe(false)

    await checkbox.setValue(true)
    await settle()

    const warning = wrapper.find('[data-test="personal-data-warning"]')

    expect(warning.exists()).toBe(true)
    expect(warning.attributes('role')).toBe('alert')
    expect(warning.text()).toBe(es.support.diagnostics.personalDataWarning)
    expect(wrapper.find('[data-test="period-days"]').exists()).toBe(true)
  })

  it('generar y descargar manda include_personal_data al servidor y enseña el nombre del fichero', async () => {
    let lastBody: unknown = null

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/support/grants': () => jsonResponse(supportGrantCollection([])),
      '/diagnostics/bundle': (_url, init) => {
        lastBody = init?.body === undefined ? null : JSON.parse(String(init.body))

        return bundleResponse('kronoqr-diagnostics-2.2.0-20260908T101500Z.json')
      },
    })

    const wrapper = await mountSupportView()

    await wrapper.find<HTMLInputElement>('[data-test="include-personal-data"]').setValue(true)
    await settle()

    await wrapper.find('[data-test="generate-bundle"]').trigger('click')
    await settle()

    expect(lastBody).toMatchObject({ include_personal_data: true })

    const success = wrapper.find('[data-test="diagnostics-success"]')

    expect(success.exists()).toBe(true)
    expect(success.text()).toContain('kronoqr-diagnostics-2.2.0-20260908T101500Z.json')
    // Se descarga y se suelta, como el resto de documentos del panel.
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:kronoqr')
  })

  it('conceder un acceso enseña el token una sola vez y lo lista como activa', async () => {
    let granted = false

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/support/grants': (_url, init) => {
        if ((init?.method ?? 'GET') === 'POST') {
          granted = true

          return jsonResponse({
            data: {
              ...supportGrant(),
              token: '23|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ',
            },
          })
        }

        return jsonResponse(supportGrantCollection(granted ? [supportGrant()] : []))
      },
    })

    const wrapper = await mountSupportView()

    expect(wrapper.find('[data-test="issued-token"]').exists()).toBe(false)

    await wrapper.find('[data-test="reason"]').setValue('Incidencia #123')
    await wrapper.find('form').trigger('submit')
    await settle()

    const tokenBox = wrapper.find('[data-test="issued-token"]')

    expect(tokenBox.exists()).toBe(true)
    expect(wrapper.find('[data-test="token-value"]').text()).toBe(
      '23|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ',
    )

    // Y la lista, recargada, ya la enseña activa: el listado que devuelve el
    // servidor NUNCA vuelve a traer el token en claro (el tipo `SupportGrant`
    // de la coleccion no tiene ese campo; solo `IssuedSupportGrant`, la
    // respuesta de conceder, lo lleva).
    expect(wrapper.text()).toContain(es.support.grant.status.active)
  })

  it('avisa junto al motivo de no incluir datos personales', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/support/grants': () => jsonResponse(supportGrantCollection([])),
    })

    const wrapper = await mountSupportView()

    expect(wrapper.text()).toContain(es.support.grant.fields.reasonPersonalHint)
  })

  it('pinta junto al campo el 422 de motivo con datos personales', async () => {
    const message = 'El motivo parece contener un correo electronico.'

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/support/grants': (_url, init) =>
        (init?.method ?? 'GET') === 'POST'
          ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
              errors: { reason: [message] },
            })
          : jsonResponse(supportGrantCollection([])),
    })

    const wrapper = await mountSupportView()

    await wrapper.find('[data-test="reason"]').setValue('Incidencia de ana@example.com')
    await wrapper.find('form').trigger('submit')
    await settle()

    const input = wrapper.find('[data-test="reason"]')
    const describedBy = (input.attributes('aria-describedby') ?? '').split(' ')

    expect(input.attributes('aria-invalid')).toBe('true')
    expect(describedBy.some((id) => wrapper.find(`[id="${id}"]`).text().includes(message))).toBe(
      true,
    )
  })

  it('una concesion con el motivo retirado dice cuando, en vez de dejar la celda vacia', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/support/grants': () =>
        jsonResponse(
          supportGrantCollection([
            supportGrant({
              status: 'expired',
              reason: null,
              reason_purged_at: '2026-12-20T03:00:00.000000Z',
            }),
          ]),
        ),
    })

    const wrapper = await mountSupportView()
    const cell = wrapper.find('[data-test="reason-purged"]')

    expect(cell.exists()).toBe(true)
    expect(cell.text()).toContain('Motivo retirado el')
    expect(cell.text()).toContain('20 dic 2026, 04:00')
  })

  it('un autor minimizado (granted_by.name vacio) se rotula en vez de dejar el hueco', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/support/grants': () =>
        jsonResponse(
          supportGrantCollection([
            supportGrant({ granted_by: { uuid: SUPPORT_GRANT_UUID, name: '' } }),
          ]),
        ),
    })

    const wrapper = await mountSupportView()

    expect(wrapper.text()).toContain(es.people.minimised)
  })

  it('no deja conceder con el motivo vacio', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/support/grants': () => jsonResponse(supportGrantCollection([])),
    })

    const wrapper = await mountSupportView()

    expect(wrapper.find('[data-test="grant-submit"]').attributes('aria-disabled')).toBeDefined()
  })

  it('revocar pide confirmacion y deja la fila como revocada', async () => {
    let revoked = false

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      [`/support/grants/${SUPPORT_GRANT_UUID}`]: () => {
        revoked = true

        return new Response(null, { status: 204 })
      },
      '/support/grants': () =>
        jsonResponse(
          supportGrantCollection([
            supportGrant(
              revoked
                ? { status: 'revoked', revoked_at: '2026-09-08T10:00:00.000000Z' }
                : { status: 'active' },
            ),
          ]),
        ),
    })

    const wrapper = await mountSupportView()

    await wrapper.find(`[data-test="revoke-${SUPPORT_GRANT_UUID}"]`).trigger('click')
    await settle()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)

    const confirmButton = wrapper
      .findAll('button')
      .find(
        (button) =>
          button.text() === es.support.grant.revoke.action &&
          button.element.closest('[role="dialog"]') !== null,
      )

    expect(confirmButton).toBeDefined()
    await confirmButton?.trigger('click')
    await settle()

    expect(revoked).toBe(true)
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.text()).toContain(es.support.grant.status.revoked)
    // Sin boton de revocar en una fila ya revocada.
    expect(wrapper.find(`[data-test="revoke-${SUPPORT_GRANT_UUID}"]`).exists()).toBe(false)
  })

  describe('usos de una concesion (RF-PD-11)', () => {
    function usesPage(
      data: { started_at: string; method: string | null; route: string | null }[],
      meta: { page: number; total: number; total_pages: number },
    ): Response {
      return jsonResponse({ data, meta: { per_page: 25, ...meta } })
    }

    it('RF-PD-11: abre el dialogo con las ventanas, la nota de 15 minutos y la zona del centro', async () => {
      stubRoutes({
        '/site': () => jsonResponse(SITE),
        [`/support/grants/${SUPPORT_GRANT_UUID}/uses`]: () =>
          usesPage(
            [
              {
                started_at: '2026-09-08T10:00:00.000000Z',
                method: 'GET',
                route: 'api/v1/diagnostics/errors',
              },
              { started_at: '2026-09-08T09:30:00.000000Z', method: null, route: null },
            ],
            { page: 1, total: 2, total_pages: 1 },
          ),
        '/support/grants': () => jsonResponse(supportGrantCollection([supportGrant()])),
      })

      const wrapper = await mountSupportView()

      await wrapper.find(`[data-test="uses-${SUPPORT_GRANT_UUID}"]`).trigger('click')
      await settle()

      const dialog = wrapper.find('[role="dialog"]')

      expect(dialog.exists()).toBe(true)
      expect(dialog.find('[data-test="grant-uses-note"]').text()).toBe(
        es.support.grant.uses.windowNote,
      )

      const rows = dialog.findAll('[data-test="grant-use-row"]')

      expect(rows).toHaveLength(2)
      expect(rows[0]?.text()).toContain('GET')
      expect(rows[0]?.text()).toContain('api/v1/diagnostics/errors')
      // 10:00 UTC son las 12:00 en Madrid (CEST), con la zona a la vista.
      expect(rows[0]?.text()).toContain('12:00')
      expect(rows[0]?.text()).toContain('CEST')
      // Un asiento sin metodo ni ruta no deja la celda en blanco.
      expect(rows[1]?.text()).toContain(es.common.empty)
    })

    it('RF-PD-11: sin usos enseña el estado vacio', async () => {
      stubRoutes({
        '/site': () => jsonResponse(SITE),
        [`/support/grants/${SUPPORT_GRANT_UUID}/uses`]: () =>
          usesPage([], { page: 1, total: 0, total_pages: 0 }),
        '/support/grants': () => jsonResponse(supportGrantCollection([supportGrant()])),
      })

      const wrapper = await mountSupportView()

      await wrapper.find(`[data-test="uses-${SUPPORT_GRANT_UUID}"]`).trigger('click')
      await settle()

      expect(wrapper.find('[role="dialog"]').text()).toContain(es.support.grant.uses.empty.title)
    })

    it('RF-PD-11: un fallo del servidor se avisa en el dialogo', async () => {
      let calls = 0

      stubRoutes({
        '/site': () => jsonResponse(SITE),
        [`/support/grants/${SUPPORT_GRANT_UUID}/uses`]: () => {
          calls += 1

          return calls === 1
            ? problemResponse(500, 'urn:kronoqr:problem:internal')
            : usesPage(
                [{ started_at: '2026-09-08T10:00:00.000000Z', method: 'GET', route: 'api/v1/x' }],
                { page: 1, total: 1, total_pages: 1 },
              )
        },
        '/support/grants': () => jsonResponse(supportGrantCollection([supportGrant()])),
      })

      const wrapper = await mountSupportView()

      await wrapper.find(`[data-test="uses-${SUPPORT_GRANT_UUID}"]`).trigger('click')
      await settle(8)

      const dialog = wrapper.find('[role="dialog"]')

      expect(dialog.find('[role="alert"]').exists()).toBe(true)
      expect(dialog.findAll('[data-test="grant-use-row"]')).toHaveLength(0)
    })

    it('RF-PD-11: pagina en el servidor', async () => {
      const pages: string[] = []

      stubRoutes({
        '/site': () => jsonResponse(SITE),
        [`/support/grants/${SUPPORT_GRANT_UUID}/uses`]: (url) => {
          const page = new URL(url, 'http://localhost').searchParams.get('page') ?? '1'

          pages.push(page)

          return usesPage(
            [
              {
                started_at: '2026-09-08T10:00:00.000000Z',
                method: 'GET',
                route: `api/v1/p${page}`,
              },
            ],
            { page: Number(page), total: 40, total_pages: 2 },
          )
        },
        '/support/grants': () => jsonResponse(supportGrantCollection([supportGrant()])),
      })

      const wrapper = await mountSupportView()

      await wrapper.find(`[data-test="uses-${SUPPORT_GRANT_UUID}"]`).trigger('click')
      await settle()

      const next = wrapper
        .find('[role="dialog"]')
        .findAll('button')
        .find((button) => button.text() === es.common.pagination.next)

      await next?.trigger('click')
      await settle(8)

      expect(pages).toEqual(['1', '2'])
      expect(wrapper.find('[role="dialog"]').text()).toContain('api/v1/p2')
    })
  })
})
