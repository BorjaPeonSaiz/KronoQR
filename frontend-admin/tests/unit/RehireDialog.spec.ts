import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import RehireDialog from '@/features/employees/RehireDialog.vue'
import es from '@/shared/i18n/locales/es.json'
import type { Employee } from '@/shared/api/types'
import { EMPLOYEE_UUID, employee, employmentContract, rehired } from './support/fixtures'
import { jsonResponse, mountView, problemResponse, settle, stubFetch } from './support/harness'

// «Dar de alta de nuevo» a una persona (RN-27, ADR-062 §6, RF-GP-01, RF-GP-03,
// pedido del propietario del 18-10-2026): un solo dato obligatorio, el resto
// relleno de la ficha anterior, y los dos avisos del 31-10-2026.

const TIMEZONE = 'Europe/Madrid'

/** Una persona de baja desde el 31 de agosto, con departamento e idioma. */
function former(overrides: Partial<Employee> = {}): Employee {
  return employee({
    status: 'terminated',
    hired_at: '2026-03-16',
    terminated_at: '2026-08-31',
    offboarding: {
      state: 'effective',
      terminated_at: '2026-08-31',
      offboarded_at: '2026-08-31T22:00:04Z',
    },
    ...overrides,
  })
}

interface Calls {
  rehire: Array<Record<string, unknown>>
  urls: string[]
  /** Toda peticion que salio, GET incluidos. */
  fetched: string[]
}

function stubApi(
  extra?: (url: string, init: RequestInit | undefined) => Response | null,
  contracts = [employmentContract({ valid_to: '2026-08-31', is_current: false })],
): Calls {
  const calls: Calls = { rehire: [], urls: [], fetched: [] }

  stubFetch((url, init) => {
    calls.fetched.push(url)

    const handled = extra?.(url, init) ?? null

    if (handled !== null) {
      return handled
    }

    if (url.startsWith('/api/v1/departments')) {
      return jsonResponse({
        data: [
          { id: 3, name: 'Recepcion' },
          { id: 4, name: 'Cocina' },
        ],
      })
    }

    if (url.endsWith('/contracts')) {
      return jsonResponse({ data: contracts })
    }

    if (url.endsWith('/rehire') && init?.method === 'POST') {
      calls.urls.push(url)
      calls.rehire.push(JSON.parse(String(init.body)))

      return jsonResponse(rehired())
    }

    throw new Error(`Sin doble para ${url}`)
  })

  return calls
}

async function mountDialog(person: Employee = former()) {
  const wrapper = await mountView(RehireDialog, { props: { employee: person, timezone: TIMEZONE } })

  await settle()

  return wrapper
}

const confirm = (wrapper: Awaited<ReturnType<typeof mountDialog>>) =>
  wrapper.find('[data-test="confirm-dialog-confirm"]')

