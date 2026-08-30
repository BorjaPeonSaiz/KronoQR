import type { DOMWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import PeriodReportView from '@/features/reports/PeriodReportView.vue'
import type { PeriodReport, PeriodReportRow } from '@/shared/api/types'
import es from '@/shared/i18n/locales/es.json'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { jsonResponse, mountView, problemResponse, settle, stubFetch } from './support/harness'

// La pantalla de informes por periodo (RF-IN-01, RF-IN-02, RF-IN-03).
//
// Lo que se comprueba aqui es lo que puede salir mal delante de una nomina:
//
//   - Que **nada se calcula en el navegador** (regla dura 7): las horas que se
//     enseñan son literalmente las que devolvio el servidor, en `HH:MM`, y
//     nunca en decimal.
//   - Que **los criterios de inclusion se enseñan tal cual**: son parte del
//     informe, no una nota de la documentacion.
//   - Que un periodo demasiado ancho da un mensaje util y **no deja en pantalla
//     las cifras del informe anterior**, que serian de otro periodo.
//   - Que el aviso de cobertura de contrato aparece cuando falta contrato: sin
//     el, una desviacion enorme tiene aspecto de dato bueno.

type Wrapper = Awaited<ReturnType<typeof mountView>>

function row(overrides: Partial<PeriodReportRow> = {}): PeriodReportRow {
  return {
    period: { from: '2026-03-01', to: '2026-03-31' },
    subject: {
      kind: 'employee',
      employee_uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
      employee_code: '739104',
      full_name: 'Lucia Amrani',
      department_id: 3,
      label: 'Lucia Amrani',
    },
    worked_minutes: 9720,
    worked: '162:00',
    shift_count: 21,
    days_in_period: 31,
    days_with_activity: 21,
    days_without_activity: 10,
    open_shift_days: 0,
    incident_days: 1,
    contracted_minutes: 9257,
    contracted: '154:17',
    deviation_minutes: 463,
    deviation: '07:43',
    overtime_minutes: 463,
    overtime: '07:43',
    days_without_contract: 0,
    ...overrides,
  }
}

function report(overrides: Partial<PeriodReport> = {}): PeriodReport {
  return {
    from: '2026-03-01',
    to: '2026-03-31',
    granularity: 'month',
    group_by: 'employee',
    data: [row()],
    meta: {
      time_zone: 'Europe/Madrid',
      generated_at: '2026-04-01T07:12:03.114000Z',
      row_count: 1,
      criteria: [
        'Los totales salen del registro horario ya consolidado.',
        'Cada turno se atribuye entero a la jornada en la que empezó.',
      ],
      contract_coverage: {
        days_without_contract: 0,
        employees_without_contract: 0,
        complete: true,
      },
    },
    ...overrides,
  }
}

function inputWith(wrapper: Wrapper, label: string): DOMWrapper<HTMLInputElement> {
  const field = wrapper
    .findAll('label')
    .find((candidate) => candidate.text().includes(label))
    ?.attributes('for')

  const input = wrapper.find<HTMLInputElement>(`#${CSS.escape(field ?? '')}`)

  if (!input.exists()) {
    throw new Error(`No hay ningun campo etiquetado «${label}»`)
  }

  return input
}

async function fillPeriod(wrapper: Wrapper, from: string, to: string): Promise<void> {
  await inputWith(wrapper, es.reports.period.filters.from).setValue(from)
  await inputWith(wrapper, es.reports.period.filters.to).setValue(to)
}

beforeEach(() => {
  clearAnnouncement()
})

describe('informe de horas por periodo', () => {
  it('no pide nada hasta que se elige un periodo', async () => {
    // Es una consulta cara —cruza la plantilla con el calendario— y quien entra
    // todavia no ha elegido nada: generarla con un rango inventado gastaria la
    // base de datos que atiende el fichaje para dar una cifra que nadie pidio.
    const fetchSpy = stubFetch(() => jsonResponse(report()))

    await mountView(PeriodReportView)
    await settle()

    // La unica peticion admisible al abrir es la de departamentos, que alimenta
    // el desplegable del filtro.
    const urls = fetchSpy.mock.calls.map((call) => String(call[0]))

    expect(urls.some((url) => url.includes('/reports/period'))).toBe(false)
  })

  it('manda el periodo, la granularidad y la agrupacion elegidas', async () => {
    const fetchSpy = stubFetch((input) =>
      String(input).includes('/reports/period')
        ? jsonResponse(report())
        : jsonResponse({ data: [] }),
    )
    const wrapper = await mountView(PeriodReportView)

    await fillPeriod(wrapper, '2026-03-01', '2026-03-31')
    await wrapper.find('form').trigger('submit')
    await settle()

    const url = String(
      fetchSpy.mock.calls
        .map((call) => String(call[0]))
        .find((it) => it.includes('/reports/period')),
    )

    expect(url).toContain('from=2026-03-01')
    expect(url).toContain('to=2026-03-31')
    expect(url).toContain('granularity=month')
    expect(url).toContain('group_by=employee')
    // Sin departamento elegido el parametro no viaja: un `department_id=` vacio
    // seria un filtro que el servidor tendria que adivinar.
    expect(url).not.toContain('department_id')
    // Y `include_open_shifts` tampoco, porque su valor por omision lo pone el
    // servidor y ya viene explicado en `meta.criteria`.
    expect(url).not.toContain('include_open_shifts')
  })

  it('enseña las horas tal como las devolvio el servidor, en HH:MM y nunca en decimal', async () => {
    // Regla dura 7 vista desde el navegador: aqui no se suma ni se formatea
    // nada. Si esta pantalla calculara, habria dos formas de obtener el mismo
    // total y el dia que discreparan nadie sabria cual creer.
    stubFetch((input) =>
      String(input).includes('/reports/period')
        ? jsonResponse(report())
        : jsonResponse({ data: [] }),
    )
    const wrapper = await mountView(PeriodReportView)

    await fillPeriod(wrapper, '2026-03-01', '2026-03-31')
    await wrapper.find('form').trigger('submit')
    await settle()

    const text = wrapper.text()

    expect(text).toContain('162:00')
    expect(text).toContain('154:17')
    expect(text).toContain('07:43')
    expect(text).toContain('Lucia Amrani')
    // Ni una hora decimal en pantalla.
    expect(text).not.toContain('162,0')
    expect(text).not.toContain('162.0')
    expect(announcement.value).toContain('1')
  })

  it('enseña los criterios de inclusion tal cual llegan', async () => {
    // `/informe-nuevo`, paso 1: los criterios van visibles en el propio informe.
    // No se reordenan, no se resumen y no se traducen aqui — vienen ya en el
    // idioma de la peticion.
    stubFetch((input) =>
      String(input).includes('/reports/period')
        ? jsonResponse(report())
        : jsonResponse({ data: [] }),
    )
    const wrapper = await mountView(PeriodReportView)

    await fillPeriod(wrapper, '2026-03-01', '2026-03-31')
    await wrapper.find('form').trigger('submit')
    await settle()

    const criteria = wrapper.find('[data-test="report-criteria"]')

    expect(criteria.exists()).toBe(true)
    expect(criteria.findAll('li')).toHaveLength(2)
    expect(criteria.text()).toContain('Los totales salen del registro horario ya consolidado.')
  })

  it('avisa cuando faltan contratos, antes de la tabla', async () => {
    // Sin este aviso, un periodo en el que a media plantilla se le olvido
    // registrar el contrato saldria con una desviacion enorme y con aspecto de
    // dato bueno.
    stubFetch((input) =>
      String(input).includes('/reports/period')
        ? jsonResponse(
            report({
              data: [
                row({ days_without_contract: 15, contracted_minutes: 0, contracted: '00:00' }),
              ],
              meta: {
                ...report().meta,
                contract_coverage: {
                  days_without_contract: 15,
                  employees_without_contract: 1,
                  complete: false,
                },
              },
            }),
          )
        : jsonResponse({ data: [] }),
    )
    const wrapper = await mountView(PeriodReportView)

    await fillPeriod(wrapper, '2026-03-01', '2026-03-31')
    await wrapper.find('form').trigger('submit')
    await settle()

    const warning = wrapper.find('[data-test="contract-coverage-warning"]')

    expect(warning.exists()).toBe(true)
    expect(warning.text()).toContain('15')
  })

  it('retira el informe anterior cuando el periodo pedido no cabe en una respuesta', async () => {
    // RNF-P-05. Dejar la tabla anterior en pantalla junto al error haria creer
    // que esas cifras valen para el periodo que se acaba de pedir, y no valen
    // para ninguno.
    let call = 0

    stubFetch((input) => {
      if (!String(input).includes('/reports/period')) {
        return jsonResponse({ data: [] })
      }

      call += 1

      return call === 1
        ? jsonResponse(report())
        : problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: {
              to: ['El informe abarca 181 dias y el maximo que se entrega en el acto es 92.'],
            },
          })
    })

    const wrapper = await mountView(PeriodReportView)

    await fillPeriod(wrapper, '2026-03-01', '2026-03-31')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain('162:00')

    await fillPeriod(wrapper, '2026-01-01', '2026-06-30')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.text()).not.toContain('162:00')
  })

  it('no deja generar sin las dos fechas', async () => {
    // Las dos son obligatorias en el contrato: un informe sobre un rango que
    // nadie pidio es exactamente la cifra que despues alguien lleva a una
    // reunion creyendo que es otra.
    stubFetch(() => jsonResponse({ data: [] }))
    const wrapper = await mountView(PeriodReportView)

    const button = wrapper.find<HTMLButtonElement>('[data-test="generate-report"]')

    expect(button.element.disabled).toBe(true)

    await fillPeriod(wrapper, '2026-03-01', '2026-03-31')

    expect(button.element.disabled).toBe(false)
  })
})
