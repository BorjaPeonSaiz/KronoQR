import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { useSessionStore } from '@/features/auth/session.store'
import EmploymentPeriodsSection from '@/features/employees/EmploymentPeriodsSection.vue'
import es from '@/shared/i18n/locales/es.json'
import type { EmploymentPeriod } from '@/shared/api/types'
import { EMPLOYEE_UUID, PERIOD_UUID, employmentPeriod, managementUser } from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

// Historia de alta y baja y correccion auditada de la fecha de cese (RN-27, RL-04,
// ADR-062 §7, RF-GP-03): motivo obligatorio de 10 a 1 000 caracteres, desde que
// fecha y hacia cual, y nada se borra.

const TIMEZONE = 'Europe/Madrid'
const SECOND_UUID = '019a3b5e-7c00-7d1e-8f2a-3b4c5d6e7f90'

const PAST = employmentPeriod()
const CURRENT = employmentPeriod({
  uuid: SECOND_UUID,
  hired_at: '2026-10-01',
  terminated_at: null,
  state: 'active',
})

async function mountSection(
  periods: EmploymentPeriod[],
  options: { abilities?: string[]; minimised?: boolean } = {},
) {
  const pinia = createTestPinia()
  const session = useSessionStore()

  session.user = managementUser({ abilities: options.abilities ?? ['employees:*'] })
  session.token = 'un-token'
  session.status = 'authenticated'

  const wrapper = await mountView(EmploymentPeriodsSection, {
    props: {
      employeeUuid: EMPLOYEE_UUID,
      periods,
      timezone: TIMEZONE,
      minimised: options.minimised ?? false,
    },
    pinia,
  })

  await settle(1)

  return wrapper
}

function stubCorrection(
  respond?: () => Response,
): Array<{ url: string; body: Record<string, unknown> }> {
  const calls: Array<{ url: string; body: Record<string, unknown> }> = []

  stubFetch((url, init) => {
    calls.push({ url, body: JSON.parse(String(init?.body ?? '{}')) })

    return (
      respond?.() ??
      jsonResponse(
        {
          id: 12,
          employment_period_uuid: PERIOD_UUID,
          previous_terminated_at: '2026-08-31',
          terminated_at: '2026-09-02',
          corrected_at: '2026-11-02T09:12:44.120Z',
        },
        201,
      )
    )
  })

  return calls
}

const dialog = (wrapper: Awaited<ReturnType<typeof mountSection>>) =>
  wrapper.find('[role="dialog"]')

async function openCorrection(wrapper: Awaited<ReturnType<typeof mountSection>>) {
  await wrapper.find('[data-test="correct-termination"]').trigger('click')
  await settle(1)
}

