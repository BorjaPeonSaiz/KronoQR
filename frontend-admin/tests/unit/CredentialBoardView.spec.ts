import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CredentialBoardView from '@/features/credentials/CredentialBoardView.vue'
import es from '@/shared/i18n/locales/es.json'
import type { CredentialStatusRow } from '@/shared/api/types'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { CREDENTIAL_UUID, SITE, board, boardRow, credential } from './support/fixtures'
import { buttonWith, jsonResponse, mountView, settle, stubFetch } from './support/harness'

// El `URL` global se sustituye en cada prueba (createObjectURL): este es el de verdad.
const NativeURL = URL

type Wrapper = Awaited<ReturnType<typeof mountView>>

/**
 * Selecciona la opcion «solo quien todavia no tiene la tarjeta en la mano»
 * del select de estado. Localiza la opcion por su texto en vez de por su
 * valor interno (`__pending_only__`): las pruebas describen lo que ve quien
 * usa el panel, no un detalle de implementacion del componente.
 */
async function selectPendingOnly(wrapper: Wrapper): Promise<void> {
  const select = wrapper.find('#credentials-status-filter')
  const option = select
    .findAll('option')
    .find((candidate) => candidate.text() === es.credentials.filters.pendingOnly)

  if (option === undefined) {
    throw new Error('No se encuentra la opcion "pendiente de tarjeta" en el select de estado')
  }

  await select.setValue((option.element as HTMLOptionElement).value)
}

function pdfResponse(count: number): Response {
  return new Response('%PDF-1.7', {
    status: 200,
    headers: {
      'Content-Type': 'application/pdf',
      'Content-Disposition': 'attachment; filename="credenciales.pdf"',
      'X-Kronoqr-Printed-Count': String(count),
    },
  })
}

function routes(
  rows: CredentialStatusRow[],
  extra?: (url: string, init: RequestInit | undefined) => Response | null,
) {
  return (url: string, init: RequestInit | undefined) => {
    if (url.startsWith('/api/v1/site')) {
      return jsonResponse(SITE)
    }

    if (url.startsWith('/api/v1/departments')) {
      return jsonResponse({
        data: [
          { id: 3, name: 'Cocina' },
          { id: 4, name: 'Recepción' },
        ],
      })
    }

    const handled = extra?.(url, init) ?? null

    return handled ?? jsonResponse(board(rows))
  }
}

async function mountBoard(
  rows: CredentialStatusRow[],
  extra?: Parameters<typeof routes>[1],
): Promise<Wrapper> {
  stubFetch(routes(rows, extra))

  const wrapper = await mountView(CredentialBoardView)

  await settle()

  return wrapper
}

