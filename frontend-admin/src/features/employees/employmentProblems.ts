// Los `409` de la baja, la readmision y la correccion del cese (RN-14, RN-27,
// ADR-062), cada uno con su «que hacer».
//
// El `type` del problema distingue las causas (contrato: `OffboardConflict`,
// `ScheduledOffboardingConflict`, `RehireConflict`, `TerminationCorrectionConflict`).
// El genero `conflict` de `ErrorNotice` dice «alguien puede haberlo cambiado»,
// que es cierto pero no ayuda a RRHH a decidir; estas causas tienen una salida
// concreta (readmitir en vez de anular, corregir la fecha en vez de cambiarla) y
// la pantalla la dice. `problem.detail` no se enseña: es para quien depura.
import { ApiError, type FieldErrors, isApiError, type Problem } from '@kronoqr/web-kit/http'

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

  const { rehirable_employee_uuid: candidate } = error.problem as Problem & {
    rehirable_employee_uuid?: unknown
  }

  return typeof candidate === 'string' && candidate !== '' ? candidate : null
}

/** Los errores por campo de un `422`, tal como los escribio el servidor; vacio para cualquier otro fallo. */
export function fieldErrorsOf(error: unknown): FieldErrors {
  return isApiError(error) && error.status === 422 ? error.fieldErrors : {}
}

/**
 * Los errores de campo de un `422` que el dialogo NO coloca junto a un campo (`placed` son los
 * que si): `department_id`, `contract.*`, un `reason` cuando el campo del motivo no tiene sitio
 * para el error... Si no se enseñan en el aviso, el usuario ve el boton parado sin saber por que.
 */
export function unplacedFieldErrors(error: unknown, placed: readonly string[]): FieldErrors {
  return Object.fromEntries(
    Object.entries(fieldErrorsOf(error)).filter(([field]) => !placed.includes(field)),
  )
}

/**
 * Lo que debe enseñar el aviso del dialogo: `null` si el fallo es un `422` con campos y todos
 * estan ya junto a su campo (decirlo dos veces es ruido); el propio fallo en cualquier otro caso.
 */
export function noticeError(error: unknown, placed: readonly string[]): unknown {
  const fields = fieldErrorsOf(error)

  return Object.keys(fields).length > 0 &&
    Object.keys(unplacedFieldErrors(error, placed)).length === 0
    ? null
    : error
}

/** Copia del fallo con solo los errores de campo que no estan junto a su campo. */
export function withUnplacedFields(error: unknown, placed: readonly string[]): unknown {
  if (!isApiError(error) || error.status !== 422) {
    return error
  }

  return new ApiError({
    kind: error.kind,
    status: error.status,
    problem: error.problem,
    fieldErrors: unplacedFieldErrors(error, placed),
    retryAfterSeconds: error.retryAfterSeconds,
  })
}
