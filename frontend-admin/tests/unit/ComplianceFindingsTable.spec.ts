// Tabla de hallazgos de la vista de cumplimiento (RF-PA-06, tarea 3.4).
//
// Lo que se comprueba aqui, que `ComplianceView.spec.ts` no cubre porque solo
// monta hallazgos diarios sin turno abierto:
//
//   - Que una semana (RN-17) enseña su intervalo completo y no una fecha
//     suelta.
//   - Que el turno abierto se marca con TEXTO, no solo con color (WCAG 1.4.1).
//   - Que cada fila enlaza al registro de esa persona **al dia del aviso**
//     (`?from=`/`?to=`, RF-PA-06 segunda vuelta), no a los ultimos 31 dias por
//     omision del servidor.
//   - Que el enlace a la incidencia respeta el ambito `incidents:*` (regla
//     dura 18).
import { describe, expect, it } from 'vitest'
import ComplianceFindingsTable from '@/features/compliance/ComplianceFindingsTable.vue'
import { groupFindingsByRule } from '@/features/compliance/compliancePresentation'
import { useSessionStore } from '@/features/auth/session.store'
import type { ComplianceFinding, ComplianceRuleStatus } from '@/shared/api/types'
import { managementUser } from './support/fixtures'
import { createTestPinia, mountView, settle } from './support/harness'

const EMPLOYEE = {
  uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
  employee_code: 'E7QK2MXPR',
  full_name: 'Youssef Amrani',
  department: { id: 3, name: 'Recepción' },
}

const DAY_FINDING: ComplianceFinding = {
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
  incident: { id: 412, status: 'open' },
}

const WEEK_FINDING: ComplianceFinding = {
  rule: 'weekly_excess',
  requirement: 'RN-17',
  employee: EMPLOYEE,
  work_date: null,
  week: { starts_on: '2026-03-09', ends_on: '2026-03-15' },
  measured_minutes: 2530,
  threshold_minutes: 2400,
  difference_minutes: 130,
  shift_entry_uuid: null,
  has_open_shift: true,
  incident: null,
}

const RULES: readonly ComplianceRuleStatus[] = [
  {
    rule: 'insufficient_rest',
    requirement: 'RN-10',
    threshold_minutes: 720,
    evaluated: true,
    suspension_reason: null,
  },
  {
    rule: 'weekly_excess',
    requirement: 'RN-17',
    threshold_minutes: 2400,
    evaluated: true,
    suspension_reason: null,
  },
]

const GROUPS = groupFindingsByRule([DAY_FINDING, WEEK_FINDING], RULES)

describe('ComplianceFindingsTable', () => {
  it('la fila de una semana enseña su intervalo, y no una fecha suelta', async () => {
    const wrapper = await mountView(ComplianceFindingsTable, {
      props: { groups: GROUPS, timeZone: 'Europe/Madrid' },
    })
    await settle()

    // Un unico hallazgo por grupo (`GROUPS`): la primera fila es la del grupo
    // RN-10 (jornada) y la segunda la del grupo RN-17 (semana), en el mismo
    // orden en que se declaran los grupos.
    const rows = wrapper.findAll('[data-test="compliance-finding-row"]')
    const weekRow = rows[1]

    expect(weekRow?.text()).toContain('9 mar')
    expect(weekRow?.text()).toContain('15 mar')
  })

  it('marca con texto, y no solo con color, la jornada que sigue abierta', async () => {
    const wrapper = await mountView(ComplianceFindingsTable, {
      props: { groups: GROUPS, timeZone: 'Europe/Madrid' },
    })
    await settle()

    const rows = wrapper.findAll('[data-test="compliance-finding-row"]')
    const dayRow = rows[0]
    const weekRow = rows[1]

    const badge = weekRow?.find('[data-test="finding-open-shift"]')

    expect(badge?.exists()).toBe(true)
    expect(badge?.text()).toContain('Turno abierto')
    // El dia de RN-10 no tiene el turno abierto: la insignia no se enseña en
    // todas las filas, solo en la que trae `has_open_shift`.
    expect(dayRow?.find('[data-test="finding-open-shift"]').exists()).toBe(false)
  })

  it('cada fila enlaza al registro de esa persona al dia del aviso, no a los ultimos 31 dias', async () => {
    const wrapper = await mountView(ComplianceFindingsTable, {
      props: { groups: GROUPS, timeZone: 'Europe/Madrid' },
    })
    await settle()

    const links = wrapper
      .findAll('a')
      .filter((link) => link.attributes('href')?.includes('/employees/') === true)

    expect(links).toHaveLength(2)

    // La jornada: el rango es esa unica fecha.
    expect(
      links.some(
        (link) =>
          link.attributes('href')?.includes('from=2026-03-14') === true &&
          link.attributes('href')?.includes('to=2026-03-14') === true,
      ),
    ).toBe(true)

    // La semana: el rango es el intervalo completo, no un dia suelto.
    expect(
      links.some(
        (link) =>
          link.attributes('href')?.includes('from=2026-03-09') === true &&
          link.attributes('href')?.includes('to=2026-03-15') === true,
      ),
    ).toBe(true)
  })

  it('el enlace a la incidencia solo aparece con el ambito incidents:* (regla dura 18)', async () => {
    const withoutAbility = await mountView(ComplianceFindingsTable, {
      props: { groups: GROUPS, timeZone: 'Europe/Madrid' },
    })
    await settle()

    expect(withoutAbility.find('[data-test="finding-incident-link"]').exists()).toBe(false)
    // Sin el ambito, la fila dice el estado tal cual, no un enlace vacio.
    expect(withoutAbility.text()).toContain('Pendiente')

    const pinia = createTestPinia()
    const session = useSessionStore(pinia)

    session.token = 'token'
    session.status = 'authenticated'
    session.user = managementUser({ abilities: ['incidents:*'] })

    const withAbility = await mountView(ComplianceFindingsTable, {
      props: { groups: GROUPS, timeZone: 'Europe/Madrid' },
      pinia,
    })
    await settle()

    const link = withAbility.find('[data-test="finding-incident-link"]')

    expect(link.exists()).toBe(true)
    expect(link.attributes('href')).toContain('employee=0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
  })

  it('sin incidencia, la celda dice que no hay ninguna en vez de dejar el hueco en blanco', async () => {
    const wrapper = await mountView(ComplianceFindingsTable, {
      props: { groups: GROUPS, timeZone: 'Europe/Madrid' },
    })
    await settle()

    const rows = wrapper.findAll('[data-test="compliance-finding-row"]')
    const weekRow = rows[1]

    expect(weekRow?.text()).toContain('—')
  })
})
