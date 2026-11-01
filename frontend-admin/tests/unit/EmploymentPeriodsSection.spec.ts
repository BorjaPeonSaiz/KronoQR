import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { useSessionStore } from '@/features/auth/session.store'
import EmploymentPeriodsSection from '@/features/employees/EmploymentPeriodsSection.vue'
import es from '@/shared/i18n/locales/es.json'
import type { EmploymentPeriodRecord } from '@/shared/api/types'
import {
  EMPLOYEE_UUID,
  PERIOD_UUID,
  employmentPeriodRecord,
  managementUser,
  terminationCorrection,
} from './support/fixtures'
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

const PAST = employmentPeriodRecord()
const CURRENT = employmentPeriodRecord({
  uuid: SECOND_UUID,
  hired_at: '2026-10-01',
  terminated_at: null,
  state: 'active',
})

async function mountSection(
  periods: EmploymentPeriodRecord[],
  options: { abilities?: string[]; minimised?: boolean; attach?: boolean } = {},
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
    attachTo: options.attach === true ? document.body : undefined,
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
      PAST,
      employmentPeriodRecord({
        hired_at: '2026-10-01',
        terminated_at: '2026-11-30',
        state: 'scheduled',
      }),
    ])

    expect(wrapper.text()).toContain(es.employees.periods.state.scheduled)
    // Solo el periodo con baja efectiva ofrece corregir; el programado no.
    expect(wrapper.findAll('[data-test="correct-termination"]')).toHaveLength(1)
  })

  it('un solo periodo sin baja efectiva no pinta la tabla: repite Datos y Baja', async () => {
    const active = await mountSection([CURRENT])
    const scheduled = await mountSection([
      employmentPeriodRecord({
        hired_at: '2026-10-01',
        terminated_at: '2026-11-30',
        state: 'scheduled',
      }),
    ])

    expect(active.find('[data-test="employment-periods"]').exists()).toBe(false)
    expect(scheduled.find('[data-test="employment-periods"]').exists()).toBe(false)
  })

  it('un solo periodo con baja efectiva si se enseña: hay algo que corregir', async () => {
    const wrapper = await mountSection([PAST])

    expect(wrapper.find('[data-test="employment-periods"]').exists()).toBe(true)
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

describe('EmploymentPeriodsSection: historico de correcciones del cese (RN-13, RL-04)', () => {
  const CORRECTED = employmentPeriodRecord({
    terminated_at: '2026-09-02',
    corrections: [
      terminationCorrection({
        id: 1,
        previous_terminated_at: '2026-08-31',
        terminated_at: '2026-09-01',
        reason: 'Primera correccion tras revisar el cuadrante',
      }),
      terminationCorrection({
        id: 2,
        previous_terminated_at: '2026-09-01',
        terminated_at: '2026-09-02',
        corrected_by: {
          uuid: '0199f0aa-2222-7000-8000-0123456789ab',
          name: 'Marta Soler',
          minimised: false,
        },
        corrected_at: '2026-11-02T09:12:44.120Z',
        reason: 'Trabajo hasta el 2\nsegun el cuadrante firmado',
      }),
    ],
  })

  it('enseña cada correccion con la fecha anterior y la nueva, quien, cuando y el motivo, de la mas antigua a la mas reciente', async () => {
    const wrapper = await mountSection([CORRECTED])
    const items = wrapper.findAll('[data-test="termination-correction"]')

    expect(items).toHaveLength(2)
    expect(items[0]?.text()).toContain('De 31 ago 2026 a 1 sept 2026')
    expect(items[0]?.text()).toContain('Direccion RRHH')
    expect(items[0]?.text()).toContain('Motivo: Primera correccion tras revisar el cuadrante')
    expect(items[1]?.text()).toContain('De 1 sept 2026 a 2 sept 2026')
    expect(items[1]?.text()).toContain('Marta Soler')
    // El instante sale en la zona del centro, con la zona escrita: 09:12 UTC es 10:12 en Madrid (CET).
    expect(items[1]?.text()).toContain('10:12')
    expect(items[1]?.text()).toContain('CET')
    expect(items[1]?.text()).toContain('Motivo: Trabajo hasta el 2')
  })

  it('el historico esta en la propia ficha: se ve igual al recargar, sin depender de la sesion', async () => {
    const wrapper = await mountSection([CORRECTED])

    expect(wrapper.find('[data-test="termination-corrections"]').text()).toContain(
      es.employees.periods.correctionsHeading,
    )
    expect(wrapper.find('[data-test="last-correction"]').exists()).toBe(false)
  })

  it('un periodo sin correcciones no pinta fila de historico', async () => {
    const wrapper = await mountSection([PAST])

    expect(wrapper.find('[data-test="termination-corrections"]').exists()).toBe(false)
  })

  it('el motivo largo conserva sus saltos de linea y no desborda la tabla', async () => {
    const wrapper = await mountSection([CORRECTED])
    const reason = wrapper.findAll('[data-test="termination-correction"]')[1]?.find('.block')

    expect(reason?.classes()).toContain('whitespace-pre-line')
    expect(reason?.classes()).toContain('break-words')
  })

  it('quien no puede corregir tambien lo ve, con la fila a todo el ancho de la tabla', async () => {
    const wrapper = await mountSection([CORRECTED], { abilities: ['employees:read'] })

    expect(wrapper.findAll('[data-test="termination-correction"]')).toHaveLength(2)
    expect(wrapper.find('[data-test="termination-corrections"] td').attributes('colspan')).toBe('3')
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
    // Mientras se escribe no se grita: el error sale al salir del campo.
    expect(dialog(wrapper).text()).not.toContain('Escribe al menos 10 caracteres.')
    expect(dialog(wrapper).find('textarea').attributes('aria-invalid')).toBeUndefined()

    await dialog(wrapper).find('textarea').trigger('blur')
    await settle(1)

    expect(dialog(wrapper).text()).toContain('Escribe al menos 10 caracteres.')
    expect(dialog(wrapper).find('textarea').attributes('aria-invalid')).toBe('true')

    await dialog(wrapper).find('textarea').setValue('Trabajó hasta el 2 según el cuadrante')
    await settle(1)

    const preview = dialog(wrapper).find('table')

    expect(preview.text()).toContain('31 ago 2026')
    expect(preview.text()).toMatch(/2 sep/)
    expect(preview.text()).toContain('Trabajó hasta el 2 según el cuadrante')
    expect(confirm.attributes('aria-disabled')).toBeUndefined()
  })

  it('al intentar confirmar sin motivo dice que falta y lleva el foco al campo, sin enviar nada', async () => {
    const spy = stubFetch(() => jsonResponse({}))
    const wrapper = await mountSection([PAST], { attach: true })

    await openCorrection(wrapper)
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-09-02')
    await settle(1)

    expect(dialog(wrapper).text()).not.toContain('Escribe al menos 10 caracteres.')

    // El boton bloqueado conserva el foco y el clic avisa, en lugar de quedarse mudo.
    await dialog(wrapper).find('[data-test="confirm-dialog-confirm"]').trigger('click')
    await settle(1)

    expect(dialog(wrapper).text()).toContain('Escribe al menos 10 caracteres.')
    expect(document.activeElement).toBe(dialog(wrapper).find('textarea').element)
    expect(spy).not.toHaveBeenCalled()
    wrapper.unmount()
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
    // El historico llega con la ficha releida, no se inventa aqui.
    expect(wrapper.find('[data-test="termination-correction"]').exists()).toBe(false)
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

    const wrapper = await mountSection([PAST], { attach: true })

    await openCorrection(wrapper)
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-09-02')
    await dialog(wrapper).find('textarea').setValue('Motivo suficientemente largo')
    await settle(1)
    await dialog(wrapper).find('[data-test="confirm-dialog-confirm"]').trigger('click')
    await settle()

    expect(dialog(wrapper).find('textarea').attributes('aria-invalid')).toBe('true')
    expect(dialog(wrapper).text()).toContain('El motivo es demasiado corto.')
    expect(dialog(wrapper).find('[data-test="employment-problem"]').exists()).toBe(false)
    // El foco va al campo que el servidor rechazo (el motivo), no al primero del dialogo.
    expect(document.activeElement).toBe(dialog(wrapper).find('textarea').element)

    wrapper.unmount()
  })

  it('tras un 422 de la fecha o del motivo, corregir el campo vuelve a habilitar «Registrar» y se puede reenviar', async () => {
    let attempts = 0

    stubCorrection(() => {
      attempts += 1

      return attempts === 1
        ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
            errors: {
              terminated_at: ['La fecha esta fuera de los limites.'],
              reason: ['El motivo no es valido.'],
            },
          })
        : jsonResponse(
            {
              id: 12,
              employment_period_uuid: PERIOD_UUID,
              previous_terminated_at: '2026-08-31',
              terminated_at: '2026-09-03',
              corrected_at: '2026-11-02T09:12:44.120Z',
            },
            201,
          )
    })

    const wrapper = await mountSection([PAST])

    await openCorrection(wrapper)
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-09-02')
    await dialog(wrapper).find('textarea').setValue('Motivo suficientemente largo')
    await settle(1)
    await dialog(wrapper).find('[data-test="confirm-dialog-confirm"]').trigger('click')
    await settle()

    const confirm = () => dialog(wrapper).find('[data-test="confirm-dialog-confirm"]')

    expect(confirm().attributes('aria-disabled')).toBeDefined()
    expect(dialog(wrapper).text()).toContain('La fecha esta fuera de los limites.')
    expect(dialog(wrapper).text()).toContain('El motivo no es valido.')

    // Editar un campo borra solo SU error del servidor; hasta corregir los dos sigue parado.
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-09-03')
    await settle(1)

    expect(dialog(wrapper).text()).not.toContain('La fecha esta fuera de los limites.')
    expect(dialog(wrapper).text()).toContain('El motivo no es valido.')
    expect(confirm().attributes('aria-disabled')).toBeDefined()

    await dialog(wrapper).find('textarea').setValue('Motivo suficientemente largo, corregido')
    await settle(1)

    expect(confirm().attributes('aria-disabled')).toBeUndefined()

    await confirm().trigger('click')
    await settle()

    expect(attempts).toBe(2)
    expect(wrapper.emitted('corrected')).toHaveLength(1)
  })

  it('un 422 de un campo que no tiene input se enseña en el aviso en lugar de perderse', async () => {
    stubCorrection(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: {
          terminated_at: ['Fuera de limites.'],
          periodo: ['El periodo no admite correccion.'],
        },
      }),
    )

    const wrapper = await mountSection([PAST])

    await openCorrection(wrapper)
    await dialog(wrapper).find<HTMLInputElement>('input[type="date"]').setValue('2026-09-02')
    await dialog(wrapper).find('textarea').setValue('Motivo suficientemente largo')
    await settle(1)
    await dialog(wrapper).find('[data-test="confirm-dialog-confirm"]').trigger('click')
    await settle()

    expect(dialog(wrapper).find('[role="alert"]').text()).toContain(
      'periodo: El periodo no admite correccion.',
    )
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

describe('EmploymentPeriodsSection: el historico con varias correcciones y varios periodos (RN-13, RN-27, RL-04)', () => {
  const MARTA = { ...terminationCorrection().corrected_by, name: 'Marta Soler' }

  /** Readmitida el 1 de octubre y de baja otra vez: cada periodo con sus correcciones del cese. */
  const PRIMERO = employmentPeriodRecord({
    terminated_at: '2026-09-03',
    corrections: [
      terminationCorrection({
        id: 1,
        previous_terminated_at: '2026-08-31',
        terminated_at: '2026-09-01',
        reason: 'Primera revision del cuadrante',
      }),
      terminationCorrection({
        id: 2,
        previous_terminated_at: '2026-09-01',
        terminated_at: '2026-09-05',
        reason: 'Segunda revision del cuadrante',
      }),
      terminationCorrection({
        id: 3,
        previous_terminated_at: '2026-09-05',
        terminated_at: '2026-09-03',
        corrected_by: MARTA,
        reason: 'Tercera revision del cuadrante',
      }),
    ],
  })
  const SEGUNDO = employmentPeriodRecord({
    uuid: SECOND_UUID,
    hired_at: '2026-10-01',
    terminated_at: '2026-10-20',
    corrections: [
      terminationCorrection({
        id: 4,
        previous_terminated_at: '2026-10-25',
        terminated_at: '2026-10-20',
        reason: 'El cese del segundo periodo era el 20',
      }),
    ],
  })

  it('cada periodo lleva debajo solo sus correcciones, en el orden en que se hicieron', async () => {
    const wrapper = await mountSection([PRIMERO, SEGUNDO])
    const grupos = wrapper.findAll('[data-test="termination-corrections"]')

    expect(grupos).toHaveLength(2)
    expect(
      grupos[0]
        ?.findAll('[data-test="termination-correction"]')
        .map((item) => item.find('.font-medium').text()),
    ).toEqual([
      'De 31 ago 2026 a 1 sept 2026',
      'De 1 sept 2026 a 5 sept 2026',
      'De 5 sept 2026 a 3 sept 2026',
    ])
    expect(
      grupos[1]
        ?.findAll('[data-test="termination-correction"]')
        .map((item) => item.find('.font-medium').text()),
    ).toEqual(['De 25 oct 2026 a 20 oct 2026'])
  })

  it('el historico de un periodo va justo detras de su fila, no al final de la tabla', async () => {
    const wrapper = await mountSection([PRIMERO, SEGUNDO])
    const filas = wrapper.findAll('tbody tr').map((row) => row.attributes('data-test'))

    expect(filas).toEqual([
      'employment-period',
      'termination-corrections',
      'employment-period',
      'termination-corrections',
    ])
  })

  it('cada correccion dice su autor: las de varias personas no se confunden', async () => {
    const wrapper = await mountSection([PRIMERO])
    const items = wrapper.findAll('[data-test="termination-correction"]')

    expect(items[1]?.text()).toContain('Direccion RRHH')
    expect(items[1]?.text()).not.toContain('Marta Soler')
    expect(items[2]?.text()).toContain('Marta Soler')
  })

  it('el momento de cada correccion sale en la zona del centro, tambien la noche del cambio de hora de octubre', async () => {
    // 25-10-2026: a las 00:30 UTC son las 02:30 CEST; a las 01:30 UTC, otra vez las 02:30, ya CET.
    const wrapper = await mountSection([
      employmentPeriodRecord({
        corrections: [
          terminationCorrection({ id: 1, corrected_at: '2026-10-25T00:30:00.000Z' }),
          terminationCorrection({ id: 2, corrected_at: '2026-10-25T01:30:00.000Z' }),
        ],
      }),
    ])
    const items = wrapper.findAll('[data-test="termination-correction"]')

    expect(items[0]?.text()).toContain('02:30')
    expect(items[0]?.text()).toContain('CEST')
    expect(items[1]?.text()).toContain('02:30')
    expect(items[1]?.text()).toContain('CET')
    expect(items[1]?.text()).not.toContain('CEST')
  })
})

describe('EmploymentPeriodsSection: el historico de una ficha minimizada (RN-13, RL-11, ADR-060)', () => {
  const MOTIVO_RETIRADO = 'Motivo retirado al minimizar la ficha (ADR-060, ADR-062)'

  const MINIMIZADO = employmentPeriodRecord({
    hired_at: '2010-01-01',
    terminated_at: '2012-12-20',
    corrections: [
      terminationCorrection({
        id: 1,
        previous_terminated_at: '2012-12-31',
        terminated_at: '2012-12-20',
        corrected_at: '2026-10-30T09:00:00.000Z',
        reason: MOTIVO_RETIRADO,
      }),
    ],
  })

  it('sigue enseñando las fechas, el autor, el momento y el texto fijo que sustituye al motivo', async () => {
    const wrapper = await mountSection([MINIMIZADO], { minimised: true })
    const item = wrapper.find('[data-test="termination-correction"]')

    expect(item.text()).toContain('De 31 dic 2012 a 20 dic 2012')
    expect(item.text()).toContain('Direccion RRHH')
    expect(item.text()).toContain('10:00')
    expect(item.text()).toContain(`Motivo: ${MOTIVO_RETIRADO}`)
  })

  it('no ofrece corregir, y la fila del historico ocupa las tres columnas que quedan', async () => {
    const wrapper = await mountSection([MINIMIZADO], { minimised: true })

    expect(wrapper.find('[data-test="correct-termination"]').exists()).toBe(false)
    expect(wrapper.findAll('thead th[scope="col"]')).toHaveLength(3)
    expect(wrapper.find('[data-test="termination-corrections"] td').attributes('colspan')).toBe('3')
  })
})
