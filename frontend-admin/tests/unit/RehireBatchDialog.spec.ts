import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import RehireBatchDialog from '@/features/employees/RehireBatchDialog.vue'
import es from '@/shared/i18n/locales/es.json'
import type { Employee } from '@/shared/api/types'
import { employee, employmentPeriodRecord, rehired } from './support/fixtures'
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

function stubApi(
  respond?: (init: RequestInit) => Response,
  details: Record<string, string | null> = {},
): Array<Record<string, unknown>> {
  const bodies: Array<Record<string, unknown>> = []

  stubFetch((url, init) => {
    // El detalle de cada ficha (la lista no trae los periodos): solo las que la prueba declara.
    const uuid = /^\/api\/v1\/employees\/([0-9a-f-]{36})$/.exec(url)?.[1]

    if (uuid !== undefined && uuid in details) {
      const person = PEOPLE.find((candidate) => candidate.uuid === uuid) ?? former(uuid, 'X')

      return jsonResponse({
        ...person,
        employment_periods: [
          employmentPeriodRecord({
            terminated_at: person.terminated_at,
            offboarded_at: details[uuid] ?? null,
          }),
        ],
      })
    }

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

describe('RehireBatchDialog: el aviso de sincronizacion sale de offboarded_at (31-10-2026)', () => {
  it('cuenta solo a quien se dio de baja hoy en la zona del centro, no por su cese', async () => {
    // Ana: cese de ayer pero efectiva hace dias; Beto: cese de hace un mes, registrada esta mañana.
    stubApi(undefined, { [A]: '2027-03-26T08:00:00Z', [B]: '2027-03-29T07:00:00Z' })

    const wrapper = await mountBatch([
      former(A, 'Ana', '2027-03-28'),
      former(B, 'Beto', '2027-02-20'),
    ])

    await settle()

    expect(wrapper.find('[data-test="rehire-recent-warning"]').text()).toContain('1 persona')
  })

  it('si el detalle de alguna no llega, esa se aproxima por el cese', async () => {
    stubApi(undefined, { [A]: '2027-03-26T08:00:00Z' })

    const wrapper = await mountBatch([
      former(A, 'Ana', '2027-03-20'),
      former(B, 'Beto', '2027-03-28'),
    ])

    await settle()

    expect(wrapper.find('[data-test="rehire-recent-warning"]').text()).toContain('1 persona')
  })
})

describe('RehireBatchDialog: un lote grande (RN-27, ADR-062 §6)', () => {
  const NOMBRES = [
    'Ana',
    'Beto',
    'Carla',
    'Dario',
    'Elena',
    'Fabio',
    'Gema',
    'Hugo',
    'Irene',
    'Jorge',
    'Karen',
    'Luis',
    'Marta',
    'Nico',
    'Olga',
  ]

  const uuidDe = (posicion: number) =>
    `0199f0c2-1f4a-7c3e-9b21-${String(posicion).padStart(12, '0')}`

  const QUINCE = NOMBRES.map((nombre, posicion) => former(uuidDe(posicion), nombre))

  it('los rechazos en posiciones de dos cifras nombran a su persona y salen en el orden de la lista, no en el del texto', async () => {
    stubApi(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: {
          'employee_uuids.13': ['Su ficha esta minimizada.'],
          'employee_uuids.2': ['Esta persona no esta de baja.'],
          'employee_uuids.10': ['La fecha de alta tiene que ser posterior a su cese.'],
        },
      }),
    )

    const wrapper = await mountBatch(QUINCE)

    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.findAll('[data-test="rehire-batch-error"]').map((item) => item.text())).toEqual([
      'Carla Prueba: Esta persona no esta de baja.',
      'Karen Prueba: La fecha de alta tiene que ser posterior a su cese.',
      'Nico Prueba: Su ficha esta minimizada.',
    ])
    expect(wrapper.emitted('rehired')).toBeUndefined()
  })

  it('un campo que no es una posicion (employee_uuids sin indice) no se atribuye a nadie', async () => {
    stubApi(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: {
          employee_uuids: ['Como maximo 500 personas por peticion.'],
          'employee_uuids.12': ['Esta persona no esta de baja.'],
        },
      }),
    )

    const wrapper = await mountBatch(QUINCE)

    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.findAll('[data-test="rehire-batch-error"]').map((item) => item.text())).toEqual([
      'Marta Prueba: Esta persona no esta de baja.',
    ])
  })

  it('el mismo motivo en local y en el servidor para una persona se dice una sola vez', async () => {
    const motivo = 'su fecha de cese es el 31 oct 2026 y la de alta tiene que ser posterior'

    stubApi(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { 'employee_uuids.11': [motivo] },
      }),
    )

    const wrapper = await mountBatch(QUINCE)

    await confirm(wrapper).trigger('click')
    await settle()
    // Ahora la fecha tampoco pasa del cese de nadie: el aviso local dice lo mismo que el servidor.
    await wrapper.find('input[type="date"]').setValue('2026-10-31')
    await settle(1)

    const errores = wrapper.findAll('[data-test="rehire-batch-error"]')

    // Las quince quedan en la lista; Luis, en la posicion 11, con el motivo una sola vez.
    expect(errores).toHaveLength(15)
    expect(errores[11]?.text()).toBe(`Luis Prueba: ${motivo}`)
  })
})

