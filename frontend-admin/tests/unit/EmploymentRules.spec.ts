// Reglas puras de la baja programada y la readmision (RF-GP-03, RN-14, RN-27,
// ADR-062): la tabla de `POST /employees/{uuid}/offboard`, los avisos de fecha del
// propietario (31-10-2026), la aritmetica de fechas civiles y el catalogo de `409`.
import { describe, expect, it } from 'vitest'
import { ApiError } from '@kronoqr/web-kit/http'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { addCivilDays, isCivilDate } from '@/features/employees/employmentDates'
import {
  EMPLOYMENT_PROBLEM_TYPES,
  employmentProblemKey,
  rehirableEmployeeUuid,
  staleProblemKey,
} from '@/features/employees/employmentProblems'
import { parsePositiveHours } from '@/features/employees/employmentLimits'
import { applyNowApplies, planOffboard } from '@/features/employees/offboardPlan'
import {
  isFutureHire,
  isRecentTermination,
  rehireDateWarnings,
} from '@/features/employees/rehirePlan'

const TODAY = '2026-10-02'

function conflict(type: string, extra: Record<string, unknown> = {}): ApiError {
  return new ApiError({
    kind: 'conflict',
    status: 409,
    problem: { type, title: 'Conflicto', status: 409, ...extra },
  })
}

describe('planOffboard: la tabla de la baja (RN-14, ADR-062 §3)', () => {
  const base = { hiredAt: '2026-08-14', today: TODAY }

  it.each([
    ['anterior al alta', '2026-08-01', false, 'beforeHire'],
    ['anterior a hoy', '2026-09-30', false, 'immediate'],
    ['anterior a hoy, con «Aplicar ya»', '2026-09-30', true, 'immediate'],
    ['hoy', TODAY, false, 'scheduled'],
    ['hoy con «Aplicar ya»', TODAY, true, 'immediate'],
    ['posterior a hoy', '2026-10-31', false, 'scheduled'],
    [
      'posterior a hoy con «Aplicar ya» (el servidor responde 422)',
      '2026-10-31',
      true,
      'applyNowInFuture',
    ],
  ] as const)('cese %s', (_caso, terminatedAt, applyNow, expected) => {
    expect(planOffboard({ ...base, terminatedAt, applyNow })).toBe(expected)
  })

  it('el alta futura con el cese en esa misma fecha se programa, y con «Aplicar ya» es una baja sin efectos', () => {
    const future = { hiredAt: '2026-10-15', today: TODAY, terminatedAt: '2026-10-15' }

    expect(planOffboard({ ...future, applyNow: false })).toBe('scheduled')
    expect(planOffboard({ ...future, applyNow: true })).toBe('noEffect')
  })

  it('sin la zona del centro o sin fecha no adivina: deja decidir al servidor', () => {
    expect(planOffboard({ ...base, today: '', terminatedAt: TODAY, applyNow: false })).toBe(
      'unknown',
    )
    expect(planOffboard({ ...base, terminatedAt: '', applyNow: false })).toBe('unknown')
  })

  it('«Aplicar ya» solo se ofrece cuando tiene efecto', () => {
    expect(applyNowApplies({ ...base, terminatedAt: TODAY })).toBe(true)
    expect(applyNowApplies({ ...base, terminatedAt: '2026-09-30' })).toBe(false)
    expect(applyNowApplies({ ...base, terminatedAt: '2026-10-31' })).toBe(false)
    expect(
      applyNowApplies({ hiredAt: '2026-10-15', today: TODAY, terminatedAt: '2026-10-15' }),
    ).toBe(true)
    expect(applyNowApplies({ ...base, today: '', terminatedAt: TODAY })).toBe(false)
  })
})

describe('avisos de fecha de un alta o una readmision (decision del 31-10-2026)', () => {
  it('una fecha de alta futura avisa de que los fichajes anteriores se rechazan', () => {
    expect(isFutureHire('2026-10-15', TODAY)).toBe(true)
    expect(isFutureHire(TODAY, TODAY)).toBe(false)
    expect(isFutureHire('', TODAY)).toBe(false)
    expect(isFutureHire('2026-10-15', '')).toBe(false)
  })

  it('la baja de hoy (o de ayer, aplicada hoy por la tarea) pide esperar a los quioscos', () => {
    expect(isRecentTermination(TODAY, TODAY)).toBe(true)
    expect(isRecentTermination('2026-10-01', TODAY)).toBe(true)
    expect(isRecentTermination('2026-09-30', TODAY)).toBe(false)
    expect(isRecentTermination(null, TODAY)).toBe(false)
    expect(isRecentTermination(TODAY, '')).toBe(false)
  })

  it('una fecha de alta que no pasa del cese se detecta antes de enviar', () => {
    const input = { terminatedAt: '2026-10-15', today: TODAY }

    expect(rehireDateWarnings({ ...input, hiredAt: '2026-10-15' }).notAfterTermination).toBe(true)
    expect(rehireDateWarnings({ ...input, hiredAt: '2026-10-16' }).notAfterTermination).toBe(false)
    expect(rehireDateWarnings({ ...input, hiredAt: '' }).notAfterTermination).toBe(false)
    expect(
      rehireDateWarnings({ terminatedAt: null, today: TODAY, hiredAt: '2026-10-16' })
        .notAfterTermination,
    ).toBe(false)
  })
})

