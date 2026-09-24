// Cuadro de impacto y adopción (RF-IN-08, RNF-D-01, tarea 3.13).
//
// Lo que se comprueba aqui:
//
//   - Que carga al abrir, sin parametros, y enseña una tarjeta por indicador
//     con objetivo (los seis del §1.3) con su valor, su objetivo y su estado
//     (texto, no solo color).
//   - Que un periodo anterior sin denominador se enseña VACIO -nunca como
//     «0»- en la variacion de cada indicador.
//   - Que un `402` (funcionalidad fuera del plan) enseña el aviso de
//     licencia con el enlace a «Licencia», y no un error generico.
//   - Que los criterios del cuadro se enseñan tal cual los da el servidor.
//
// `ChartWithTable` se sustituye por un doble: esta pantalla no dibuja ningun
// grafico por su cuenta, asi que lo que le corresponde comprobar es que le
// pasa las categorias y las series correctas, no el lienzo (eso es
// `ChartWithTable.spec.ts`).
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdoptionDashboardView from '@/features/reports/AdoptionDashboardView.vue'
import type { AdoptionIndicator, AdoptionOriginShare, AdoptionReport } from '@/shared/api/types'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { jsonResponse, mountView, problemResponse, settle, stubFetch } from './support/harness'

vi.mock('@/shared/ui/ChartWithTable.vue', () => ({
  default: {
    name: 'ChartWithTableStub',
    props: ['type', 'title', 'categories', 'series', 'formatValue'],
    template: '<div data-test="chart-stub">{{ title }}: {{ categories.join(",") }}</div>',
  },
}))

/** Los doce indicadores del contrato, EXACTOS al esquema `AdoptionIndicator` de `docs/api/openapi.yaml`. */
function indicators(): AdoptionIndicator[] {
  return [
    {
      key: 'workdays_complete_ratio',
      unit: 'percent',
      current: 99.4,
      previous: 98.1,
      delta: 1.3,
      target: { comparison: 'at_least', value: 99 },
    },
    {
      key: 'qr_scans_ratio',
      unit: 'percent',
      current: 97.2,
      previous: 96.5,
      delta: 0.7,
      target: { comparison: 'at_least', value: 98 },
    },
    {
      key: 'manual_corrections_ratio',
      unit: 'percent',
      current: 1.4,
      previous: 1.8,
      delta: -0.4,
      target: { comparison: 'at_most', value: 2 },
    },
    {
      key: 'clocking_availability_ratio',
      unit: 'percent',
      current: 99.92,
      previous: 99.88,
      delta: 0.04,
      target: { comparison: 'at_least', value: 99.9 },
    },
    {
      key: 'offline_resolved_ratio',
      unit: 'percent',
      current: 0.6,
      previous: null,
      delta: null,
      target: null,
    },
    {
      key: 'incident_resolution_mean_minutes',
      unit: 'minutes',
      current: 390,
      previous: 555,
      delta: -165,
      target: { comparison: 'at_most', value: 1440 },
    },
    {
      key: 'incident_resolution_median_minutes',
      unit: 'minutes',
      current: 270,
      previous: null,
      delta: null,
      target: null,
    },
    { key: 'open_incidents', unit: 'count', current: 3, previous: null, delta: null, target: null },
    {
      key: 'employees_without_credential',
      unit: 'count',
      current: 2,
      previous: null,
      delta: null,
      target: null,
    },
    {
      key: 'worked_minutes',
      unit: 'minutes',
      current: 612_000,
      previous: 580_000,
      delta: 32_000,
      target: null,
    },
    {
      key: 'contracted_minutes',
      unit: 'minutes',
      current: 604_800,
      previous: 590_400,
      delta: 14_400,
      target: null,
    },
    {
      key: 'baseline_manual_minutes_per_month',
      unit: 'minutes',
      current: 1080,
      previous: null,
      delta: null,
      target: { comparison: 'reduction', value: 80 },
    },
  ]
}

function report(
  overrides: {
    indicators?: AdoptionIndicator[]
    originBreakdown?: AdoptionOriginShare[]
    criteria?: string[]
  } = {},
): AdoptionReport {
  return {
    data: {
      indicators: overrides.indicators ?? indicators(),
      origin_breakdown: overrides.originBreakdown ?? [
        { origin: 'qr_kiosk', scans: 4120, share: 97.2 },
        { origin: 'pin_kiosk', scans: 96, share: 2.3 },
        { origin: 'manual_admin', scans: 18, share: 0.4 },
        { origin: 'import', scans: 4, share: 0.1 },
      ],
    },
    meta: {
      generated_at: '2026-04-01T07:20:11.000000Z',
      time_zone: 'Europe/Madrid',
      period: { from: '2026-03-01', to: '2026-03-31', days: 31 },
      previous_period: { from: '2026-01-29', to: '2026-02-28', days: 31 },
      criteria: overrides.criteria ?? [
        'Una jornada con registro completo es una jornada con algún tramo en la que todos los tramos están cerrados.',
        'El reparto por origen solo cuenta fichajes aceptados.',
      ],
    },
  }
}

