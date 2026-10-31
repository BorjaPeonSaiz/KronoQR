// Los `409` de la baja, la readmision y la correccion del cese (RN-14, RN-27,
// ADR-062), cada uno con su «que hacer».
//
// El `type` del problema distingue las causas (contrato: `OffboardConflict`,
// `ScheduledOffboardingConflict`, `RehireConflict`, `TerminationCorrectionConflict`).
// El genero `conflict` de `ErrorNotice` dice «alguien puede haberlo cambiado»,
// que es cierto pero no ayuda a RRHH a decidir; estas causas tienen una salida
// concreta (readmitir en vez de anular, corregir la fecha en vez de cambiarla) y
// la pantalla la dice. `problem.detail` no se enseña: es para quien depura.
import { isApiError } from '@kronoqr/web-kit/http'

export const EMPLOYMENT_PROBLEM_TYPES = {
  employeeTerminated: 'urn:kronoqr:problem:employee-terminated',
  offboardingDue: 'urn:kronoqr:problem:offboarding-due',
  offboardingNotScheduled: 'urn:kronoqr:problem:offboarding-not-scheduled',
  offboardingNotEffective: 'urn:kronoqr:problem:offboarding-not-effective',
  employeeNotTerminated: 'urn:kronoqr:problem:employee-not-terminated',
  recordMinimised: 'urn:kronoqr:problem:employee-record-minimised',
} as const

export type EmploymentProblemKey = keyof typeof EMPLOYMENT_PROBLEM_TYPES

/** Causa del `409` por su `type`, o `null` si no es una de estas (entonces vale el aviso general). */
export function employmentProblemKey(error: unknown): EmploymentProblemKey | null {
  if (!isApiError(error) || error.status !== 409 || error.problem === null) {
    return null
  }

  const type = error.problem.type
  const found = (
    Object.entries(EMPLOYMENT_PROBLEM_TYPES) as Array<[EmploymentProblemKey, string]>
  ).find(([, candidate]) => candidate === type)

  return found?.[0] ?? null
}

/**
 * La causa, si dice que la ficha ya no esta como la pantalla la enseñaba: quien llama
 * la vuelve a leer, porque la accion pedida ya no tiene sentido sobre el estado viejo.
 * `null` para cualquier otro fallo (un 409 de otra causa, un 422, la red...).
 */
export function staleProblemKey(error: unknown): EmploymentProblemKey | null {
  const key = employmentProblemKey(error)

  return key === 'employeeTerminated' ||
    key === 'offboardingNotScheduled' ||
    key === 'employeeNotTerminated' ||
    key === 'offboardingNotEffective'
    ? key
    : null
}

/** `rehirable_employee_uuid` del `409` de un alta con el documento de una persona de baja (RN-27). */
export function rehirableEmployeeUuid(error: unknown): string | null {
  if (!isApiError(error) || error.status !== 409 || error.problem === null) {
    return null
  }

  const candidate = (error.problem as unknown as Record<string, unknown>)['rehirable_employee_uuid']

  return typeof candidate === 'string' && candidate !== '' ? candidate : null
}