describe('RehireBatchDialog: el detalle de cada ficha para el aviso de sincronizacion (31-10-2026)', () => {
  const uuidDe = (posicion: number) =>
    `0199f0c2-1f4a-7c3e-9b21-${String(posicion).padStart(12, '0')}`

  /** 60 personas de baja: mas que las 50 fichas que el dialogo llega a pedir. */
  const SESENTA = Array.from({ length: 60 }, (_, posicion) =>
    former(uuidDe(posicion), `Persona${posicion}`),
  )

  function stubDetails(): { pedidas: string[]; maximoEnVuelo: () => number } {
    const pedidas: string[] = []
    let enVuelo = 0
    let maximo = 0

    stubFetch(async (url) => {
      pedidas.push(url)
      enVuelo += 1
      maximo = Math.max(maximo, enVuelo)
      await new Promise((resolve) => setTimeout(resolve, 0))
      enVuelo -= 1

      return jsonResponse({ ...former(url.slice(-36), 'X'), employment_periods: [] })
    })

    return { pedidas, maximoEnVuelo: () => maximo }
  }

  it('pide la ficha de las 50 primeras, de cinco en cinco, y no la de las demas', async () => {
    const red = stubDetails()

    await mountBatch(SESENTA)

    await vi.waitFor(() => expect(red.pedidas).toHaveLength(50))
    await settle()

    expect(red.pedidas).toHaveLength(50)
    expect(red.pedidas[0]).toBe(`/api/v1/employees/${uuidDe(0)}`)
    expect(red.pedidas[49]).toBe(`/api/v1/employees/${uuidDe(49)}`)
    expect(red.maximoEnVuelo()).toBe(5)
  })

  it('a quien ya trae sus periodos no se le pide la ficha, y su offboarded_at es el que cuenta', async () => {
    const red = stubDetails()
    // Cese de hace meses, pero la baja se registro esta mañana en Madrid.
    const conPeriodos = {
      ...former(A, 'Ana', '2026-10-31'),
      employment_periods: [
        employmentPeriodRecord({
          terminated_at: '2026-10-31',
          offboarded_at: '2027-03-29T07:00:00Z',
        }),
      ],
    }

    const wrapper = await mountBatch([conPeriodos])

    await settle()

    expect(red.pedidas).toEqual([])
    expect(wrapper.find('[data-test="rehire-recent-warning"]').text()).toContain('1 persona')
  })
})

describe('RehireBatchDialog: sin la zona del centro (RN-27, ADR-040)', () => {
  it('no propone fecha de alta, no deja confirmar y no adivina avisos', async () => {
    const bodies = stubApi()

    const wrapper = await mountView(RehireBatchDialog, {
      props: { people: [former(A, 'Ana', '2027-03-28')], timezone: undefined },
    })

    await settle()

    expect(wrapper.find<HTMLInputElement>('input[type="date"]').element.value).toBe('')
    expect(confirm(wrapper).attributes('aria-disabled')).toBeDefined()
    expect(wrapper.find('[data-test="rehire-recent-warning"]').exists()).toBe(false)

    await confirm(wrapper).trigger('click')
    await settle()

    expect(bodies).toEqual([])
  })
})