function stubApi(onReport: (url: string) => Response): ReturnType<typeof stubFetch> {
  return stubFetch((input) => {
    const url = String(input)

    if (url.includes('/reports/adoption/export')) {
      return new Response('contenido-de-prueba', {
        status: 200,
        headers: { 'Content-Type': 'text/csv' },
      })
    }

    if (url.includes('/reports/adoption')) {
      return onReport(url)
    }

    return jsonResponse({}, 404)
  })
}

beforeEach(() => {
  clearAnnouncement()
})

describe('cuadro de impacto y adopción', () => {
  it('carga al abrir y enseña una tarjeta por indicador con objetivo, con su valor y su estado', async () => {
    stubApi(() => jsonResponse(report()))

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    const cards = wrapper.findAll('[data-test="indicator-card"]')

    // Los seis indicadores con objetivo del §1.3 (los otros seis del
    // contrato viajan como dato secundario dentro de estas tarjetas).
    expect(cards).toHaveLength(6)

    const completeWorkdays = cards.find(
      (card) => card.attributes('data-indicator') === 'workdays_complete_ratio',
    )

    // Dos decimales, con coma (idioma es por omision de `mountView`): un solo
    // decimal colapsaba el margen de RNF-D-01 (segunda vuelta, bloqueante).
    expect(completeWorkdays?.find('[data-test="workdays-complete-ratio-value"]').text()).toBe(
      '99,40 %',
    )
    expect(completeWorkdays?.find('[data-test="workdays-complete-ratio-status"]').text()).toContain(
      'Dentro del objetivo',
    )
    expect(completeWorkdays?.text()).toContain('Objetivo: ≥ 99,00 %')
    // La variacion de un porcentaje va en puntos porcentuales, no en «%»
    // (segunda vuelta de la ficha).
    expect(completeWorkdays?.find('[data-test="workdays-complete-ratio-delta"]').text()).toBe(
      '+1,30 pp',
    )

    // La disponibilidad lleva el subindicador «resueltos sin servidor».
    const availability = cards.find(
      (card) => card.attributes('data-indicator') === 'clocking_availability_ratio',
    )

    expect(availability?.find('[data-test="availability-offline"]').exists()).toBe(true)

    // El tiempo de resolucion lleva la mediana como dato secundario.
    const resolution = cards.find(
      (card) => card.attributes('data-indicator') === 'incident_resolution_mean_minutes',
    )

    expect(resolution?.find('[data-test="median-resolution"]').exists()).toBe(true)

    // Fotos sin comparacion.
    expect(wrapper.find('[data-test="incidents-open-card"]').text()).toContain('3')
    expect(wrapper.find('[data-test="credentials-card"]').text()).toContain('2')

    // Horas trabajadas frente a contratadas, en horas y minutos enteros
    // (regla dura, nunca decimal): 612 000 min = 10200 h 00 min.
    expect(wrapper.find('[data-test="worked-hours-card"]').text()).toContain('10200 h 00 min')
    expect(wrapper.find('[data-test="worked-hours-card"]').text()).toContain('10080 h 00 min')

    expect(announcement.value).not.toBe('')
  })

  it('RNF-D-01: 99,86 % fuera del objetivo de ≥ 99,9 % no se confunde con 99,94 % dentro (bloqueante, segunda vuelta)', async () => {
    stubApi(() =>
      jsonResponse(
        report({
          indicators: indicators().map((indicator) =>
            indicator.key === 'clocking_availability_ratio'
              ? { ...indicator, current: 99.86, previous: 99.94, delta: -0.08 }
              : indicator,
          ),
        }),
      ),
    )

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    const availability = wrapper
      .findAll('[data-test="indicator-card"]')
      .find((card) => card.attributes('data-indicator') === 'clocking_availability_ratio')

    // Con un solo decimal, 99,86 redondeaba a «99.9 %» -el mismo valor que el
    // objetivo- y a la vez se marcaba «fuera»: una contradiccion visible.
    expect(availability?.find('[data-test="clocking-availability-ratio-value"]').text()).toBe(
      '99,86 %',
    )
    expect(availability?.find('[data-test="clocking-availability-ratio-status"]').text()).toContain(
      'Fuera del objetivo',
    )
    expect(availability?.text()).toContain('Objetivo: ≥ 99,90 %')
  })

  it('un indicador fuera de objetivo lo dice con texto, no solo con color', async () => {
    stubApi(() =>
      jsonResponse(
        report({
          indicators: indicators().map((indicator) =>
            indicator.key === 'manual_corrections_ratio'
              ? { ...indicator, current: 3.5 }
              : indicator,
          ),
        }),
      ),
    )

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    const corrections = wrapper
      .findAll('[data-test="indicator-card"]')
      .find((card) => card.attributes('data-indicator') === 'manual_corrections_ratio')

    expect(corrections?.find('[data-test="manual-corrections-ratio-status"]').text()).toContain(
      'Fuera del objetivo',
    )
  })

  it('un periodo anterior sin denominador se enseña vacio, nunca como 0', async () => {
    stubApi(() =>
      jsonResponse(
        report({
          indicators: indicators().map((indicator) => ({
            ...indicator,
            previous: null,
            delta: null,
          })),
        }),
      ),
    )

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    const deltas = wrapper.findAll('[data-test$="-delta"]')

    expect(deltas.length).toBeGreaterThan(0)

    for (const delta of deltas) {
      expect(delta.text()).toBe('Sin periodo anterior comparable.')
      expect(delta.text()).not.toContain('0')
    }
  })

  it('un 402 enseña el aviso de licencia con el enlace a «Licencia»', async () => {
    stubApi(() =>
      problemResponse(402, 'urn:kronoqr:problem:feature-not-licensed', {
        feature: 'impact_dashboard',
        restriction: 'not_in_plan',
      }),
    )

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    expect(wrapper.find('[data-test="license-required-notice"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('no está incluido en tu licencia actual')
  })

  it('un 422 «report-too-large» enseña el aviso de acortar el rango, distinguido por el `type`', async () => {
    stubApi(() =>
      problemResponse(422, 'urn:kronoqr:problem:report-too-large', {
        errors: { to: ['El rango supera el máximo de 366 días.'] },
      }),
    )

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    expect(wrapper.find('[data-test="range-too-large-notice"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="license-required-notice"]').exists()).toBe(false)
  })

  it('un 422 de validación corriente sigue el camino genérico, no el aviso de rango', async () => {
    stubApi(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { to: ['El periodo termina antes de empezar.'] },
      }),
    )

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    expect(wrapper.find('[data-test="range-too-large-notice"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('El periodo termina antes de empezar.')
  })

  it('los criterios se enseñan tal cual los da el servidor', async () => {
    stubApi(() => jsonResponse(report()))

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    const criteria = wrapper.findAll('[data-test="adoption-criteria"] li')

    expect(criteria).toHaveLength(2)
    expect(criteria[0]?.text()).toBe(
      'Una jornada con registro completo es una jornada con algún tramo en la que todos los tramos están cerrados.',
    )
  })

  it('el reparto de origen nunca convierte "sin fichajes aceptados" en 0 al pasarlo al grafico', async () => {
    stubApi(() =>
      jsonResponse(
        report({
          originBreakdown: [
            { origin: 'qr_kiosk', scans: 0, share: null },
            { origin: 'pin_kiosk', scans: 0, share: null },
            { origin: 'manual_admin', scans: 0, share: null },
            { origin: 'import', scans: 0, share: null },
          ],
        }),
      ),
    )

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    const pieChart = wrapper.findAllComponents({ name: 'ChartWithTableStub' })[0]
    const series = pieChart?.props('series') as ReadonlyArray<{
      readonly values: ReadonlyArray<number | null>
    }>

    expect(series[0]?.values).toEqual([null, null, null, null])
  })

  it('las barras de comparacion no fabrican una barra a cero para un indicador sin periodo anterior', async () => {
    const keysWithoutPrevious = [
      'qr_scans_ratio',
      'manual_corrections_ratio',
      'clocking_availability_ratio',
    ]

    stubApi(() =>
      jsonResponse(
        report({
          indicators: indicators().map((indicator) =>
            keysWithoutPrevious.includes(indicator.key)
              ? { ...indicator, previous: null, delta: null }
              : indicator,
          ),
        }),
      ),
    )

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    const barChart = wrapper.findAllComponents({ name: 'ChartWithTableStub' })[1]
    const series = barChart?.props('series') as ReadonlyArray<{
      readonly name: string
      readonly values: ReadonlyArray<number | null>
    }>

    // Solo `workdays_complete_ratio` conserva su `previous` (98,1): la serie
    // «Periodo anterior» aparece por el, pero los otros tres quedan en
    // `null` -un hueco en su barra-, nunca en `0` fabricado.
    expect(series).toHaveLength(2)
    expect(series[1]?.values).toEqual([98.1, null, null, null])
  })

  it('el estado vacio es alcanzable: sin jornadas y sin fichajes aceptados', async () => {
    stubApi(() =>
      jsonResponse(
        report({
          indicators: indicators().map((indicator) =>
            indicator.key === 'workdays_complete_ratio'
              ? { ...indicator, current: null, previous: null, delta: null }
              : indicator,
          ),
          originBreakdown: [
            { origin: 'qr_kiosk', scans: 0, share: null },
            { origin: 'pin_kiosk', scans: 0, share: null },
            { origin: 'manual_admin', scans: 0, share: null },
            { origin: 'import', scans: 0, share: null },
          ],
        }),
      ),
    )

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    expect(wrapper.find('[data-test="indicator-cards"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('No hay datos que mostrar en ese periodo')
  })

  it('la linea base declarada de horas al mes se enseña, y su ausencia tambien', async () => {
    stubApi(() =>
      jsonResponse(
        report({
          indicators: indicators().map((indicator) =>
            indicator.key === 'baseline_manual_minutes_per_month'
              ? { ...indicator, current: null }
              : indicator,
          ),
        }),
      ),
    )

    const wrapper = await mountView(AdoptionDashboardView)
    await settle()

    expect(wrapper.find('[data-test="baseline-not-declared"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="baseline-declared"]').exists()).toBe(false)
  })
})
