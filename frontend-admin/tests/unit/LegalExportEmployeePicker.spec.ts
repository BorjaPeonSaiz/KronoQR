import { afterEach, describe, expect, it, vi } from 'vitest'
import LegalExportEmployeePicker from '@/features/reports/LegalExportEmployeePicker.vue'
import es from '@/shared/i18n/locales/es.json'
import {
  buttonWith,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

// Buscador de la persona de la exportacion para la Inspeccion (RF-IN-05,
// V21-3-PA-1). Lo que importa: que no busque sin que nadie lo pida (cada busqueda
// deja un asiento en la auditoria), que el DNI no se quede, que el uuid no se vea
// y que cada fallo diga que hacer.

const picker = es.reports.legalExport.picker

const AMRANI = {
  uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
  first_name: 'Lucia',
  last_name: 'Amrani',
  department: { id: 3, name: 'Cocina' },
  status: 'terminated',
  hired_at: '2022-03-01',
  terminated_at: '2026-02-15',
  employment_periods: [
    {
      uuid: '0199f0c2-5555-7000-8000-000000000001',
      hired_at: '2022-03-01',
      terminated_at: '2026-02-15',
      state: 'terminated',
    },
  ],
}

const OTHER = {
  uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91',
  first_name: 'Omar',
  last_name: 'Amrani',
  department: null,
  status: 'active',
  hired_at: '2024-01-10',
  terminated_at: null,
  employment_periods: [
    {
      uuid: '0199f0c2-5555-7000-8000-000000000002',
      hired_at: '2024-01-10',
      terminated_at: null,
      state: 'active',
    },
  ],
}

async function mountPicker(): Promise<Awaited<ReturnType<typeof mountView>>> {
  return mountView(LegalExportEmployeePicker)
}

async function typeTerm(wrapper: Awaited<ReturnType<typeof mountView>>, value: string) {
  await wrapper.find<HTMLInputElement>('input[type="text"]').setValue(value)
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('buscador de la persona de la exportacion', () => {
  it('no busca mientras se escribe: solo con Intro o con el boton', async () => {
    const fetchSpy = stubFetch(() => jsonResponse({ data: [], truncated: false }))
    const wrapper = await mountPicker()
    const field = wrapper.find<HTMLInputElement>('input[type="text"]')

    await field.setValue('amr')
    await field.setValue('amra')
    await settle()

    expect(fetchSpy).not.toHaveBeenCalled()

    await field.trigger('keydown', { key: 'Enter' })
    await settle()

    expect(fetchSpy).toHaveBeenCalledTimes(1)
    expect(JSON.parse(String((fetchSpy.mock.calls[0]?.[1] as RequestInit).body))).toEqual({
      q: 'amra',
    })
  })

  it('el campo no autocompleta y Intro no envia el formulario que lo contiene', async () => {
    stubFetch(() => jsonResponse({ data: [], truncated: false }))
    const wrapper = await mountPicker()
    const field = wrapper.find<HTMLInputElement>('input[type="text"]')

    expect(field.attributes('autocomplete')).toBe('off')

    await typeTerm(wrapper, 'amrani')
    const event = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true })
    field.element.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
  })

  it('lista a las personas con departamento y fecha de baja, sin enseñar el uuid', async () => {
    stubFetch(() => jsonResponse({ data: [AMRANI, OTHER], truncated: false }))
    const wrapper = await mountPicker()

    await typeTerm(wrapper, 'amrani')
    await buttonWith(wrapper, picker.search).trigger('click')
    await settle()

    const options = wrapper.findAll('fieldset input[type="radio"][name]')

    expect(wrapper.text()).toContain('Amrani, Lucia · Cocina · De baja desde')
    expect(wrapper.text()).toContain('15')
    expect(wrapper.text()).toContain('Amrani, Omar · Sin departamento')
    expect(wrapper.text()).not.toContain(AMRANI.uuid)
    expect(options.length).toBeGreaterThanOrEqual(2)
    expect(wrapper.find('fieldset legend').exists()).toBe(true)
  })

  it('avisa cuando hay mas coincidencias de las que caben', async () => {
    stubFetch(() => jsonResponse({ data: [AMRANI], truncated: true }))
    const wrapper = await mountPicker()

    await typeTerm(wrapper, 'amrani')
    await buttonWith(wrapper, picker.search).trigger('click')
    await settle()

    expect(wrapper.text()).toContain(picker.truncated)
  })

  it('borra el DNI del campo al buscar y lo manda en el cuerpo, no en la URL', async () => {
    const fetchSpy = stubFetch(() => jsonResponse({ data: [], truncated: false }))
    const wrapper = await mountPicker()

    await wrapper.find('input[type="radio"][value="nationalId"]').setValue(true)
    await typeTerm(wrapper, ' 00000000T ')
    await wrapper.find<HTMLInputElement>('input[type="text"]').trigger('keydown', { key: 'Enter' })
    await settle()

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit]

    expect(url).toBe('/api/v1/reports/legal-export/employees/search')
    expect(init.method).toBe('POST')
    expect(JSON.parse(String(init.body))).toEqual({ national_id: '00000000T' })
    expect(wrapper.find<HTMLInputElement>('input[type="text"]').element.value).toBe('')
    expect(wrapper.html()).not.toContain('00000000T')
  })

  it('sin resultados por DNI explica que quien no lo tiene dado de alta solo sale por nombre', async () => {
    stubFetch(() => jsonResponse({ data: [], truncated: false }))
    const wrapper = await mountPicker()

    await wrapper.find('input[type="radio"][value="nationalId"]').setValue(true)
    await typeTerm(wrapper, '00000000T')
    await buttonWith(wrapper, picker.search).trigger('click')
    await settle()

    expect(wrapper.text()).toContain(picker.noneByNationalId)
  })

  it('cambiar de criterio con una busqueda en curso descarta su respuesta y libera el boton', async () => {
    let release: (response: Response) => void = () => undefined

    stubFetch(
      () =>
        new Promise<Response>((resolve) => {
          release = resolve
        }),
    )
    const wrapper = await mountPicker()

    await wrapper.find('input[type="radio"][value="nationalId"]').setValue(true)
    await typeTerm(wrapper, '00000000T')
    await buttonWith(wrapper, picker.search).trigger('click')
    await settle()

    await wrapper.find('input[type="radio"][value="text"]').setValue(true)
    await typeTerm(wrapper, 'amrani')

    expect(buttonWith(wrapper, picker.search).attributes('aria-disabled')).toBeUndefined()

    release(jsonResponse({ data: [AMRANI], truncated: false }))
    await settle()

    expect(wrapper.text()).not.toContain('Amrani, Lucia')
    expect(wrapper.find<HTMLInputElement>('input[type="text"]').element.value).toBe('amrani')
  })

  it('al elegir enseña la pastilla «Se exportará», guarda el uuid y permite cambiar', async () => {
    stubFetch(() => jsonResponse({ data: [AMRANI], truncated: false }))
    const wrapper = await mountPicker()

    await typeTerm(wrapper, 'amrani')
    await buttonWith(wrapper, picker.search).trigger('click')
    await settle()
    await wrapper.find(`input[type="radio"][value="${AMRANI.uuid}"]`).setValue(true)
    await buttonWith(wrapper, picker.choose).trigger('click')
    await settle()

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([AMRANI.uuid])
    expect(wrapper.text()).toContain('Se exportará: Amrani, Lucia')
    expect(wrapper.text()).not.toContain(AMRANI.uuid)

    await buttonWith(wrapper, picker.change).trigger('click')
    await settle()

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([''])
    expect(wrapper.find('input[type="text"]').exists()).toBe(true)
  })

  it('el aviso de que la busqueda queda registrada es fijo', async () => {
    const wrapper = await mountPicker()

    expect(wrapper.text()).toContain(picker.audited)
  })

  it('un 422 se pinta junto al campo', async () => {
    stubFetch(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { q: ['Escribe al menos 3 caracteres.'] },
      }),
    )
    const wrapper = await mountPicker()

    await typeTerm(wrapper, 'am')
    await buttonWith(wrapper, picker.search).trigger('click')
    await settle()

    const field = wrapper.find<HTMLInputElement>('input[type="text"]')

    expect(field.attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Escribe al menos 3 caracteres.')
  })

  it('un 429 dice cuando reintentar', async () => {
    stubFetch(
      () =>
        new Response(
          JSON.stringify({
            type: 'urn:kronoqr:problem:too-many-requests',
            title: 'x',
            status: 429,
          }),
          {
            status: 429,
            headers: { 'Content-Type': 'application/problem+json', 'Retry-After': '30' },
          },
        ),
    )
    const wrapper = await mountPicker()

    await typeTerm(wrapper, 'amrani')
    await buttonWith(wrapper, picker.search).trigger('click')
    await settle()

    expect(wrapper.text()).toContain(es.errors.rateLimited.title)
    expect(wrapper.text()).toContain('30')
  })

  it('un 403 explica que hacer sin enseñar el codigo', async () => {
    stubFetch(() => problemResponse(403, 'urn:kronoqr:problem:forbidden'))
    const wrapper = await mountPicker()

    await typeTerm(wrapper, 'amrani')
    await buttonWith(wrapper, picker.search).trigger('click')
    await settle()

    expect(wrapper.text()).toContain(es.errors.forbidden.title)
    expect(wrapper.text()).not.toContain('403')
  })
})

