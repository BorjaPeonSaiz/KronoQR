import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import RehireBatchDialog from '@/features/employees/RehireBatchDialog.vue'
import es from '@/shared/i18n/locales/es.json'
import type { Employee } from '@/shared/api/types'
import { employee, rehired } from './support/fixtures'
import { jsonResponse, mountView, problemResponse, settle, stubFetch } from './support/harness'

// Readmision en lote con fecha comun (RN-27, ADR-062 §6, pedido del 18-10-2026):
// todo o nada, con el error por posicion (`employee_uuids.N`) traducido al nombre.

const TIMEZONE = 'Europe/Madrid'

function former(uuid: string, first: string, terminatedAt = '2026-10-31'): Employee {
  return employee({
    uuid,
    first_name: first,
    last_name: 'Prueba',
    status: 'terminated',
    hired_at: '2026-03-16',
    terminated_at: terminatedAt,
    offboarding: { state: 'effective', terminated_at: terminatedAt },
  })
}

const A = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b01'
const B = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b02'
const C = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b03'

const PEOPLE = [former(A, 'Ana'), former(B, 'Beto'), former(C, 'Carla')]

function stubApi(respond?: (init: RequestInit) => Response): Array<Record<string, unknown>> {
  const bodies: Array<Record<string, unknown>> = []

  stubFetch((url, init) => {
    if (url === '/api/v1/employees/rehire' && init?.method === 'POST') {
      bodies.push(JSON.parse(String(init.body)))

      return respond?.(init) ?? jsonResponse({ rehired: [rehired()] })
    }

    throw new Error(`Sin doble para ${url}`)
  })

  return bodies
}

async function mountBatch(people: readonly Employee[] = PEOPLE) {
  const wrapper = await mountView(RehireBatchDialog, { props: { people, timezone: TIMEZONE } })

  await settle(1)

  return wrapper
}