describe('fechas civiles sin zona', () => {
  it('suma y resta dias por calendario, con meses, años y bisiestos', () => {
    expect(addCivilDays('2026-10-31', 1)).toBe('2026-11-01')
    expect(addCivilDays('2026-01-01', -1)).toBe('2025-12-31')
    expect(addCivilDays('2028-02-28', 1)).toBe('2028-02-29')
    expect(addCivilDays('2026-03-29', 1)).toBe('2026-03-30')
  })

  it('rechaza lo que no es una fecha civil', () => {
    expect(() => addCivilDays('02/10/2026', 1)).toThrow(RangeError)
    expect(isCivilDate('2026-02-30')).toBe(false)
    expect(isCivilDate('2026-10-02')).toBe(true)
    expect(isCivilDate('')).toBe(false)
  })

  it('las horas del contrato admiten coma decimal y dentro de rango', () => {
    expect(parsePositiveHours('37,5', 168)).toBe(37.5)
    expect(parsePositiveHours(' 40 ', 168)).toBe(40)
    expect(parsePositiveHours('0', 168)).toBeNull()
    expect(parsePositiveHours('169', 168)).toBeNull()
    expect(parsePositiveHours('abc', 168)).toBeNull()
    expect(parsePositiveHours('', 168)).toBeNull()
  })
})

describe('los 409 de la baja, la readmision y la correccion (RN-14, RN-27)', () => {
  it.each(Object.entries(EMPLOYMENT_PROBLEM_TYPES))(
    'reconoce la causa %s por su type',
    (key, type) => {
      expect(employmentProblemKey(conflict(type))).toBe(key)
    },
  )

  it('no confunde un 409 generico, un 422 ni un fallo que no es de la API', () => {
    expect(employmentProblemKey(conflict('urn:kronoqr:problem:conflict'))).toBeNull()
    expect(
      employmentProblemKey(
        new ApiError({
          kind: 'validation',
          status: 422,
          problem: { type: EMPLOYMENT_PROBLEM_TYPES.employeeTerminated, title: 'x', status: 422 },
        }),
      ),
    ).toBeNull()
    expect(employmentProblemKey(new TypeError('Failed to fetch'))).toBeNull()
  })

  it('solo estas causas dicen que la ficha ya no esta como se veia', () => {
    expect(staleProblemKey(conflict(EMPLOYMENT_PROBLEM_TYPES.employeeTerminated))).toBe(
      'employeeTerminated',
    )
    expect(staleProblemKey(conflict(EMPLOYMENT_PROBLEM_TYPES.offboardingNotScheduled))).toBe(
      'offboardingNotScheduled',
    )
    expect(staleProblemKey(conflict(EMPLOYMENT_PROBLEM_TYPES.employeeNotTerminated))).toBe(
      'employeeNotTerminated',
    )
    expect(staleProblemKey(conflict(EMPLOYMENT_PROBLEM_TYPES.offboardingNotEffective))).toBe(
      'offboardingNotEffective',
    )
    // «La fecha ya paso» y «ficha minimizada» no se arreglan releyendo: se explican.
    expect(staleProblemKey(conflict(EMPLOYMENT_PROBLEM_TYPES.offboardingDue))).toBeNull()
    expect(staleProblemKey(conflict(EMPLOYMENT_PROBLEM_TYPES.recordMinimised))).toBeNull()
  })

  it.each(Object.keys(EMPLOYMENT_PROBLEM_TYPES))(
    'la causa %s tiene «que ha pasado» y «que hacer» en español y en inglés',
    (key) => {
      for (const catalogue of [es, en]) {
        const entry = (
          catalogue.employees.problems as Record<string, { title: string; advice: string }>
        )[key]

        expect(entry?.title.trim()).toBeTruthy()
        expect(entry?.advice.trim()).toBeTruthy()
      }
    },
  )

  it('el 409 del alta con el documento de una persona de baja ofrece su uuid, solo si lo trae', () => {
    const uuid = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'

    expect(
      rehirableEmployeeUuid(
        conflict('urn:kronoqr:problem:conflict', { rehirable_employee_uuid: uuid }),
      ),
    ).toBe(uuid)
    expect(rehirableEmployeeUuid(conflict('urn:kronoqr:problem:conflict'))).toBeNull()
    expect(
      rehirableEmployeeUuid(
        conflict('urn:kronoqr:problem:conflict', { rehirable_employee_uuid: 42 }),
      ),
    ).toBeNull()
    expect(rehirableEmployeeUuid(new TypeError('x'))).toBeNull()
  })
})