// Todos los periodos de alta de la persona, no solo el ultimo (RN-27, ADR-062,
// RF-IN-05): el rango de la exportacion puede caer en cualquiera de ellos.
describe('buscador de la persona de la exportacion: periodos de alta (RN-27)', () => {
  const READMITIDA = {
    uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b92',
    first_name: 'Lucia',
    last_name: 'Ferrer',
    department: { id: 3, name: 'Cocina' },
    status: 'active',
    hired_at: '2027-04-01',
    terminated_at: null,
    employment_periods: [
      {
        uuid: '0199f0c2-5555-7000-8000-000000000011',
        hired_at: '2025-04-01',
        terminated_at: '2025-10-31',
        state: 'terminated',
      },
      {
        uuid: '0199f0c2-5555-7000-8000-000000000012',
        hired_at: '2026-04-01',
        terminated_at: '2026-10-31',
        state: 'terminated',
      },
      {
        uuid: '0199f0c2-5555-7000-8000-000000000013',
        hired_at: '2027-04-01',
        terminated_at: null,
        state: 'active',
      },
    ],
  }

  async function search(person: unknown) {
    stubFetch(() => jsonResponse({ data: [person], truncated: false }))
    const wrapper = await mountPicker()

    await typeTerm(wrapper, 'ferrer')
    await buttonWith(wrapper, picker.search).trigger('click')
    await settle()

    return wrapper
  }

  it('enseña cada periodo de alta de una persona readmitida, no solo el ultimo', async () => {
    const wrapper = await search(READMITIDA)
    const periods = wrapper.findAll('[data-test="match-period"]').map((item) => item.text())

    expect(periods).toHaveLength(3)
    expect(periods[0]).toContain('1 abr 2025')
    expect(periods[0]).toContain('31 oct 2025')
    expect(periods[1]).toContain('1 abr 2026')
    expect(periods[1]).toContain('31 oct 2026')
    expect(periods[2]).toBe('Desde el 1 abr 2027, sin baja')
  })

  it('el grupo de periodos tiene nombre para quien usa lector de pantalla', async () => {
    const wrapper = await search(READMITIDA)

    expect(wrapper.find('[data-test="match-periods"] .sr-only').text()).toBe(
      `${picker.periodsLegend}:`,
    )
  })

  it('una baja programada se rotula como tal, con su fecha', async () => {
    const wrapper = await search({
      ...READMITIDA,
      employment_periods: [
        {
          uuid: '0199f0c2-5555-7000-8000-000000000014',
          hired_at: '2026-04-01',
          terminated_at: '2026-11-30',
          state: 'scheduled',
        },
      ],
    })

    expect(wrapper.find('[data-test="match-period"]').text()).toBe(
      'Desde el 1 abr 2026, baja programada para el 30 nov 2026',
    )
  })

  it('el uuid de los periodos tampoco se ve', async () => {
    const wrapper = await search(READMITIDA)

    expect(wrapper.text()).not.toContain('0199f0c2-5555')
  })
})
