// Reglas puras de la baja programada y la readmision (RF-GP-03, RN-14, RN-27,
// ADR-062): la tabla de `POST /employees/{uuid}/offboard`, los avisos de fecha del
// propietario (31-10-2026), la aritmetica de fechas civiles y el catalogo de `409`.
import { describe, expect, it } from 'vitest'
import { ApiError } from '@kronoqr/web-kit/http'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { addCivilDays } from '@/features/employees/employmentDates'
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

  const ZONE = 'Europe/Madrid'

  it('la baja efectiva hoy en la zona del centro pide esperar a los quioscos, sea cual sea su cese', () => {
    // 22:30 UTC del 1 de octubre ya es el 2 en Madrid (CEST); 21:30 UTC del 1, aun el 1.
    const effective = (offboardedAt: string | null) =>
      isRecentTermination({ offboardedAt, today: TODAY, timezone: ZONE })

    expect(effective('2026-10-01T22:30:00Z')).toBe(true)
    expect(effective('2026-10-02T08:00:00Z')).toBe(true)
    expect(effective('2026-10-01T21:30:00Z')).toBe(false)
    // Lo que cuenta es cuando se hizo efectiva, no su fecha de cese (que esta ni se mira): una baja
    // retroactiva registrada esta mañana avisa, y una de cese de hoy registrada hace tiempo no.
    expect(effective('2026-09-30T08:00:00Z')).toBe(false)
    // Una baja que no es efectiva (o que la ficha no trae) no hay nada que esperar.
    expect(effective(null)).toBe(false)
  })

  it('sin la zona del centro no adivina con un instante que si conoce', () => {
    expect(
      isRecentTermination({
        offboardedAt: '2026-10-02T08:00:00Z',
        today: TODAY,
        timezone: undefined,
      }),
    ).toBe(false)
  })

  it('una fecha de alta que no pasa del cese se detecta antes de enviar', () => {
    const input = { terminatedAt: '2026-10-15', today: TODAY, timezone: ZONE, offboardedAt: null }

    expect(rehireDateWarnings({ ...input, hiredAt: '2026-10-15' }).notAfterTermination).toBe(true)
    expect(rehireDateWarnings({ ...input, hiredAt: '2026-10-16' }).notAfterTermination).toBe(false)
    expect(rehireDateWarnings({ ...input, hiredAt: '' }).notAfterTermination).toBe(false)
    expect(
      rehireDateWarnings({
        terminatedAt: null,
        today: TODAY,
        timezone: ZONE,
        offboardedAt: null,
        hiredAt: '2026-10-16',
      }).notAfterTermination,
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
    expect(() => addCivilDays('', 1)).toThrow(RangeError)
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

describe('isRecentTermination en el borde de medianoche de la zona del centro (ADR-040, RN-27)', () => {
  const MADRID = 'Europe/Madrid'

  it.each([
    // 2 de octubre, CEST (UTC+2): el dia va de las 22:00 UTC del 1 a las 21:59:59.999 UTC del 2.
    { hoy: '2026-10-02', instante: '2026-10-01T21:59:59.999Z', esDeHoy: false },
    { hoy: '2026-10-02', instante: '2026-10-01T22:00:00.000Z', esDeHoy: true },
    { hoy: '2026-10-02', instante: '2026-10-02T21:59:59.999Z', esDeHoy: true },
    { hoy: '2026-10-02', instante: '2026-10-02T22:00:00.000Z', esDeHoy: false },
    // 25 de octubre, cambio de hora: empieza en CEST (22:00 UTC del 24) y acaba en CET (23:00 UTC del 25).
    { hoy: '2026-10-25', instante: '2026-10-24T21:59:59.999Z', esDeHoy: false },
    { hoy: '2026-10-25', instante: '2026-10-24T22:00:00.000Z', esDeHoy: true },
    { hoy: '2026-10-25', instante: '2026-10-25T22:59:59.999Z', esDeHoy: true },
    { hoy: '2026-10-25', instante: '2026-10-25T23:00:00.000Z', esDeHoy: false },
    // 29 de marzo, cambio de hora: empieza en CET (23:00 UTC del 28) y acaba en CEST (22:00 UTC del 29).
    { hoy: '2026-03-29', instante: '2026-03-28T22:59:59.999Z', esDeHoy: false },
    { hoy: '2026-03-29', instante: '2026-03-28T23:00:00.000Z', esDeHoy: true },
    { hoy: '2026-03-29', instante: '2026-03-29T21:59:59.999Z', esDeHoy: true },
    { hoy: '2026-03-29', instante: '2026-03-29T22:00:00.000Z', esDeHoy: false },
  ])(
    'una baja efectiva a las $instante es de hoy ($hoy) en Madrid: $esDeHoy',
    ({ hoy, instante, esDeHoy }) => {
      expect(
        isRecentTermination({
          offboardedAt: instante,
          today: hoy,
          timezone: MADRID,
        }),
      ).toBe(esDeHoy)
    },
  )

  it('la fecha civil es la de la zona del centro que se le da, no la de Madrid ni la del navegador', () => {
    // 22:30 UTC del 1 de octubre: el 2 en Madrid, todavia el 1 en Canarias.
    const enZona = (timezone: string) =>
      isRecentTermination({
        offboardedAt: '2026-10-01T22:30:00Z',
        today: '2026-10-02',
        timezone,
      })

    expect(enZona('Europe/Madrid')).toBe(true)
    expect(enZona('Atlantic/Canary')).toBe(false)
  })
})
