import type { DOMWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CredentialBoardView from '@/features/credentials/CredentialBoardView.vue'
import es from '@/shared/i18n/locales/es.json'
import type { CredentialStatusRow } from '@/shared/api/types'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { CREDENTIAL_UUID, SITE, board, boardRow, credential } from './support/fixtures'
import { jsonResponse, mountView, settle, stubFetch } from './support/harness'

type Wrapper = Awaited<ReturnType<typeof mountView>>

function buttonWith(wrapper: Wrapper, label: string): DOMWrapper<Element> {
  const found = wrapper.findAll('button').find((button) => button.text().includes(label))

  if (found === undefined) {
    throw new Error(`No hay ningun boton con el texto «${label}»`)
  }

  return found
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
    if (url.startsWith('/api/v1/sites')) {
      return jsonResponse({ data: [SITE] })
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

    await wrapper.find('input[type="checkbox"]').setValue(true)
    await settle()

    expect(wrapper.text()).toContain(es.credentials.empty.allDelivered)
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