const confirm = (wrapper: Awaited<ReturnType<typeof mountBatch>>) =>
  wrapper.find('[data-test="confirm-dialog-confirm"]')

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'], now: new Date('2027-03-29T10:00:00Z') })
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('RehireBatchDialog (RN-27, la vuelta de la temporada)', () => {
  it('pide una sola fecha comun, hoy por defecto, y envia a todas en el orden de la seleccion', async () => {
    const bodies = stubApi()

    const wrapper = await mountBatch()

    expect(wrapper.find<HTMLInputElement>('input[type="date"]').element.value).toBe('2027-03-29')
    expect(confirm(wrapper).text()).toContain('3 personas')

    await confirm(wrapper).trigger('click')
    await settle()

    expect(bodies).toEqual([
      { employee_uuids: [A, B, C], hired_at: '2027-03-29', reset_pin: false },
    ])
    expect(wrapper.emitted('rehired')).toHaveLength(1)
  })

  it('con una sola persona usa el singular', async () => {
    stubApi()

    const wrapper = await mountBatch([former(A, 'Ana')])

    expect(confirm(wrapper).text()).toContain('1 persona')
    expect(confirm(wrapper).text()).not.toContain('personas')
  })

  it('con «PIN nuevo» lo pide para cada una', async () => {
    const bodies = stubApi()

    const wrapper = await mountBatch()

    await wrapper.find('[data-test="rehire-reset-pin"]').setValue(true)
    await settle(1)

    expect(wrapper.find('table').text()).toContain(es.employees.rehire.pin.new)

    await confirm(wrapper).trigger('click')
    await settle()

    expect(bodies[0]?.['reset_pin']).toBe(true)
  })

  it('enseña quien entra, antes de confirmar, sin volcar la lista en la tabla de cambios', async () => {
    stubApi()

    const wrapper = await mountBatch()
    const summary = wrapper.find('[data-test="rehire-batch-people"]')

    expect(summary.text()).toContain('Ana Prueba')
    expect(summary.text()).toContain('Carla Prueba')
    expect(wrapper.find('[role="region"]').attributes('tabindex')).toBe('0')
    expect(wrapper.find('table').text()).toContain(es.credentials.status.pending_print)
  })

  it('una fecha que no pasa del cese de alguien se detecta antes de enviar y dice a quien', async () => {
    const bodies = stubApi()

    const wrapper = await mountBatch([
      former(A, 'Ana', '2027-03-20'),
      former(B, 'Beto', '2027-03-29'),
    ])

    const errors = wrapper.find('[data-test="rehire-batch-errors"]')

    expect(errors.attributes('role')).toBe('alert')
    expect(errors.text()).toContain('Beto Prueba')
    expect(errors.text()).not.toContain('Ana Prueba')
    expect(confirm(wrapper).attributes('aria-disabled')).toBeDefined()

    await confirm(wrapper).trigger('click')
    await settle()

    expect(bodies).toHaveLength(0)

    // Con una fecha posterior a todos los ceses, se puede confirmar.
    await wrapper.find('input[type="date"]').setValue('2027-03-30')
    await settle(1)

    expect(wrapper.find('[data-test="rehire-batch-errors"]').exists()).toBe(false)
    expect(confirm(wrapper).attributes('aria-disabled')).toBeUndefined()
  })

  it('el 422 por posicion se traduce al nombre de cada persona y dice que no se ha escrito nada', async () => {
    stubApi(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: {
          'employee_uuids.1': ['Esta persona no esta de baja.'],
          'employee_uuids.2': ['Su ficha esta minimizada.'],
        },
      }),
    )

    const wrapper = await mountBatch()

    await confirm(wrapper).trigger('click')
    await settle()

    const items = wrapper.findAll('[data-test="rehire-batch-error"]').map((item) => item.text())

    expect(items).toEqual([
      'Beto Prueba: Esta persona no esta de baja.',
      'Carla Prueba: Su ficha esta minimizada.',
    ])
    expect(wrapper.find('[data-test="rehire-batch-errors"]').text()).toContain(
      es.employees.rehireBatch.errorsAdvice,
    )
    expect(wrapper.emitted('rehired')).toBeUndefined()
    // El dialogo sigue abierto con la seleccion intacta para corregir y repetir.
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
  })

  it('una posicion que no esta en la lista se rotula tal cual, no se esconde', async () => {
    stubApi(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { 'employee_uuids.7': ['No existe.'] },
      }),
    )

    const wrapper = await mountBatch()

    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.find('[data-test="rehire-batch-error"]').text()).toContain('n.º 8')
  })

  it('el 422 de la fecha se pinta junto al campo', async () => {
    stubApi(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { hired_at: ['Fecha no valida.'] },
      }),
    )

    const wrapper = await mountBatch()

    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.find('input[type="date"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Fecha no valida.')
  })

  it('un fallo de red no pierde la seleccion y cuenta que hacer', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const wrapper = await mountBatch()

    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
    expect(wrapper.find('[data-test="rehire-batch-people"]').text()).toContain('Ana Prueba')
  })

  it('con fecha futura avisa de que los fichajes anteriores se rechazan; con una baja de hoy, de esperar', async () => {
    stubApi()

    const wrapper = await mountBatch([
      former(A, 'Ana', '2027-03-28'),
      former(B, 'Beto', '2027-03-20'),
    ])

    // La baja de ayer ya cuenta como reciente (la tarea la aplico hoy).
    expect(wrapper.find('[data-test="rehire-recent-warning"]').text()).toContain('1 persona')
    expect(wrapper.find('[data-test="rehire-future-warning"]').exists()).toBe(false)

    await wrapper.find('input[type="date"]').setValue('2027-04-15')
    await settle(1)

    expect(wrapper.find('[data-test="rehire-future-warning"]').text()).toContain(
      'los fichajes anteriores a esa fecha se rechazan',
    )
  })
})