beforeEach(() => {
  clearAnnouncement()
  vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-11-02T10:00:00Z') })
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('RehireDialog: un solo dato obligatorio (RN-27, pedido del 18-10-2026)', () => {
  it('propone hoy en la zona del centro y no vuelve a pedir DNI, nombre ni codigo', async () => {
    stubApi()

    const wrapper = await mountDialog()
    const date = wrapper.find<HTMLInputElement>('input[type="date"]')
    const labels = wrapper.findAll('label').map((label) => label.text())

    expect(date.element.value).toBe('2026-11-02')
    // El dia siguiente al cese es la primera fecha que admite el servidor.
    expect(date.attributes('min')).toBe('2026-09-01')
    expect(date.attributes('required')).toBeDefined()
    expect(labels.some((text) => text.includes(es.employees.fields.nationalId))).toBe(false)
    expect(labels.some((text) => text.includes(es.employees.fields.firstName))).toBe(false)
    expect(labels.some((text) => text.includes(es.employees.fields.lastName))).toBe(false)
    expect(wrapper.text()).not.toContain(es.employees.fields.nationalId)
  })

  it('solo con la fecha, envia solo la fecha: lo demas lo toma el servidor de la ficha', async () => {
    const calls = stubApi()

    const wrapper = await mountDialog()

    await confirm(wrapper).trigger('click')
    await settle()

    expect(calls.rehire).toEqual([{ hired_at: '2026-11-02', reset_pin: false }])
    expect(wrapper.emitted('rehired')).toHaveLength(1)
  })

  it('antes de confirmar enseña desde que valor y hacia cual, tarjeta y PIN incluidos', async () => {
    stubApi()

    const wrapper = await mountDialog()
    const preview = wrapper.find('table').text()

    expect(preview).toContain(es.employees.status.terminated)
    expect(preview).toContain(es.employees.status.active)
    expect(preview).toContain(es.credentials.status.revoked)
    expect(preview).toContain(es.credentials.status.pending_print)
    expect(preview).toContain(es.employees.rehire.pin.kept)
    expect(wrapper.text()).toContain(es.employees.rehire.consequenceCard)
  })

  it('departamento, contrato y PIN nuevo se pueden cambiar, y solo viaja lo que cambia', async () => {
    const calls = stubApi()

    const wrapper = await mountDialog()
    const selects = wrapper.findAll('select')

    await selects[0]?.setValue(4)
    await wrapper.find('input[type="radio"][value="none"]').setValue(true)
    await wrapper.find('[data-test="rehire-reset-pin"]').setValue(true)
    await settle(1)

    expect(wrapper.find('table').text()).toContain('Cocina')
    expect(wrapper.find('table').text()).toContain(es.employees.rehire.pin.new)

    await confirm(wrapper).trigger('click')
    await settle()

    expect(calls.rehire).toEqual([
      { hired_at: '2026-11-02', reset_pin: true, department_id: 4, contract: null },
    ])
  })

  it('otros terminos de contrato exigen las horas y el tipo de jornada, y viajan como RehireContract', async () => {
    const calls = stubApi()

    const wrapper = await mountDialog()

    await wrapper.find('input[type="radio"][value="custom"]').setValue(true)
    await settle(1)

    expect(confirm(wrapper).attributes('aria-disabled')).toBeDefined()

    const [weekly, annual] = wrapper.findAll('input[inputmode="decimal"]')
    const schedule = wrapper.findAll('select').at(-1)

    await weekly?.setValue('37,5')
    await annual?.setValue('1700')
    await schedule?.setValue('partida')
    await settle(1)

    expect(confirm(wrapper).attributes('aria-disabled')).toBeUndefined()

    await confirm(wrapper).trigger('click')
    await settle()

    expect(calls.rehire[0]?.['contract']).toEqual({
      weekly_hours: 37.5,
      annual_hours: 1700,
      schedule_type: 'partida',
    })
  })

  it('por omision cuenta que se registra un contrato con los terminos del ultimo', async () => {
    stubApi()

    const wrapper = await mountDialog()

    expect(wrapper.find('[data-test="rehire-contract-keep"]').text()).toContain('40 h')
    expect(wrapper.find('[data-test="rehire-contract-keep"]').text()).toContain('Por turnos')
  })

  it('una fecha que no pasa del cese no se envia y dice cual es el cese', async () => {
    const calls = stubApi()

    const wrapper = await mountDialog()

    await wrapper.find('input[type="date"]').setValue('2026-08-31')
    await settle(1)

    expect(confirm(wrapper).attributes('aria-disabled')).toBeDefined()
    expect(wrapper.find('input[type="date"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('31 ago 2026')

    await confirm(wrapper).trigger('click')
    await settle()

    expect(calls.rehire).toHaveLength(0)
  })
})

describe('RehireDialog: los dos avisos del propietario (31-10-2026)', () => {
  it('con fecha de alta futura avisa de que los fichajes anteriores se rechazan', async () => {
    stubApi()

    const wrapper = await mountDialog()

    expect(wrapper.find('[data-test="rehire-future-warning"]').exists()).toBe(false)

    await wrapper.find('input[type="date"]').setValue('2026-12-01')
    await settle(1)

    const warning = wrapper.find('[data-test="rehire-future-warning"]')

    expect(warning.text()).toContain('los fichajes anteriores a esa fecha se rechazan')
    // El aviso vive dentro de una region viva que ya estaba en el dialogo (se anuncia de forma fiable).
    expect(warning.element.closest('[role="status"]')).not.toBeNull()
    // Es un aviso, no un bloqueo.
    expect(confirm(wrapper).attributes('aria-disabled')).toBeUndefined()
  })

  it('si la baja fue hoy pide esperar a que los quioscos esten sincronizados', async () => {
    stubApi()

    const wrapper = await mountDialog(
      former({
        terminated_at: '2026-11-02',
        offboarding: {
          state: 'effective',
          terminated_at: '2026-11-02',
          offboarded_at: '2026-11-02T22:00:04Z',
        },
      }),
    )

    // La fecha de alta tiene que ser posterior al cese de hoy: mañana.
    await wrapper.find('input[type="date"]').setValue('2026-11-03')
    await settle(1)

    const warning = wrapper.find('[data-test="rehire-recent-warning"]')

    expect(warning.text()).toContain('espera a que los quioscos estén sincronizados')
  })

  it('si coinciden los dos avisos van en UNA caja, dentro de una region viva que ya existia', async () => {
    stubApi()

    const wrapper = await mountDialog(
      former({
        terminated_at: '2026-11-02',
        offboarding: {
          state: 'effective',
          terminated_at: '2026-11-02',
          offboarded_at: '2026-11-02T22:00:04Z',
        },
      }),
    )

    // Sin avisos la region existe (para que el lector de pantalla anuncie lo que llegue) y no ocupa sitio.
    const region = wrapper.find('[data-test="date-warnings"]')

    expect(region.attributes('role')).toBe('status')
    expect(region.element.children).toHaveLength(0)

    await wrapper.find('input[type="date"]').setValue('2026-12-01')
    await settle(1)

    expect(region.element.children).toHaveLength(1)
    expect(region.findAll('p').map((paragraph) => paragraph.attributes('data-test'))).toEqual([
      'rehire-future-warning',
      'rehire-recent-warning',
    ])
  })

  it('con la fecha rechazada manda el error: los avisos de fecha futura y de sincronizacion se callan', async () => {
    stubApi()

    const wrapper = await mountDialog(
      former({
        terminated_at: '2026-11-02',
        offboarding: {
          state: 'effective',
          terminated_at: '2026-11-02',
          offboarded_at: '2026-11-02T22:00:04Z',
        },
      }),
    )

    // Hoy es el dia del cese: el alta no es posterior. Es un error, y la baja de hoy no es el asunto.
    await wrapper.find('input[type="date"]').setValue('2026-11-02')
    await settle(1)

    expect(wrapper.find('input[type="date"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.find('[data-test="rehire-recent-warning"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="date-warnings"]').element.children).toHaveLength(0)
  })

  /** Una baja de cese `terminatedAt` que se hizo efectiva en `offboardedAt` (UTC), como la trae el listado. */
  const offboardedAt = (terminatedAt: string, instant: string) =>
    former({
      terminated_at: terminatedAt,
      offboarding: { state: 'effective', terminated_at: terminatedAt, offboarded_at: instant },
    })

  it('avisa si la baja se hizo efectiva hoy, aunque su cese sea antiguo, sin pedir mas fichas', async () => {
    const calls = stubApi()

    // Cese del 31 de agosto, registrado esta mañana: el cese solo no lo diria.
    const wrapper = await mountDialog(offboardedAt('2026-08-31', '2026-11-02T08:00:00Z'))

    expect(wrapper.find('[data-test="rehire-recent-warning"]').text()).toContain(
      'espera a que los quioscos estén sincronizados',
    )
    // offboarded_at viaja en la propia persona: ninguna peticion de detalle.
    expect(calls.fetched).not.toContain(`/api/v1/employees/${EMPLOYEE_UUID}`)
  })

  it('manda offboarded_at: un cese de ayer efectivo ayer no avisa', async () => {
    stubApi()

    const wrapper = await mountDialog(offboardedAt('2026-11-01', '2026-11-01T22:00:04Z'))

    expect(wrapper.find('[data-test="rehire-recent-warning"]').exists()).toBe(false)
  })

  it('una baja antigua no lleva el aviso de sincronizacion', async () => {
    stubApi()

    const wrapper = await mountDialog()

    expect(wrapper.find('[data-test="rehire-recent-warning"]').exists()).toBe(false)
  })
})

describe('RehireDialog: errores, cada uno con su «que hacer» (RN-27)', () => {
  it('el 422 de la fecha se pinta junto al campo y devuelve el foco a el', async () => {
    const message = 'La fecha de alta (2026-09-01) tiene que ser posterior a la fecha de cese.'

    stubApi((url, init) =>
      url.endsWith('/rehire') && init?.method === 'POST'
        ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: { hired_at: [message] },
          })
        : null,
    )

    const wrapper = await mountDialog()

    await confirm(wrapper).trigger('click')
    await settle()

    const input = wrapper.find('input[type="date"]')
    const describedBy = (input.attributes('aria-describedby') ?? '').split(' ')

    expect(input.attributes('aria-invalid')).toBe('true')
    expect(describedBy.some((id) => wrapper.find(`[id="${id}"]`).text() === message)).toBe(true)
    // El mismo mensaje no se repite en un aviso aparte.
    expect(wrapper.find('[data-test="employment-problem"]').exists()).toBe(false)
    expect(wrapper.emitted('rehired')).toBeUndefined()
  })

  it('tras un 422 de la fecha, corregirla vuelve a habilitar «Confirmar» y se puede reenviar', async () => {
    let attempts = 0

    stubApi((url, init) => {
      if (url.endsWith('/rehire') && init?.method === 'POST') {
        attempts += 1

        return attempts === 1
          ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
              errors: { hired_at: ['La fecha de alta no es valida.'] },
            })
          : jsonResponse(rehired())
      }

      return null
    })

    const wrapper = await mountDialog()

    await confirm(wrapper).trigger('click')
    await settle()

    // El error del servidor para esa fecha deja el boton parado hasta que se toca el campo...
    expect(confirm(wrapper).attributes('aria-disabled')).toBeDefined()

    await wrapper.find('input[type="date"]').setValue('2026-11-03')
    await settle(1)

    // ...y al corregirla se vuelve a poder confirmar.
    expect(wrapper.find('input[type="date"]').attributes('aria-invalid')).toBeUndefined()
    expect(confirm(wrapper).attributes('aria-disabled')).toBeUndefined()

    await confirm(wrapper).trigger('click')
    await settle()

    expect(attempts).toBe(2)
    expect(wrapper.emitted('rehired')).toHaveLength(1)
  })

  it('un 422 de un campo que no esta junto a un input (departamento, contrato) sale en el aviso con su nombre', async () => {
    stubApi((url, init) =>
      url.endsWith('/rehire') && init?.method === 'POST'
        ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: {
              hired_at: ['Fecha no valida.'],
              department_id: ['El departamento ya no existe.'],
              'contract.weekly_hours': ['Las horas no son validas.'],
            },
          })
        : null,
    )

    const wrapper = await mountDialog()

    await confirm(wrapper).trigger('click')
    await settle()

    const notice = wrapper.find('[role="alert"]')

    expect(notice.text()).toContain('Departamento: El departamento ya no existe.')
    expect(notice.text()).toContain('Horas por semana: Las horas no son validas.')
    // La fecha ya esta junto a su campo: no se repite en el aviso.
    expect(notice.text()).not.toContain('Fecha no valida.')
    expect(wrapper.text()).toContain('Fecha no valida.')
  })

  it('si la persona ya no esta de baja lo explica, dice que hacer y avisa para releer la ficha', async () => {
    stubApi((url, init) =>
      url.endsWith('/rehire') && init?.method === 'POST'
        ? problemResponse(409, 'urn:kronoqr:problem:employee-not-terminated')
        : null,
    )

    const wrapper = await mountDialog()

    await confirm(wrapper).trigger('click')
    await settle()

    const notice = wrapper.find('[data-test="employment-problem"]')

    expect(notice.attributes('role')).toBe('alert')
    expect(notice.text()).toContain(es.employees.problems.employeeNotTerminated.title)
    expect(notice.text()).toContain(es.employees.problems.employeeNotTerminated.advice)
    expect(wrapper.emitted('stale')?.[0]).toEqual(['employeeNotTerminated'])
  })

  it('una ficha minimizada se explica (alta como persona nueva) sin pedir releer', async () => {
    stubApi((url, init) =>
      url.endsWith('/rehire') && init?.method === 'POST'
        ? problemResponse(409, 'urn:kronoqr:problem:employee-record-minimised')
        : null,
    )

    const wrapper = await mountDialog()

    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.find('[data-test="employment-problem"]').text()).toContain(
      es.employees.problems.recordMinimised.advice,
    )
    expect(wrapper.emitted('stale')).toBeUndefined()
  })

  it('un fallo de red cuenta que ha pasado y que hacer, y no cierra el dialogo', async () => {
    stubApi((url, init) => {
      if (url.endsWith('/rehire') && init?.method === 'POST') {
        throw new TypeError('Failed to fetch')
      }

      return null
    })

    const wrapper = await mountDialog()

    await confirm(wrapper).trigger('click')
    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(announcement.value).toBe('')
  })

  it('readmite en la ficha que le pasan, no en otra', async () => {
    const calls = stubApi()
    const wrapper = await mountDialog(former({ uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b99' }))

    await confirm(wrapper).trigger('click')
    await settle()

    expect(calls.urls).toEqual(['/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b99/rehire'])
  })
})

describe('RehireDialog: «hoy» es el del centro a medianoche, tambien la noche del cambio de hora (ADR-040, RN-27)', () => {
  it.each([
    // 00:00 del 25 en Madrid (CEST) y todavia el 24 en UTC.
    {
      ahora: '2026-10-24T22:00:00Z',
      propuesta: '2026-10-25',
      efectiva: '2026-10-24T21:59:59Z',
      avisa: false,
    },
    {
      ahora: '2026-10-24T22:00:00Z',
      propuesta: '2026-10-25',
      efectiva: '2026-10-24T22:00:00Z',
      avisa: true,
    },
    // 23:59:59 del 25 en Madrid, ya CET: con el desfase del verano seria el 26.
    {
      ahora: '2026-10-25T22:59:59Z',
      propuesta: '2026-10-25',
      efectiva: '2026-10-25T22:30:00Z',
      avisa: true,
    },
    // 00:00 del 26 en Madrid: la baja de hace media hora ya fue «ayer».
    {
      ahora: '2026-10-25T23:00:00Z',
      propuesta: '2026-10-26',
      efectiva: '2026-10-25T22:30:00Z',
      avisa: false,
    },
  ])(
    'a las $ahora propone $propuesta y una baja efectiva a las $efectiva avisa: $avisa',
    async ({ ahora, propuesta, efectiva, avisa }) => {
      vi.setSystemTime(new Date(ahora))
      stubApi()

      const wrapper = await mountDialog(
        former({
          offboarding: { state: 'effective', terminated_at: '2026-08-31', offboarded_at: efectiva },
        }),
      )

      expect(wrapper.find<HTMLInputElement>('input[type="date"]').element.value).toBe(propuesta)
      expect(wrapper.find('[data-test="rehire-recent-warning"]').exists()).toBe(avisa)
    },
  )
})