beforeEach(() => {
  clearAnnouncement()
  vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-11-02T10:00:00Z') })
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('EmploymentPeriodsSection: la historia de alta y baja (RN-27)', () => {
  it('lista todos los periodos, del mas antiguo al mas reciente, con su estado en texto', async () => {
    const wrapper = await mountSection([PAST, CURRENT])
    const rows = wrapper.findAll('[data-test="employment-period"]')

    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('16 mar 2026')
    expect(rows[0]?.text()).toContain('31 ago 2026')
    expect(rows[0]?.text()).toContain(es.employees.periods.state.terminated)
    expect(rows[1]?.text()).toContain('1 oct 2026')
    expect(rows[1]?.text()).toContain(es.employees.periods.noEnd)
    expect(rows[1]?.text()).toContain(es.employees.periods.state.active)
    // Entre dos periodos no hay tramos ni ausencias: se dice.
    expect(wrapper.text()).toContain(es.employees.periods.gapNote)
  })

  it('es una tabla accesible: titulo, encabezados de columna y de fila, region con nombre', async () => {
    const wrapper = await mountSection([PAST])

    expect(wrapper.find('caption').text()).toBe(es.employees.periods.caption)
    expect(wrapper.findAll('th[scope="col"]').length).toBeGreaterThanOrEqual(3)
    expect(wrapper.find('tbody th[scope="row"]').text()).toContain('16 mar 2026')
    expect(wrapper.find('[role="region"]').attributes('aria-label')).toBe(
      es.employees.periods.caption,
    )
  })

  it('sin periodos no pinta nada', async () => {
    const wrapper = await mountSection([])

    expect(wrapper.find('[data-test="employment-periods"]').exists()).toBe(false)
  })

  it('una baja programada se rotula como tal y no se corrige (se cambia de fecha desde la baja)', async () => {
    const wrapper = await mountSection([
      employmentPeriod({ hired_at: '2026-10-01', terminated_at: '2026-11-30', state: 'scheduled' }),
    ])

    expect(wrapper.text()).toContain(es.employees.periods.state.scheduled)
    expect(wrapper.find('[data-test="correct-termination"]').exists()).toBe(false)
  })

  it('solo se ofrece corregir en un periodo con baja efectiva', async () => {
    const wrapper = await mountSection([PAST, CURRENT])

    expect(wrapper.findAll('[data-test="correct-termination"]')).toHaveLength(1)
  })

  it('quien no lleva employees:* no ve el boton ni la columna de acciones', async () => {
    const wrapper = await mountSection([PAST], { abilities: ['employees:read'] })

    expect(wrapper.find('[data-test="correct-termination"]').exists()).toBe(false)
    expect(wrapper.findAll('th[scope="col"]')).toHaveLength(3)
  })

  it('una ficha minimizada solo se lee', async () => {
    const wrapper = await mountSection([PAST], { minimised: true })

    expect(wrapper.find('[data-test="correct-termination"]').exists()).toBe(false)
  })
})

describe('TerminationCorrectionDialog: corregir la fecha de cese (RN-27, RL-04)', () => {
  it('enseña desde que fecha y hacia cual, y exige un motivo de al menos 10 caracteres', async () => {
    const wrapper = await mountSection([PAST])

    await openCorrection(wrapper)

    const date = dialog(wrapper).find<HTMLInputElement>('input[type="date"]')
    const confirm = dialog(wrapper).find('[data-test="confirm-dialog-confirm"]')

    expect(date.element.value).toBe('2026-08-31')
    // Sin cambiar la fecha ni escribir el motivo no hay nada que registrar.
    expect(confirm.attributes('aria-disabled')).toBeDefined()

    await date.setValue('2026-09-02')
    await dialog(wrapper).find('textarea').setValue('corto')
    await settle(1)

    expect(confirm.attributes('aria-disabled')).toBeDefined()
    expect(dialog(wrapper).text()).toContain('Escribe al menos 10 caracteres.')

    await dialog(wrapper).find('textarea').setValue('Trabajó hasta el 2 según el cuadrante')
    await settle(1)

    const preview = dialog(wrapper).find('table')

    expect(preview.text()).toContain('31 ago 2026')
    expect(preview.text()).toMatch(/2 sep/)
    expect(preview.text()).toContain('Trabajó hasta el 2 según el cuadrante')
    expect(confirm.attributes('aria-disabled')).toBeUndefined()
  })

  it('el motivo no pasa de 1 000 caracteres y cuenta los que lleva', async () => {
    const wrapper = await mountSection([PAST])

    await openCorrection(wrapper)
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-09-02')
    await dialog(wrapper).find('textarea').setValue('x'.repeat(1001))
    await settle(1)

    expect(
      dialog(wrapper).find('[data-test="confirm-dialog-confirm"]').attributes('aria-disabled'),
    ).toBeDefined()
    expect(dialog(wrapper).text()).toContain('como máximo 1000')

    await dialog(wrapper).find('textarea').setValue('x'.repeat(40))
    await settle(1)

    expect(dialog(wrapper).find('[data-test="reason-counter"]').text()).toBe(
      '40 de 1000 caracteres',
    )
  })

  it('registra la correccion con motivo, la anuncia y enseña la fecha anterior que se conserva', async () => {
    const calls = stubCorrection()
    const wrapper = await mountSection([PAST])

    await openCorrection(wrapper)
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-09-02')
    await dialog(wrapper).find('textarea').setValue('Trabajó hasta el 2 según el cuadrante')
    await settle(1)
    await dialog(wrapper).find('[data-test="confirm-dialog-confirm"]').trigger('click')
    await settle()

    expect(calls).toEqual([
      {
        url: `/api/v1/employees/${EMPLOYEE_UUID}/employment-periods/${PERIOD_UUID}/termination-corrections`,
        body: { terminated_at: '2026-09-02', reason: 'Trabajó hasta el 2 según el cuadrante' },
      },
    ])
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.emitted('corrected')).toHaveLength(1)
    expect(announcement.value).toContain('31 ago 2026')
    expect(announcement.value).toMatch(/2 sep/)
    // El dato anterior no desaparece: sigue a la vista junto al nuevo.
    expect(wrapper.find('[data-test="last-correction"]').text()).toContain('31 ago 2026')
    expect(wrapper.find('[data-test="last-correction"]').text()).toMatch(/2 sep/)
  })

  it('nunca admite una fecha posterior a hoy ni anterior al alta del periodo', async () => {
    const wrapper = await mountSection([PAST])

    await openCorrection(wrapper)

    const date = dialog(wrapper).find<HTMLInputElement>('input[type="date"]')

    expect(date.attributes('min')).toBe('2026-03-16')
    expect(date.attributes('max')).toBe('2026-11-02')
    expect(dialog(wrapper).text()).toContain('Entre el 16 mar 2026 y 2 nov 2026.')

    await dialog(wrapper).find('textarea').setValue('Motivo suficientemente largo')
    await date.setValue('2026-11-05')
    await settle(1)

    expect(date.attributes('aria-invalid')).toBe('true')
    expect(
      dialog(wrapper).find('[data-test="confirm-dialog-confirm"]').attributes('aria-disabled'),
    ).toBeDefined()

    await date.setValue('2026-03-01')
    await settle(1)

    expect(date.attributes('aria-invalid')).toBe('true')
  })

  it('con un periodo posterior (una readmision) el techo es el dia antes de su alta', async () => {
    const wrapper = await mountSection([PAST, CURRENT])

    await openCorrection(wrapper)

    const date = dialog(wrapper).find<HTMLInputElement>('input[type="date"]')

    expect(date.attributes('max')).toBe('2026-09-30')

    await dialog(wrapper).find('textarea').setValue('Motivo suficientemente largo')
    await date.setValue('2026-10-01')
    await settle(1)

    expect(date.attributes('aria-invalid')).toBe('true')
    expect(dialog(wrapper).text()).toContain('anterior al alta del periodo siguiente')
    // Y avisa de lo que no se mueve: el contrato cerrado al readmitir.
    expect(dialog(wrapper).find('[data-test="correction-contract-note"]').text()).toBe(
      es.employees.correction.consequenceContract,
    )
  })

  it('adelantar el cese avisa de que lo posterior no se borra; retrasarlo, de que los fichajes rechazados no se reprocesan', async () => {
    const wrapper = await mountSection([PAST])

    await openCorrection(wrapper)

    const date = dialog(wrapper).find<HTMLInputElement>('input[type="date"]')

    await date.setValue('2026-08-20')
    await settle(1)

    expect(dialog(wrapper).find('[data-test="correction-earlier"]').text()).toBe(
      es.employees.correction.earlierNotice,
    )

    await date.setValue('2026-09-02')
    await settle(1)

    expect(dialog(wrapper).find('[data-test="correction-later"]').text()).toBe(
      es.employees.correction.laterNotice,
    )
    expect(dialog(wrapper).text()).toContain(es.employees.correction.consequenceKept)
  })

  it('el 422 de la fecha o del motivo se pinta junto a su campo', async () => {
    stubCorrection(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { reason: ['El motivo es demasiado corto.'] },
      }),
    )

    const wrapper = await mountSection([PAST])

    await openCorrection(wrapper)
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-09-02')
    await dialog(wrapper).find('textarea').setValue('Motivo suficientemente largo')
    await settle(1)
    await dialog(wrapper).find('[data-test="confirm-dialog-confirm"]').trigger('click')
    await settle()

    expect(dialog(wrapper).find('textarea').attributes('aria-invalid')).toBe('true')
    expect(dialog(wrapper).text()).toContain('El motivo es demasiado corto.')
    expect(dialog(wrapper).find('[data-test="employment-problem"]').exists()).toBe(false)
  })

  it('si el periodo ya no tiene la baja efectiva lo explica, dice que hacer y avisa para releer', async () => {
    stubCorrection(() => problemResponse(409, 'urn:kronoqr:problem:offboarding-not-effective'))

    const wrapper = await mountSection([PAST])

    await openCorrection(wrapper)
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-09-02')
    await dialog(wrapper).find('textarea').setValue('Motivo suficientemente largo')
    await settle(1)
    await dialog(wrapper).find('[data-test="confirm-dialog-confirm"]').trigger('click')
    await settle()

    expect(dialog(wrapper).find('[data-test="employment-problem"]').text()).toContain(
      es.employees.problems.offboardingNotEffective.advice,
    )
    expect(wrapper.emitted('stale')?.[0]).toEqual(['offboardingNotEffective'])
  })

  it('cancelar cierra sin enviar nada', async () => {
    const spy = stubFetch(() => jsonResponse({}))
    const wrapper = await mountSection([PAST])

    await openCorrection(wrapper)
    await dialog(wrapper).find('[data-test="confirm-dialog-cancel"]').trigger('click')

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(spy).not.toHaveBeenCalled()
  })
})
