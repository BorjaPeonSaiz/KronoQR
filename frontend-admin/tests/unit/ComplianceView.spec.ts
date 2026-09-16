// Vista de cumplimiento (RF-PA-06, tarea 3.4).
//
// Lo que se comprueba aqui:
//
//   - Que carga AL ABRIR, sin que nadie elija nada (al reves que el informe
//     por periodo): el servidor aplica los 28 dias por omision y los filtros
//     de fecha se rellenan con lo que `meta` devuelve.
//   - Que cada tarjeta enseña el umbral en palabras y con el nombre del
//     perfil (regla dura 14): un aviso cuyo criterio no se ve es un aviso que
//     nadie defiende ante un empleado.
//   - Que la regla suspendida (RN-12) se marca «No se evalua» con su motivo,
//     sin callarlo.
//   - Que los filtros de departamento y regla disparan una nueva consulta al
//     servidor, no un filtrado en el navegador (RF-ID-03).
//   - Que el periodo sin hallazgos da el estado vacio con los criterios.
//   - Que un `422` (rango superior al maximo) se lee con el nombre del campo
//     tal como lo ve esta pantalla, no con el de la columna.
import { beforeEach, describe, expect, it } from 'vitest'
import ComplianceView from '@/features/compliance/ComplianceView.vue'
import type { ComplianceSummary } from '@/shared/api/types'
import es from '@/shared/i18n/locales/es.json'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { jsonResponse, mountView, problemResponse, settle, stubFetch } from './support/harness'

function summary(overrides: Partial<ComplianceSummary> = {}): ComplianceSummary {
  return {
    data: [
      {
        rule: 'insufficient_rest',
        requirement: 'RN-10',
        employee: {
          uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
          employee_code: 'E7QK2MXPR',
          full_name: 'Youssef Amrani',
          department: { id: 3, name: 'Recepción' },
        },
        work_date: '2026-03-14',
        week: null,
        measured_minutes: 600,
        threshold_minutes: 720,
        difference_minutes: 120,
        shift_entry_uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
        has_open_shift: false,
        incident: { id: 42, status: 'open' },
      },
    ],
    meta: {
      generated_at: '2026-03-31T09:12:03.418000Z',
      time_zone: 'Europe/Madrid',
      from: '2026-03-04',
      to: '2026-03-31',
      profile: { id: 1, name: 'ES-hosteleria', jurisdiction: 'ES' },
      week_starts_on: 1,
      rules: [
        {
          rule: 'insufficient_rest',
          requirement: 'RN-10',
          threshold_minutes: 720,
          evaluated: true,
          suspension_reason: null,
        },
        {
          rule: 'daily_excess',
          requirement: 'RN-11',
          threshold_minutes: 540,
          evaluated: true,
          suspension_reason: null,
        },
        {
          rule: 'missing_break',
          requirement: 'RN-12',
          threshold_minutes: 360,
          evaluated: false,
          suspension_reason: 'awaiting_declared_break',
        },
        {
          rule: 'weekly_excess',
          requirement: 'RN-17',
          threshold_minutes: 2400,
          evaluated: true,
          suspension_reason: null,
        },
      ],
      totals: {
        by_rule: { insufficient_rest: 1, daily_excess: 0, missing_break: 0, weekly_excess: 0 },
        employees_affected: 1,
        employees_evaluated: 48,
      },
      scope: 'all',
      criteria: [
        'Descanso entre jornadas: se compara la ultima salida con la primera entrada de la siguiente.',
        'Semana: siete dias desde el lunes, evaluados completos aunque el periodo los corte.',
      ],
    },
    ...overrides,
  }
}

function stubApi(onSummary: (url: string) => Response): ReturnType<typeof stubFetch> {
  return stubFetch((input) => {
    const url = String(input)

    if (url.includes('/compliance/summary')) {
      return onSummary(url)
    }

    if (url.includes('/departments')) {
      return jsonResponse({
        data: [
          { id: 3, name: 'Recepción' },
          { id: 4, name: 'Pisos' },
        ],
      })
    }

    return jsonResponse({}, 404)
  })
}

beforeEach(() => {
  clearAnnouncement()
})

