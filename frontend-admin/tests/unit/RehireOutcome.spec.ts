import { QueryClient } from '@tanstack/vue-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { useSessionStore } from '@/features/auth/session.store'
import RehireOutcome from '@/features/employees/RehireOutcome.vue'
import es from '@/shared/i18n/locales/es.json'
import type { RehiredEmployee } from '@/shared/api/types'
import {
  EMPLOYEE_UUID,
  REHIRED_CREDENTIAL_UUID,
  employee,
  managementUser,
  rehired,
} from './support/fixtures'
import {
  buttonWith,
  createTestPinia,
  jsonResponse,
  mountView,
  settle,
  stubFetch,
} from './support/harness'

// Lo que sigue a una readmision (RN-27, ADR-062 §6, ADR-034, RF-ID-09, RF-QR-04):
// el PIN nuevo se ve UNA vez y primero; la tarjeta nueva se ofrece imprimir en el
// acto, y es un acto que acuña el QR y no se hace solo.

const SECOND_UUID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91'

function pin(forUuid: string, value: string) {
  return {
    employee_uuid: forUuid,
    pin: value,
    issued_at: '2026-11-02T09:00:00.000000Z',
    pin_status: 'issued' as const,
  }
}

function second(overrides: Partial<RehiredEmployee> = {}): RehiredEmployee {
  return rehired({
    employee: employee({ uuid: SECOND_UUID, first_name: 'Marta', last_name: 'Vidal' }),
    credential: { uuid: '019a3b5e-7c00-7d1e-8f2a-3b4c5d6e7f82', status: 'pending_print' },
    ...overrides,
  })
}

function pdf(printedCount?: number): Response {
  return new Response('%PDF-1.7', {
    status: 200,
    headers: {
      'Content-Type': 'application/pdf',
      'Content-Disposition': 'attachment; filename="t.pdf"',
      ...(printedCount === undefined ? {} : { 'X-Kronoqr-Printed-Count': String(printedCount) }),
    },
  })
}

async function mountOutcome(
  results: RehiredEmployee[],
  abilities?: string[],
  attachTo?: HTMLElement,
) {
  const pinia = createTestPinia()

  if (abilities !== undefined) {
    const session = useSessionStore()

    session.user = managementUser({ abilities })
    session.token = 'un-token'
    session.status = 'authenticated'
  }

  const wrapper = await mountView(RehireOutcome, { props: { results }, pinia, attachTo })

  await settle(1)

  return wrapper
}

