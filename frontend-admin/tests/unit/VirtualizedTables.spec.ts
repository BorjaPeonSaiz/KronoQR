// Virtualizacion del informe por periodo y de los hallazgos de cumplimiento
// (V5-PA-1, RC1 · RF-IN-01, RF-IN-03, RF-PA-06, RNF-P-04).
//
// El servidor admite hasta 20 000 filas por informe y la vista de cumplimiento no
// pagina: lo que se prueba aqui es que el DOM NUNCA crece con el numero de filas
// (solo la ventana visible), que el total y la posicion de cada fila se declaran
// (`aria-rowcount`/`aria-rowindex`, PA7-003) y que el contenedor con scroll es una
// region enfocable y con nombre (AX7-01). jsdom no maqueta: se le da al
// contenedor una altura de 600 px para que el virtualizador tenga ventana.
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import ComplianceFindingsGroupTable from '@/features/compliance/ComplianceFindingsGroupTable.vue'
import PeriodReportTable from '@/features/reports/PeriodReportTable.vue'
import type { ComplianceFinding, ComplianceRuleStatus, PeriodReportRow } from '@/shared/api/types'
import { mountView, settle } from './support/harness'

const VIEWPORT_HEIGHT_PX = 600

const heightDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetHeight')
const widthDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetWidth')

beforeAll(() => {
  Object.defineProperty(HTMLElement.prototype, 'offsetHeight', {
    configurable: true,
    get: () => VIEWPORT_HEIGHT_PX,
  })
  Object.defineProperty(HTMLElement.prototype, 'offsetWidth', {
    configurable: true,
    get: () => 1200,
  })
})

afterAll(() => {
  for (const [name, descriptor] of [
    ['offsetHeight', heightDescriptor],
    ['offsetWidth', widthDescriptor],
  ] as const) {
    if (descriptor === undefined) {
      Reflect.deleteProperty(HTMLElement.prototype, name)
    } else {
      Object.defineProperty(HTMLElement.prototype, name, descriptor)
    }
  }
})

function reportRow(index: number): PeriodReportRow {
  return {
    period: { from: '2026-03-01', to: '2026-03-31' },
    subject: {
      kind: 'employee',
      employee_uuid: `0199f0c2-1f4a-7c3e-9b21-${String(index).padStart(12, '0')}`,
      employee_code: String(100000 + index),
      full_name: `Persona ${index}`,
      department_id: 3,
      label: `Persona ${index}`,
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
    absence_days: 0,
    holiday_days: 0,
    unjustified_absence_days: 10,
  }
}

function finding(index: number): ComplianceFinding {
  return {
    rule: 'daily_excess',
    requirement: 'RN-11',
    employee: {
      uuid: `0199f0c2-1f4a-7c3e-9b21-${String(index).padStart(12, '0')}`,
      employee_code: String(100000 + index),
      full_name: `Persona ${index}`,
      department: { id: 3, name: 'Recepción' },
    },
    work_date: '2026-03-14',
    week: null,
    measured_minutes: 600,
    threshold_minutes: 540,
    difference_minutes: 60,
    shift_entry_uuid: null,
    has_open_shift: index % 2 === 0,
    incident: null,
  }
}

const RULE: ComplianceRuleStatus = {
  rule: 'daily_excess',
  requirement: 'RN-11',
  threshold_minutes: 540,
  evaluated: true,
  suspension_reason: null,
}

/** Cuanto cabe en pantalla mas el sobrepintado: el techo de filas en el DOM con cualquier volumen. */
const PAINTED_CEILING = 60

describe('PeriodReportTable virtualizada (V5-PA-1, RF-IN-01, RF-IN-03, RNF-P-04)', () => {
  it('con 20 000 filas pinta solo la ventana y declara el total y la posicion', async () => {
    const rows = Array.from({ length: 20_000 }, (_, index) => reportRow(index + 1))
    const startedAt = performance.now()
    const wrapper = await mountView(PeriodReportTable, {
      props: { rows, timeZone: 'Europe/Madrid' },
    })
    await settle()
    const elapsedMs = performance.now() - startedAt

    const painted = wrapper.findAll('[data-test="report-row"]')

    expect(painted.length).toBeGreaterThan(0)
    expect(painted.length).toBeLessThanOrEqual(PAINTED_CEILING)
    // 20 000 filas y la cabecera: el lector sabe que hay muchas mas de las que se pintan.
    expect(wrapper.find('[role="table"]').attributes('aria-rowcount')).toBe('20001')
    expect(painted[0]?.attributes('aria-rowindex')).toBe('2')
    expect(painted.at(-1)?.attributes('aria-rowindex')).toBe(String(painted.length + 1))
    // Presupuesto holgado: la medicion real esta en el E2E. Esto solo atrapa un
    // regreso a pintar las 20 000 (que tarda varios segundos en jsdom).
    expect(elapsedMs).toBeLessThan(1500)
  })

  it('el contenedor con scroll es una region enfocable y con nombre (AX7-01)', async () => {
    const wrapper = await mountView(PeriodReportTable, {
      props: { rows: [reportRow(1), reportRow(2)], timeZone: 'Europe/Madrid' },
    })
    const scroller = wrapper.find('[data-test="period-report-table"]')

    expect(scroller.attributes('tabindex')).toBe('0')
    expect(scroller.attributes('role')).toBe('region')
    expect(scroller.attributes('aria-label')).toContain('Europe/Madrid')
  })

  it('por debajo del umbral pinta todas las filas, indexadas desde la 2', async () => {
    const wrapper = await mountView(PeriodReportTable, {
      props: { rows: [reportRow(1), reportRow(2), reportRow(3)], timeZone: 'Europe/Madrid' },
    })

    expect(wrapper.find('[role="table"]').attributes('aria-rowcount')).toBe('4')
    expect(
      wrapper.findAll('[data-test="report-row"]').map((entry) => entry.attributes('aria-rowindex')),
    ).toEqual(['2', '3', '4'])
    expect(wrapper.findAll('[role="columnheader"]').length).toBeGreaterThan(0)
  })
})

describe('ComplianceFindingsGroupTable virtualizada (RC1, RF-PA-06, RNF-P-04)', () => {
  it('con 3 000 hallazgos pinta solo la ventana y declara el total y la posicion', async () => {
    const findings = Array.from({ length: 3_000 }, (_, index) => finding(index + 1))
    const startedAt = performance.now()
    const wrapper = await mountView(ComplianceFindingsGroupTable, {
      props: { group: { rule: RULE, findings }, timeZone: 'Europe/Madrid' },
    })
    await settle()
    const elapsedMs = performance.now() - startedAt

    const painted = wrapper.findAll('[data-test="compliance-finding-row"]')

    expect(painted.length).toBeGreaterThan(0)
    expect(painted.length).toBeLessThanOrEqual(PAINTED_CEILING)
    expect(wrapper.find('[role="table"]').attributes('aria-rowcount')).toBe('3001')
    expect(painted[0]?.attributes('aria-rowindex')).toBe('2')
    expect(elapsedMs).toBeLessThan(1500)
  })

  it('el contenedor con scroll es una region enfocable y con nombre (AX7-01)', async () => {
    const wrapper = await mountView(ComplianceFindingsGroupTable, {
      props: { group: { rule: RULE, findings: [finding(1)] }, timeZone: 'Europe/Madrid' },
    })
    const scroller = wrapper.find('[data-test="compliance-table"]')

    expect(scroller.attributes('tabindex')).toBe('0')
    expect(scroller.attributes('role')).toBe('region')
    expect(scroller.attributes('aria-label')).toContain('Europe/Madrid')
  })
})