describe('vista de cumplimiento', () => {
  it('carga al abrir, sin parametros, y rellena los filtros con lo que resuelve el servidor', async () => {
    const fetchSpy = stubApi(() => jsonResponse(summary()))

    const wrapper = await mountView(ComplianceView)
    await settle()

    const calls = fetchSpy.mock.calls.map((call) => String(call[0]))
    const summaryCall = calls.find((url) => url.includes('/compliance/summary'))

    expect(summaryCall).toBeDefined()
    // Sin `from`/`to`/`department_id`/`rule`: el servidor decide los 28 dias.
    expect(summaryCall).not.toContain('from=')
    expect(summaryCall).not.toContain('to=')
    expect(summaryCall).not.toContain('department_id')
    expect(summaryCall).not.toContain('rule=')

    expect(wrapper.find<HTMLInputElement>('#compliance-from').element.value).toBe('2026-03-04')
    expect(wrapper.find<HTMLInputElement>('#compliance-to').element.value).toBe('2026-03-31')

    expect(announcement.value).toContain('1')
  })

  it('cada tarjeta enseña el umbral en palabras y con el nombre del perfil', async () => {
    stubApi(() => jsonResponse(summary()))

    const wrapper = await mountView(ComplianceView)
    await settle()

    const cards = wrapper.findAll('[data-test="rule-card"]')

    expect(cards).toHaveLength(4)

    const restCard = cards[0]

    expect(restCard?.text()).toContain(es.complianceSummary.rules.insufficient_rest.label)
    expect(restCard?.text()).toContain('12 h 00 min')
    expect(restCard?.text()).toContain('ES-hosteleria')
    expect(restCard?.find('[data-test="rule-count"]').text()).toBe('1')
  })

  it('la regla suspendida se marca «No se evalua», con el motivo traducido', async () => {
    stubApi(() => jsonResponse(summary()))

    const wrapper = await mountView(ComplianceView)
    await settle()

    const cards = wrapper.findAll('[data-test="rule-card"]')
    const breakCard = cards.find((card) =>
      card.text().includes(es.complianceSummary.rules.missing_break.label),
    )

    expect(breakCard?.find('[data-test="rule-suspended"]').exists()).toBe(true)
    expect(breakCard?.find('[data-test="rule-suspended"]').text()).toBe(
      es.complianceSummary.notEvaluated,
    )
    expect(breakCard?.find('[data-test="rule-suspended-reason"]').text()).toBe(
      es.complianceSummary.suspensionReasons.awaiting_declared_break,
    )
  })

  it('el filtro de departamento y de regla disparan una nueva consulta al servidor', async () => {
    const fetchSpy = stubApi(() => jsonResponse(summary()))

    const wrapper = await mountView(ComplianceView)
    await settle()

    await wrapper.find('#compliance-department').setValue('3')
    await wrapper.find('#compliance-rule').setValue('insufficient_rest')
    await wrapper.find('form').trigger('submit')
    await settle()

    const lastUrl = String(fetchSpy.mock.calls.at(-1)?.[0])

    expect(lastUrl).toContain('department_id=3')
    expect(lastUrl).toContain('rule=insufficient_rest')
  })

  it('un periodo sin hallazgos da el estado vacio con los criterios', async () => {
    stubApi(() =>
      jsonResponse(
        summary({
          data: [],
          meta: { ...summary().meta, totals: { ...summary().meta.totals, employees_affected: 0 } },
        }),
      ),
    )

    const wrapper = await mountView(ComplianceView)
    await settle()

    expect(wrapper.text()).toContain(es.complianceSummary.empty.title)

    const criteria = wrapper.findAll('[data-test="compliance-criteria"] li')

    expect(criteria).toHaveLength(2)
    expect(criteria[0]?.text()).toBe(
      'Descanso entre jornadas: se compara la ultima salida con la primera entrada de la siguiente.',
    )
  })

  it('un 422 por rango excesivo se lee con el nombre del campo de esta pantalla', async () => {
    let call = 0

    stubApi((url) => {
      if (!url.includes('/compliance/summary')) {
        return jsonResponse({ data: [] })
      }

      call += 1

      return call === 1
        ? jsonResponse(summary())
        : problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: { to: ['El rango supera el maximo de 92 dias.'] },
          })
    })

    const wrapper = await mountView(ComplianceView)
    await settle()

    await wrapper.find<HTMLInputElement>('#compliance-to').setValue('2026-12-31')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain('Hasta: El rango supera el maximo')
    expect(wrapper.text()).not.toContain('to: El rango')
  })
})