beforeEach(() => {
  window.sessionStorage.clear()
  clearAnnouncement()
  vi.stubGlobal('URL', {
    ...URL,
    createObjectURL: vi.fn(() => 'blob:kronoqr'),
    revokeObjectURL: vi.fn(),
  })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('CredentialBoardView', () => {
  it('dice cuanta gente falta del total, no del filtro', async () => {
    const wrapper = await mountBoard([
      boardRow(),
      boardRow({ employee_uuid: 'b', status: 'delivered' }),
    ])

    expect(wrapper.text()).toContain('1 de 60')
  })

  it('distingue los cinco estados derivados', async () => {
    const wrapper = await mountBoard([
      boardRow({ employee_uuid: 'a', status: 'no_credential', credential: null }),
      boardRow({ employee_uuid: 'b', status: 'pending_print' }),
      boardRow({ employee_uuid: 'c', status: 'pending_delivery' }),
      boardRow({ employee_uuid: 'd', status: 'delivered' }),
      boardRow({ employee_uuid: 'e', status: 'revoked' }),
    ])

    expect(wrapper.text()).toContain(es.credentials.status.no_credential)
    expect(wrapper.text()).toContain(es.credentials.status.pending_print)
    expect(wrapper.text()).toContain(es.credentials.status.pending_delivery)
    expect(wrapper.text()).toContain(es.credentials.status.delivered)
    expect(wrapper.text()).toContain(es.credentials.status.revoked)
  })

  it('enseña las horas en la zona del centro, con la zona a la vista', async () => {
    const wrapper = await mountBoard([
      boardRow({
        status: 'delivered',
        credential: credential({
          printed_at: '2026-08-20T07:11:02Z',
          delivered_at: '2026-08-21T06:40:15Z',
        }),
      }),
    ])

    expect(wrapper.text()).toContain('9:11')
    expect(wrapper.text()).toMatch(/GMT\+2|CEST/)
  })

  it('no ofrece reimprimir una tarjeta ya impresa', async () => {
    const wrapper = await mountBoard([
      boardRow({
        status: 'delivered',
        credential: credential({ printed_at: '2026-08-20T07:11:02Z' }),
      }),
    ])

    const labels = wrapper.findAll('tbody button').map((button) => button.text())

    expect(labels).toEqual([es.credentials.actions.revoke])
  })

  it('avisa de que imprimir acuña el QR y no tiene vuelta atras', async () => {
    const wrapper = await mountBoard([boardRow({ status: 'pending_print' })])

    await buttonWith(wrapper, es.credentials.actions.print).trigger('click')
    await settle(1)

    expect(wrapper.find('[role="dialog"]').text()).toContain(es.credentials.confirm.print.notice)
  })

  it('descarga el PDF de una tarjeta y lo anuncia', async () => {
    const wrapper = await mountBoard([boardRow({ status: 'pending_print' })], (url, init) =>
      url.endsWith(`/credentials/${CREDENTIAL_UUID}/print`) && init?.method === 'POST'
        ? pdfResponse(1)
        : null,
    )

    await buttonWith(wrapper, es.credentials.actions.print).trigger('click')
    await settle(1)
    await buttonWith(wrapper, es.credentials.confirm.print.action).trigger('click')
    await settle()

    expect(announcement.value).toContain('Lucia Martinez Prieto')
  })

  it('antes de registrar una entrega dice que queda en auditoria y no se deshace', async () => {
    const wrapper = await mountBoard([boardRow({ status: 'pending_delivery' })])

    await buttonWith(wrapper, es.credentials.actions.deliver).trigger('click')
    await settle(1)

    const dialog = wrapper.find('[role="dialog"]')

    expect(dialog.text()).toContain(es.credentials.confirm.deliver.notice)
    expect(dialog.text()).toContain(es.credentials.status.pending_delivery)
    expect(dialog.text()).toContain(es.credentials.status.delivered)
  })

  it('el anuncio de la accion es el ultimo en la region viva, no el recuento del refresco posterior', async () => {
    const wrapper = await mountBoard([boardRow({ status: 'pending_delivery' })])

    await buttonWith(wrapper, es.credentials.actions.deliver).trigger('click')
    await settle(1)
    await buttonWith(wrapper, es.credentials.confirm.deliver.action).trigger('click')
    await settle()

    // `refreshBoard()` recarga el panel tras la entrega. El recuento de filas
    // visibles no cambia (sigue habiendo una), asi que el ultimo anuncio debe
    // seguir siendo la confirmacion de la entrega, no un "Resultados: 1" que
    // nadie ha pedido.
    expect(announcement.value).toBe('Entrega de la tarjeta de Lucia Martinez Prieto registrada.')
  })

  it('no deja revocar sin un motivo del catalogo', async () => {
    const wrapper = await mountBoard([boardRow({ status: 'pending_delivery' })])

    await buttonWith(wrapper, es.credentials.actions.revoke).trigger('click')
    await settle(1)

    expect(
      buttonWith(wrapper, es.credentials.confirm.revoke.action).attributes('disabled'),
    ).toBeDefined()

    await wrapper.find('#revocation-reason').setValue('lost')
    await settle(1)

    expect(
      buttonWith(wrapper, es.credentials.confirm.revoke.action).attributes('disabled'),
    ).toBeUndefined()
  })

  it('manda el motivo elegido al revocar', async () => {
    const spy = stubFetch(
      routes([boardRow({ status: 'pending_delivery' })], (url, init) =>
        url.endsWith('/revoke') && init?.method === 'POST' ? jsonResponse(credential()) : null,
      ),
    )

    const wrapper = await mountView(CredentialBoardView)

    await settle()
    await buttonWith(wrapper, es.credentials.actions.revoke).trigger('click')
    await settle(1)
    await wrapper.find('#revocation-reason').setValue('lost')
    await buttonWith(wrapper, es.credentials.confirm.revoke.action).trigger('click')
    await settle()

    const body = spy.mock.calls
      .map((call) => call[1] as RequestInit | undefined)
      .map((init) => String(init?.body ?? ''))
      .find((raw) => raw.includes('reason'))

    expect(body).toContain(es.credentials.revoke.reasons.lost)
  })

  it('trata el 204 del lote como «no habia nada pendiente», no como un fallo', async () => {
    const wrapper = await mountBoard([boardRow({ status: 'delivered' })], (url, init) =>
      url.endsWith('/credentials/print-batch') && init?.method === 'POST'
        ? new Response(null, { status: 204 })
        : null,
    )

    await buttonWith(wrapper, es.credentials.batch.action).trigger('click')
    await settle(1)
    await buttonWith(wrapper, es.credentials.batch.confirmAction).trigger('click')
    await settle()

    expect(announcement.value).toContain(es.credentials.announce.batchEmpty)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('anuncia cuantas tarjetas lleva la hoja del lote', async () => {
    const wrapper = await mountBoard([boardRow({ status: 'pending_print' })], (url, init) =>
      url.endsWith('/credentials/print-batch') && init?.method === 'POST' ? pdfResponse(40) : null,
    )

    await buttonWith(wrapper, es.credentials.batch.action).trigger('click')
    await settle(1)
    await buttonWith(wrapper, es.credentials.batch.confirmAction).trigger('click')
    await settle()

    expect(announcement.value).toContain('40')
  })

  it('ofrece emitir a quien no tiene ninguna credencial', async () => {
    const wrapper = await mountBoard([boardRow({ status: 'no_credential', credential: null })])

    const labels = wrapper.findAll('tbody button').map((button) => button.text())

    expect(labels).toEqual([es.credentials.actions.issue])
  })

  it('explica el vacio cuando ya todo el mundo tiene su tarjeta', async () => {
    const wrapper = await mountBoard([])

    await selectPendingOnly(wrapper)
    await settle()

    expect(wrapper.text()).toContain(es.credentials.empty.allDelivered)
  })

  /** Las URLs de `/credentials/status` que ha pedido el panel, en orden. */
  function boardUrls(spy: ReturnType<typeof stubFetch>): InstanceType<typeof NativeURL>[] {
    return spy.mock.calls
      .map((call) => String(call[0]))
      .filter((url) => url.includes('/api/v1/credentials/status'))
      .map((url) => new NativeURL(url, 'http://localhost'))
  }

  it('el estado, el departamento y la pagina viajan al servidor; el panel no filtra por su cuenta (RF-QR-08, V5-PA-2)', async () => {
    const spy = stubFetch(routes([boardRow({ employee_uuid: 'a', status: 'delivered' })]))
    const wrapper = await mountView(CredentialBoardView)

    await settle()

    // Se pide la primera pagina de 30, sin filtros.
    const first = boardUrls(spy)[0]

    expect(first?.searchParams.get('page')).toBe('1')
    expect(first?.searchParams.get('per_page')).toBe('30')
    expect(first?.searchParams.has('status')).toBe(false)

    await wrapper.find('#credentials-status-filter').setValue('delivered')
    await settle()
    await wrapper.find('#credentials-department-filter').setValue('3')
    await settle()

    const last = boardUrls(spy).at(-1)

    expect(last?.searchParams.get('status')).toBe('delivered')
    expect(last?.searchParams.get('department_id')).toBe('3')
    expect(last?.searchParams.get('page')).toBe('1')
  })

  it('«sin departamento» pide without_department, no un departamento inventado', async () => {
    const spy = stubFetch(routes([boardRow()]))
    const wrapper = await mountView(CredentialBoardView)

    await settle()
    await wrapper.find('#credentials-department-filter').setValue('__no_department__')
    await settle()

    const last = boardUrls(spy).at(-1)

    expect(last?.searchParams.get('without_department')).toBe('true')
    expect(last?.searchParams.has('department_id')).toBe(false)
  })

  it('los departamentos salen de GET /departments, no de las filas de la pagina', async () => {
    const wrapper = await mountBoard([boardRow({ department_name: 'Lavanderia' })])
    const options = wrapper
      .find('#credentials-department-filter')
      .findAll('option')
      .map((option) => option.text())

    expect(options).toContain('Cocina')
    expect(options).toContain('Recepción')
    expect(options).not.toContain('Lavanderia')
  })

  it('la busqueda espera a que se deje de teclear y se hace en el servidor', async () => {
    const spy = stubFetch(routes([boardRow()]))
    const wrapper = await mountView(CredentialBoardView)

    await settle()
    const before = boardUrls(spy).length

    await wrapper.find('#credentials-search-filter').setValue('garcia')
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(boardUrls(spy).length).toBe(before)

    await new Promise((resolve) => setTimeout(resolve, 400))
    await settle()

    expect(boardUrls(spy).at(-1)?.searchParams.get('q')).toBe('garcia')
  })

  it('pagina en el servidor: enseña la pagina que llega y pide la siguiente al avanzar (RF-QR-08)', async () => {
    const total = 35
    const spy = stubFetch((url, init) => {
      if (url.startsWith('/api/v1/site')) {
        return jsonResponse(SITE)
      }

      if (url.startsWith('/api/v1/departments')) {
        return jsonResponse({ data: [] })
      }

      const page = Number(new NativeURL(url, 'http://localhost').searchParams.get('page') ?? '1')
      const rows = Array.from({ length: page === 1 ? 30 : 5 }, (_, index) =>
        boardRow({
          employee_uuid: `row-${page}-${index}`,
          employee_code: `CODE${index}`,
          full_name: `Persona ${page}-${index}`,
        }),
      )

      void init

      return jsonResponse({
        ...board(rows),
        meta: { page, per_page: 30, total, total_pages: 2 },
      })
    })
    const wrapper = await mountView(CredentialBoardView)

    await settle()

    expect(wrapper.findAll('tbody tr')).toHaveLength(30)
    expect(wrapper.text()).toContain(`1–30 de ${total}`)

    await wrapper.findAll('nav button')[1]?.trigger('click')
    await settle()

    expect(boardUrls(spy).at(-1)?.searchParams.get('page')).toBe('2')
    expect(wrapper.findAll('tbody tr')).toHaveLength(5)
  })

  it('un filtro nuevo vuelve a la primera pagina', async () => {
    const spy = stubFetch(
      routes([boardRow()], (url) =>
        url.includes('/credentials/status')
          ? jsonResponse({
              ...board([boardRow()]),
              meta: { page: 2, per_page: 30, total: 35, total_pages: 2 },
            })
          : null,
      ),
    )
    const wrapper = await mountView(CredentialBoardView)

    await settle()
    await wrapper.findAll('nav button')[0]?.trigger('click')
    await wrapper.find('#credentials-status-filter').setValue('revoked')
    await settle()

    expect(boardUrls(spy).at(-1)?.searchParams.get('page')).toBe('1')
  })

  it('«pendiente de tarjeta» es una opcion mas del select de estado, sin control deshabilitado que nadie con teclado o lector de pantalla pueda alcanzar (RF-QR-08)', async () => {
    const spy = stubFetch(routes([boardRow({ status: 'pending_print' })]))

    const wrapper = await mountView(CredentialBoardView)

    await settle()
    await selectPendingOnly(wrapper)
    await settle()

    // Va al servidor: acota tambien el resumen y el lote de impresion, cosa
    // que un filtro solo-en-cliente no podria hacer.
    const urls = spy.mock.calls.map((call) => String(call[0]))

    expect(urls.some((url) => url.includes('pending=true'))).toBe(true)

    // Ningun control queda deshabilitado ni con `title` como unico aviso: no
    // hay exclusion que anunciar, es una opcion mas.
    expect(wrapper.find('#credentials-status-filter').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
  })

  it('el vacio filtrado es distinto del vacio real (RF-QR-08)', async () => {
    const wrapper = await mountBoard([boardRow({ full_name: 'Lucia Martinez' })], (url) =>
      url.includes('q=nadie')
        ? jsonResponse({
            ...board([]),
            meta: { page: 1, per_page: 30, total: 0, total_pages: 1 },
          })
        : null,
    )

    await wrapper.find('#credentials-search-filter').setValue('nadie encaja con esto')
    await new Promise((resolve) => setTimeout(resolve, 450))
    await settle()

    expect(wrapper.text()).toContain(es.credentials.empty.filtered)
    expect(wrapper.text()).not.toContain(es.credentials.empty.description)
  })

  // --- Rotacion de la clave de firma (RF-QR-07, tarea 2.12) ------------------

  it('no dice nada de rotaciones cuando no hay ninguna abierta', async () => {
    // Es lo normal: el panel de credenciales no tiene por que hablar de claves.
    const wrapper = await mountBoard([boardRow()])

    expect(wrapper.text()).not.toContain(es.credentials.rotation.heading)
  })

  it('pinta el avance de la reimpresion cuando hay una rotacion abierta', async () => {
    stubFetch((url) =>
      url.startsWith('/api/v1/site')
        ? jsonResponse(SITE)
        : jsonResponse(
            board([boardRow({ status: 'delivered' })], {
              retiring_key_id: 'a2',
              pending_reprint: 12,
            }),
          ),
    )

    const wrapper = await mountView(CredentialBoardView)
    await settle()

    expect(wrapper.text()).toContain(es.credentials.rotation.heading)
    // 48 de 60, el 80 %: el avance se mide contra la plantilla entera.
    expect(wrapper.text()).toContain('48 de 60')
    expect(wrapper.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('80')
  })

  it('pide al servidor solo a quien le falta reimprimir', async () => {
    // El filtro va al SERVIDOR con el parametro `key_id`: es lo que permite
    // confirmar que no queda nadie antes de retirar la clave (doc 02 §5.3).
    const urls: string[] = []

    stubFetch((url) => {
      urls.push(url)

      return url.startsWith('/api/v1/site')
        ? jsonResponse(SITE)
        : jsonResponse(
            board([boardRow({ status: 'delivered' })], {
              retiring_key_id: 'a2',
              pending_reprint: 12,
            }),
          )
    })

    const wrapper = await mountView(CredentialBoardView)
    await settle()

    await buttonWith(wrapper, es.credentials.rotation.showPending.replace('{count}', '12')).trigger(
      'click',
    )
    await settle()

    expect(urls.some((url) => url.includes('key_id=a2'))).toBe(true)
    expect(buttonWith(wrapper, es.credentials.rotation.showAll).attributes('aria-pressed')).toBe(
      'true',
    )
  })

  it('avisa de las tarjetas firmadas con una clave que el servidor ya no reconoce', async () => {
    // Es el hallazgo de la revision de seguridad de la 2.12: esas filas se ven
    // «Entregada» y correctas, y esas personas NO PUEDEN FICHAR. Sin este aviso
    // el panel decia que todo estaba bien.
    stubFetch((url) =>
      url.startsWith('/api/v1/site')
        ? jsonResponse(SITE)
        : jsonResponse(board([boardRow({ status: 'delivered' })], { active_unknown_key: 12 })),
    )

    const wrapper = await mountView(CredentialBoardView)
    await settle()

    const alerta = wrapper.find('[role="alert"]')

    expect(alerta.text()).toContain(es.credentials.unknownKey.heading)
    expect(alerta.text()).toContain('12')
  })

  it('no avisa de claves desconocidas cuando no hay ninguna', async () => {
    const wrapper = await mountBoard([boardRow({ status: 'delivered' })])

    expect(wrapper.text()).not.toContain(es.credentials.unknownKey.heading)
  })

  it('cuenta que ha pasado si el panel no se puede cargar', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const wrapper = await mountView(CredentialBoardView)

    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
  })
})
