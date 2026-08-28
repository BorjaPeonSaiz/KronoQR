// Botones y dialogos de UNA fila de credencial (RF-QR-04, RF-QR-06, RF-QR-08).
// Fuente unica de esta logica: el tablero (`CredentialBoardView`) y la
// seccion «Tarjeta QR» de la ficha de empleado la montan igual. Estas
// pruebas ejercitan el componente solo, sin el tablero ni la ficha alrededor.
import type { DOMWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CredentialRowActions from '@/features/credentials/CredentialRowActions.vue'
import es from '@/shared/i18n/locales/es.json'
import { CREDENTIAL_UUID, boardRow, credential } from './support/fixtures'
import { jsonResponse, mountView, settle, stubFetch } from './support/harness'

type Wrapper = Awaited<ReturnType<typeof mountView>>

function buttonWith(wrapper: Wrapper, label: string): DOMWrapper<Element> {
  const found = wrapper.findAll('button').find((button) => button.text().includes(label))

  if (found === undefined) {
    throw new Error(`No hay ningun boton con el texto «${label}»`)
  }

  return found
}

beforeEach(() => {
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

describe('CredentialRowActions', () => {
  it('ofrece «Emitir» a quien no tiene ninguna credencial, y nada mas', async () => {
    const wrapper = await mountView(CredentialRowActions, {
      props: {
        row: boardRow({ status: 'no_credential', credential: null }),
        timeZone: 'Europe/Madrid',
      },
    })

    const labels = wrapper.findAll('button').map((button) => button.text())

    expect(labels).toEqual([es.credentials.actions.issue])
  })

  it('ofrece «Emitir» a quien tiene la credencial revocada: la reposicion pasa por emitir de nuevo', async () => {
    const wrapper = await mountView(CredentialRowActions, {
      props: { row: boardRow({ status: 'revoked' }), timeZone: 'Europe/Madrid' },
    })

    const labels = wrapper.findAll('button').map((button) => button.text())

    expect(labels).toEqual([es.credentials.actions.issue])
  })

  it('ofrece «Imprimir» y «Revocar» a quien esta pendiente de imprimir', async () => {
    const wrapper = await mountView(CredentialRowActions, {
      props: { row: boardRow({ status: 'pending_print' }), timeZone: 'Europe/Madrid' },
    })

    const labels = wrapper.findAll('button').map((button) => button.text())

    expect(labels).toEqual([es.credentials.actions.print, es.credentials.actions.revoke])
  })

  it('ofrece «Registrar la entrega» y «Revocar» a quien esta pendiente de entregar', async () => {
    const wrapper = await mountView(CredentialRowActions, {
      props: { row: boardRow({ status: 'pending_delivery' }), timeZone: 'Europe/Madrid' },
    })

    const labels = wrapper.findAll('button').map((button) => button.text())

    expect(labels).toEqual([es.credentials.actions.deliver, es.credentials.actions.revoke])
  })

  it('a quien ya tiene la tarjeta entregada solo le ofrece revocarla', async () => {
    const wrapper = await mountView(CredentialRowActions, {
      props: { row: boardRow({ status: 'delivered' }), timeZone: 'Europe/Madrid' },
    })

    const labels = wrapper.findAll('button').map((button) => button.text())

    expect(labels).toEqual([es.credentials.actions.revoke])
  })

  it('no deja revocar sin un motivo del catalogo', async () => {
    stubFetch(() => jsonResponse(credential()))

    const wrapper = await mountView(CredentialRowActions, {
      props: { row: boardRow({ status: 'pending_delivery' }), timeZone: 'Europe/Madrid' },
    })

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

  it('manda el motivo elegido al revocar y avisa al padre de que ha terminado', async () => {
    const spy = stubFetch((url, init) =>
      url.endsWith(`/credentials/${CREDENTIAL_UUID}/revoke`) && init?.method === 'POST'
        ? jsonResponse(credential())
        : jsonResponse(credential()),
    )

    const wrapper = await mountView(CredentialRowActions, {
      props: { row: boardRow({ status: 'pending_delivery' }), timeZone: 'Europe/Madrid' },
    })

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
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('deja escribir un motivo propio cuando elige «otro motivo»', async () => {
    stubFetch(() => jsonResponse(credential()))

    const wrapper = await mountView(CredentialRowActions, {
      props: { row: boardRow({ status: 'pending_delivery' }), timeZone: 'Europe/Madrid' },
    })

    await buttonWith(wrapper, es.credentials.actions.revoke).trigger('click')
    await settle(1)
    await wrapper.find('#revocation-reason').setValue('other')
    await settle(1)

    expect(wrapper.find('#revocation-reason-text').exists()).toBe(true)
    expect(
      buttonWith(wrapper, es.credentials.confirm.revoke.action).attributes('disabled'),
    ).toBeDefined()

    await wrapper
      .find('#revocation-reason-text')
      .setValue('Se quedo en el bolsillo del uniforme anterior')
    await settle(1)

    expect(
      buttonWith(wrapper, es.credentials.confirm.revoke.action).attributes('disabled'),
    ).toBeUndefined()
  })

  it('avisa al padre tras entregar, para que refresque la fila', async () => {
    stubFetch((url, init) =>
      url.endsWith(`/credentials/${CREDENTIAL_UUID}/deliver`) && init?.method === 'POST'
        ? jsonResponse(credential({ delivered_at: '2026-08-28T09:00:00.000000Z' }))
        : jsonResponse(credential()),
    )

    const wrapper = await mountView(CredentialRowActions, {
      props: { row: boardRow({ status: 'pending_delivery' }), timeZone: 'Europe/Madrid' },
    })

    await buttonWith(wrapper, es.credentials.actions.deliver).trigger('click')
    await settle(1)
    await buttonWith(wrapper, es.credentials.confirm.deliver.action).trigger('click')
    await settle()

    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('cuenta el fallo sin cerrar el dialogo cuando la accion no se puede completar', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const wrapper = await mountView(CredentialRowActions, {
      props: { row: boardRow({ status: 'pending_delivery' }), timeZone: 'Europe/Madrid' },
    })

    await buttonWith(wrapper, es.credentials.actions.deliver).trigger('click')
    await settle(1)
    await buttonWith(wrapper, es.credentials.confirm.deliver.action).trigger('click')
    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
    expect(wrapper.emitted('changed')).toBeUndefined()
  })
})
