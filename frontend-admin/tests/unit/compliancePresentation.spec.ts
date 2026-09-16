// Presentacion pura de la vista de cumplimiento (RF-PA-06, tarea 3.4). Sin
// montar ningun componente: es el mismo patron que `devicePresentation.ts`.
import { describe, expect, it } from 'vitest'
import {
  differenceDirection,
  differenceLabelKey,
  findingDurations,
  findingPeriod,
  groupFindingsByRule,
  incidentsLinkFor,
  ruleLabelKey,
  suspensionReasonKey,
} from '@/features/compliance/compliancePresentation'
import type { ComplianceFinding, ComplianceRuleStatus } from '@/shared/api/types'

const EMPLOYEE = {
  uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
  employee_code: 'E7QK2MXPR',
  full_name: 'Youssef Amrani',
  department: { id: 3, name: 'Recepción' },
}

function dayFinding(overrides: Partial<ComplianceFinding> = {}): ComplianceFinding {
  return {
    rule: 'insufficient_rest',
    requirement: 'RN-10',
    employee: EMPLOYEE,
    work_date: '2026-03-14',
    week: null,
    measured_minutes: 600,
    threshold_minutes: 720,
    difference_minutes: 120,
    shift_entry_uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
    has_open_shift: false,
    incident: null,
    ...overrides,
  }
}

function weekFinding(overrides: Partial<ComplianceFinding> = {}): ComplianceFinding {
  return {
    rule: 'weekly_excess',
    requirement: 'RN-17',
    employee: EMPLOYEE,
    work_date: null,
    week: { starts_on: '2026-03-09', ends_on: '2026-03-15' },
    measured_minutes: 2530,
    threshold_minutes: 2400,
    difference_minutes: 130,
    shift_entry_uuid: null,
    has_open_shift: false,
    incident: null,
    ...overrides,
  }
}

function ruleStatus(overrides: Partial<ComplianceRuleStatus> = {}): ComplianceRuleStatus {
  return {
    rule: 'insufficient_rest',
    requirement: 'RN-10',
    threshold_minutes: 720,
    evaluated: true,
    suspension_reason: null,
    ...overrides,
  }
}

describe('ruleLabelKey', () => {
  it('nombra la clave i18n de cada regla', () => {
    expect(ruleLabelKey('insufficient_rest')).toBe(
      'complianceSummary.rules.insufficient_rest.label',
    )
    expect(ruleLabelKey('daily_excess')).toBe('complianceSummary.rules.daily_excess.label')
    expect(ruleLabelKey('missing_break')).toBe('complianceSummary.rules.missing_break.label')
    expect(ruleLabelKey('weekly_excess')).toBe('complianceSummary.rules.weekly_excess.label')
  })
})

describe('differenceDirection y differenceLabelKey', () => {
  it('RN-10 es lo unico que falta; las otras tres son excesos', () => {
    expect(differenceDirection('insufficient_rest')).toBe('missing')
    expect(differenceDirection('daily_excess')).toBe('excess')
    expect(differenceDirection('missing_break')).toBe('excess')
    expect(differenceDirection('weekly_excess')).toBe('excess')

    expect(differenceLabelKey('insufficient_rest')).toBe('complianceSummary.difference.missing')
    expect(differenceLabelKey('daily_excess')).toBe('complianceSummary.difference.excess')
  })
})

describe('findingDurations', () => {
  it('convierte los tres minutos del hallazgo a horas y minutos, sin decimales', () => {
    const durations = findingDurations(dayFinding())

    expect(durations.measured).toEqual({ hours: 10, minutes: '00' })
    expect(durations.threshold).toEqual({ hours: 12, minutes: '00' })
    expect(durations.difference).toEqual({ hours: 2, minutes: '00' })
  })

  it('no redondea: 130 minutos son 2 h 10 min, no 2 h', () => {
    const durations = findingDurations(weekFinding())

    expect(durations.measured).toEqual({ hours: 42, minutes: '10' })
    expect(durations.difference).toEqual({ hours: 2, minutes: '10' })
  })
})

describe('suspensionReasonKey', () => {
  it('es null cuando la regla se evalua', () => {
    expect(suspensionReasonKey(ruleStatus({ evaluated: true, suspension_reason: null }))).toBeNull()
  })

  it('nombra la clave del motivo cuando esta suspendida', () => {
    const suspended = ruleStatus({
      rule: 'missing_break',
      requirement: 'RN-12',
      evaluated: false,
      suspension_reason: 'awaiting_declared_break',
    })

    expect(suspensionReasonKey(suspended)).toBe(
      'complianceSummary.suspensionReasons.awaiting_declared_break',
    )
  })
})

describe('findingPeriod', () => {
  it('una jornada da una unica fecha', () => {
    expect(findingPeriod(dayFinding())).toEqual({
      kind: 'day',
      from: '2026-03-14',
      to: '2026-03-14',
    })
  })

  it('una semana da su intervalo completo', () => {
    expect(findingPeriod(weekFinding())).toEqual({
      kind: 'week',
      from: '2026-03-09',
      to: '2026-03-15',
    })
  })
})

describe('groupFindingsByRule', () => {
  const rules = [
    ruleStatus({ rule: 'insufficient_rest', requirement: 'RN-10' }),
    ruleStatus({ rule: 'daily_excess', requirement: 'RN-11', threshold_minutes: 540 }),
    ruleStatus({
      rule: 'missing_break',
      requirement: 'RN-12',
      threshold_minutes: 360,
      evaluated: false,
      suspension_reason: 'awaiting_declared_break',
    }),
    ruleStatus({ rule: 'weekly_excess', requirement: 'RN-17', threshold_minutes: 2400 }),
  ]

  it('agrupa en el orden de meta.rules[], sin generar grupos vacios', () => {
    const findings = [dayFinding(), weekFinding()]

    const groups = groupFindingsByRule(findings, rules)

    expect(groups.map((group) => group.rule.rule)).toEqual(['insufficient_rest', 'weekly_excess'])
    expect(groups[0]?.findings).toEqual([findings[0]])
    expect(groups[1]?.findings).toEqual([findings[1]])
  })

  it('una regla suspendida sin hallazgos no aparece', () => {
    const groups = groupFindingsByRule([dayFinding()], rules)

    expect(groups.some((group) => group.rule.rule === 'missing_break')).toBe(false)
  })

  it('sin ningun hallazgo, no hay ningun grupo', () => {
    expect(groupFindingsByRule([], rules)).toEqual([])
  })
})

describe('incidentsLinkFor', () => {
  it('lleva a la bandeja acotada a esa persona, con el mismo filtro que el detalle de jornada', () => {
    expect(incidentsLinkFor(EMPLOYEE.uuid)).toEqual({
      name: 'incidents',
      query: { employee: EMPLOYEE.uuid },
    })
  })
})