beforeEach(() => {
  clearAnnouncement()
  window.sessionStorage.clear()
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

describe('RehireOutcome: una persona', () => {
  it('sin PIN nuevo ofrece la tarjeta, con el aviso de que imprimir acuña el QR', async () => {
    const wrapper = await mountOutcome([rehired()], ['credentials:*'])

    expect(wrapper.find('[role="dialog"]').text()).toContain(es.employees.rehire.card.heading)
    expect(wrapper.find('[data-test="rehire-card-notice"]').text()).toContain(
      es.credentials.confirm.print.notice,
    )
    expect(wrapper.find('[data-test="pin-value"]').exists()).toBe(false)
  })

  it('imprime la tarjeta de esa persona, la descarga y lo dice', async () => {
    const urls: string[] = []

    stubFetch((url, init) => {
      urls.push(`${init?.method ?? 'GET'} ${url}`)

      return pdf()
    })

    const wrapper = await mountOutcome([rehired()], ['credentials:*'])

    await wrapper.find('[data-test="rehire-card-print"]').trigger('click')
    await settle()

    expect(urls).toEqual([`POST /api/v1/credentials/${REHIRED_CREDENTIAL_UUID}/print`])
    expect(wrapper.find('[data-test="rehire-card-printed"]').text()).toBe(
      es.employees.rehire.card.printed,
    )
    expect(announcement.value).toBe(es.employees.rehire.card.announcePrinted)
    // Impresa, ya no se vuelve a ofrecer imprimir: no existe la reimpresion (ADR-034).
    expect(wrapper.find('[data-test="rehire-card-print"]').exists()).toBe(false)
  })

  it('«Imprimir más tarde» cierra sin imprimir nada', async () => {
    const spy = stubFetch(() => pdf())
    const wrapper = await mountOutcome([rehired()], ['credentials:*'])

    await wrapper.find('[data-test="rehire-card-close"]').trigger('click')

    expect(wrapper.emitted('done')).toHaveLength(1)
    expect(spy).not.toHaveBeenCalled()
  })

  it('quien no gestiona credenciales no ve el boton y sabe a quien pedirlo', async () => {
    const wrapper = await mountOutcome([rehired()], ['employees:*'])

    expect(wrapper.find('[data-test="rehire-card-print"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="rehire-card-no-ability"]').text()).toBe(
      es.employees.rehire.card.noAbility,
    )
    // No puede imprimir: ni «Imprimir más tarde» (no es suyo) ni «puedes imprimirlas desde Credenciales».
    expect(wrapper.find('[data-test="rehire-card-close"]').text()).toBe(es.common.close)
    expect(wrapper.text()).not.toContain(es.employees.rehire.card.later)
  })

  it('al imprimir, el foco pasa a «Cerrar» en vez de perderse con el boton que desaparece', async () => {
    stubFetch(() => pdf())

    const wrapper = await mountOutcome([rehired()], ['credentials:*'], document.body)

    await wrapper.find('[data-test="rehire-card-print"]').trigger('click')
    await settle()

    expect(document.activeElement).toBe(wrapper.find('[data-test="rehire-card-close"]').element)
    expect(wrapper.find('[data-test="rehire-card-close"]').text()).toBe(es.common.close)

    wrapper.unmount()
  })

  it('un fallo al imprimir cuenta que ha pasado y deja volver a intentarlo', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const wrapper = await mountOutcome([rehired()], ['credentials:*'])

    await wrapper.find('[data-test="rehire-card-print"]').trigger('click')
    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
    expect(wrapper.find('[data-test="rehire-card-print"]').exists()).toBe(true)
  })

  it('con PIN nuevo lo enseña PRIMERO, una vez, y despues la tarjeta', async () => {
    const wrapper = await mountOutcome(
      [rehired({ pin: pin(EMPLOYEE_UUID, '483920') })],
      ['credentials:*'],
    )

    expect(wrapper.find('[data-test="pin-value"]').text()).toBe('483920')
    expect(wrapper.text()).toContain(es.pin.reveal.onlyOnce)
    // Dice que la tarjeta viene despues: quien lo ve no cree que ya esta todo hecho.
    expect(wrapper.find('[data-test="pin-next"]').text()).toBe(es.employees.rehire.pins.next)
    expect(wrapper.find('[data-test="rehire-card-print"]').exists()).toBe(false)

    await buttonWith(wrapper, es.pin.reveal.acknowledge).trigger('click')
    await settle(1)

    expect(wrapper.find('[data-test="pin-value"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('483920')
    expect(wrapper.find('[data-test="rehire-card-print"]').exists()).toBe(true)
  })

  it('registrar la entrega del PIN avisa para releer la ficha y sigue con la tarjeta', async () => {
    stubFetch((url, init) =>
      url.endsWith('/pin/deliver') && init?.method === 'POST'
        ? jsonResponse({
            employee_uuid: EMPLOYEE_UUID,
            delivered_at: '2026-11-02T09:01:00.000000Z',
            delivered_by: 'x',
            pin_status: 'delivered',
          })
        : pdf(),
    )

    const wrapper = await mountOutcome(
      [rehired({ pin: pin(EMPLOYEE_UUID, '483920') })],
      ['credentials:*'],
    )

    await wrapper.find('input[type="checkbox"]').setValue(true)
    await buttonWith(wrapper, es.pin.reveal.registerDelivery).trigger('click')
    await settle()

    expect(wrapper.emitted('pinDelivered')).toHaveLength(1)
    expect(wrapper.find('[data-test="rehire-card-print"]').exists()).toBe(true)
  })
})

describe('RehireOutcome: varias personas', () => {
  it('con PIN nuevo enseña todos juntos, sin poder cerrar por descuido, y despues las tarjetas', async () => {
    const wrapper = await mountOutcome(
      [rehired({ pin: pin(EMPLOYEE_UUID, '483920') }), second({ pin: pin(SECOND_UUID, '117205') })],
      ['credentials:*'],
    )

    const values = wrapper.findAll('[data-test="batch-pin-value"]').map((cell) => cell.text())

    expect(wrapper.find('[data-test="batch-pins-next"]').text()).toBe(es.employees.rehire.pins.next)

    expect(values).toEqual(['483920', '117205'])
    expect(wrapper.text()).toContain(es.pin.reveal.onlyOnce)

    // Sin confirmar que se han anotado no se puede seguir, ni con Escape.
    expect(
      wrapper.find('[data-test="batch-pins-continue"]').attributes('aria-disabled'),
    ).toBeDefined()
    await wrapper.find('[role="dialog"]').trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('[data-test="batch-pin-value"]').exists()).toBe(true)

    await wrapper.find('[data-test="batch-pins-noted"]').setValue(true)
    await wrapper.find('[data-test="batch-pins-continue"]').trigger('click')
    await settle(1)

    expect(wrapper.text()).not.toContain('483920')
    expect(wrapper.text()).not.toContain('117205')
    expect(wrapper.find('[data-test="rehire-card-list"]').text()).toContain('Marta Vidal')
  })

  it('sin PIN nuevo va directo a las tarjetas: la hoja A4 con todas las pendientes, y lo avisa', async () => {
    const urls: string[] = []

    stubFetch((url, init) => {
      urls.push(`${init?.method ?? 'GET'} ${url}`)

      return pdf()
    })

    const wrapper = await mountOutcome([rehired(), second()], ['credentials:*'])

    expect(wrapper.find('[data-test="rehire-card-batch-scope"]').text()).toBe(
      es.employees.rehire.card.batchScope,
    )

    await wrapper.find('[data-test="rehire-card-print"]').trigger('click')
    await settle()

    expect(urls).toEqual(['POST /api/v1/credentials/print-batch'])
    expect(wrapper.find('[data-test="rehire-card-printed"]').exists()).toBe(true)
  })

  it('al imprimir el lote anuncia cuantas tarjetas lleva la hoja (plural), recuerda que el PDF es al portador y relee las credenciales', async () => {
    const invalidate = vi.spyOn(QueryClient.prototype, 'invalidateQueries')

    stubFetch(() => pdf(2))

    const wrapper = await mountOutcome([rehired(), second()], ['credentials:*'])

    expect(wrapper.find('[data-test="rehire-card-bearer"]').text()).toBe(
      es.credentials.batch.bearerNotice,
    )

    await wrapper.find('[data-test="rehire-card-print"]').trigger('click')
    await settle()

    expect(announcement.value).toBe('Hoja generada: 2 tarjetas.')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['credential-board'] })
  })

  it('con una sola tarjeta usa el singular del anuncio y tambien relee las credenciales', async () => {
    const invalidate = vi.spyOn(QueryClient.prototype, 'invalidateQueries')

    stubFetch(() => pdf())

    const wrapper = await mountOutcome([rehired()], ['credentials:*'])

    await wrapper.find('[data-test="rehire-card-print"]').trigger('click')
    await settle()

    expect(announcement.value).toBe(es.employees.rehire.card.announcePrinted)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['credential-board'] })
  })

  it('un 204 del lote no es un error: ya no habia nada pendiente', async () => {
    stubFetch(() => new Response(null, { status: 204 }))

    const wrapper = await mountOutcome([rehired(), second()], ['credentials:*'])

    await wrapper.find('[data-test="rehire-card-print"]').trigger('click')
    await settle()

    expect(wrapper.find('[data-test="rehire-card-nothing"]').text()).toBe(
      es.employees.rehire.card.nothing,
    )
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })
})
