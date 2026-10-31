// Doble con estado de la baja programada, la readmision y la correccion del cese
// (RN-14, RN-27, ADR-062), para los E2E de `employment-lifecycle.spec.ts`.
//
// Se registra DESPUES de `stubManagementApi`: Playwright prueba primero la ruta
// registrada la ultima, y lo que este doble no conoce lo devuelve con
// `route.fallback()` al doble general. Reproduce las reglas del contrato que la
// interfaz tiene que respetar (la tabla de `POST /offboard`, los `409` por causa,
// el todo-o-nada del lote, los limites de la correccion), no la logica del dominio,
// que prueba el backend (`OffboardEmployeeTest`, `RehireEmployeeTest`,
// `TerminationDateCorrectionTest`...). El backend no participa.
import type { Page, Route } from '@playwright/test'
import type {
  Employee,
  EmploymentPeriod,
  EmploymentPeriodCorrection,
  IssuedPin,
} from '@/shared/api/types'
import type { RecordedRequest } from './admin'

/** «Hoy» en la zona del centro, como lo calcula el servidor (ADR-040). */
export function todayInCenter(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Madrid',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now)
}

/** La fecha civil `days` dias despues de `date`, sin pasar por la zona del navegador. */
export function addDays(date: string, days: number): string {
  const [year, month, day] = date.split('-').map(Number)
  const shifted = new Date(Date.UTC(year ?? 1970, (month ?? 1) - 1, (day ?? 1) + days))

  return shifted.toISOString().slice(0, 10)
}

/** Una persona del doble: su ficha, sus periodos y sus correcciones. */
export interface StubPerson {
  employee: Employee
  periods: EmploymentPeriod[]
  corrections: EmploymentPeriodCorrection[]
  /** Si el servidor la rechaza en un lote (`422` en su posicion), p. ej. porque ya no esta de baja. */
  rejectInBatch?: string
}

export interface EmploymentStub {
  /** Todas las peticiones a `/api/v1/*`, en orden. */
  readonly requests: RecordedRequest[]
  person(uuid: string): StubPerson
}

let periodSeed = 0
let credentialSeed = 0

function periodUuid(): string {
  periodSeed += 1

  return `0199f0e3-${String(periodSeed).padStart(4, '0')}-7000-8000-000000000001`
}

function credentialUuid(): string {
  credentialSeed += 1

  return `0199f0e2-${String(credentialSeed).padStart(4, '0')}-7000-8000-000000000001`
}

/** Una persona en alta desde `hiredAt`, con un solo periodo abierto. */
export function activePerson(uuid: string, firstName: string, hiredAt = '2026-03-16'): StubPerson {
  return {
    employee: {
      uuid,
      employee_code: `COD${firstName.toUpperCase()}`,
      first_name: firstName,
      last_name: 'Prueba',
      email: null,
      department_id: 3,
      status: 'active',
      hired_at: hiredAt,
      terminated_at: null,
      locale: 'es',
      teleworking: false,
      pin_status: 'delivered',
      anonymized_at: null,
      offboarding: null,
    },
    periods: [{ uuid: periodUuid(), hired_at: hiredAt, terminated_at: null, state: 'active' }],
    corrections: [],
  }
}

/** Una persona de baja desde `terminatedAt`, con su periodo cerrado. */
export function formerPerson(
  uuid: string,
  firstName: string,
  options: { hiredAt?: string; terminatedAt?: string } = {},
): StubPerson {
  const hiredAt = options.hiredAt ?? '2026-03-16'
  const terminatedAt = options.terminatedAt ?? '2026-08-31'
  const base = activePerson(uuid, firstName, hiredAt)

  return {
    ...base,
    employee: {
      ...base.employee,
      status: 'terminated',
      terminated_at: terminatedAt,
      offboarding: { state: 'effective', terminated_at: terminatedAt },
    },
    periods: [
      { uuid: periodUuid(), hired_at: hiredAt, terminated_at: terminatedAt, state: 'terminated' },
    ],
  }
}

/** El PIN nuevo que el doble emite con `reset_pin` (se ve una sola vez). */
export const NEW_PIN = '654321'

const PDF_HEADERS = { 'Cache-Control': 'no-store, private' }

export async function stubEmploymentApi(page: Page, people: StubPerson[]): Promise<EmploymentStub> {
  const requests: RecordedRequest[] = []
  const state = new Map(people.map((person) => [person.employee.uuid, person]))
  /** uuid de la credencial pendiente de cada persona readmitida. */
  const credentials = new Map<string, string>()

  function find(uuid: string): StubPerson {
    const person = state.get(uuid)

    if (person === undefined) {
      throw new Error(`Persona sin doble: ${uuid}`)
    }

    return person
  }

  function current(person: StubPerson): EmploymentPeriod {
    const last = person.periods.at(-1)

    if (last === undefined) {
      throw new Error('Persona sin periodos')
    }

    return last
  }

  async function json(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  }

  async function problem(route: Route, status: number, type: string): Promise<void> {
    await route.fulfill({
      status,
      contentType: 'application/problem+json',
      body: JSON.stringify({ type, title: 'Problema', status }),
    })
  }

  async function invalid(route: Route, errors: Record<string, string[]>): Promise<void> {
    await route.fulfill({
      status: 422,
      contentType: 'application/problem+json',
      body: JSON.stringify({
        type: 'urn:kronoqr:problem:validation-failed',
        title: 'Peticion no valida',
        status: 422,
        errors,
      }),
    })
  }

  function withPeriods(person: StubPerson): Employee & { employment_periods: EmploymentPeriod[] } {
    return {
      ...person.employee,
      employment_periods: person.periods.map((period) => ({ ...period })),
    }
  }

  /** Aplica la readmision a una persona y devuelve el elemento de `RehiredEmployee`. */
  function rehire(
    person: StubPerson,
    body: { hired_at: string; department_id?: number | null; locale?: string; reset_pin?: boolean },
  ): unknown {
    const periodId = periodUuid()
    const credential = credentialUuid()

    credentials.set(person.employee.uuid, credential)
    person.employee = {
      ...person.employee,
      status: 'active',
      hired_at: body.hired_at,
      terminated_at: null,
      offboarding: null,
      ...(body.department_id === undefined ? {} : { department_id: body.department_id }),
      ...(body.locale === undefined ? {} : { locale: body.locale }),
    }
    const period: EmploymentPeriod = {
      uuid: periodId,
      hired_at: body.hired_at,
      terminated_at: null,
      state: 'active',
    }

    person.periods.push(period)

    const pin: IssuedPin | undefined =
      body.reset_pin === true
        ? {
            employee_uuid: person.employee.uuid,
            pin: NEW_PIN,
            issued_at: '2026-11-02T09:00:00.000000Z',
            pin_status: 'issued',
          }
        : undefined

    return {
      employee: person.employee,
      employment_period: period,
      credential: { uuid: credential, status: 'pending_print' },
      ...(pin === undefined ? {} : { pin }),
    }
  }

  await page.route(
    (url) =>
      url.pathname.startsWith('/api/v1/employees') ||
      url.pathname.startsWith('/api/v1/credentials/'),
    async (route) => {
      const request = route.request()
      const url = new URL(request.url())
      const method = request.method()
      const path = url.pathname
      let body: Record<string, unknown> = {}

      try {
        body = (request.postDataJSON() ?? {}) as Record<string, unknown>
      } catch {
        body = {}
      }

      const detail = /^\/api\/v1\/employees\/([0-9a-f-]{36})(\/.*)?$/.exec(path)
      const uuid = detail?.[1]
      const suffix = detail?.[2] ?? ''
      const known = uuid !== undefined && state.has(uuid)

      const mine =
        (method === 'GET' && path === '/api/v1/employees') ||
        (method === 'POST' && path === '/api/v1/employees/rehire') ||
        (known && suffix === '' && method === 'GET') ||
        (known && suffix === '/contracts' && method === 'GET') ||
        (known &&
          method === 'POST' &&
          ['/offboard', '/offboard/cancel', '/rehire'].includes(suffix)) ||
        (known && method === 'POST' && suffix.endsWith('/termination-corrections')) ||
        (method === 'POST' &&
          /^\/api\/v1\/credentials\/(print-batch|[0-9a-f-]{36}\/print)$/.test(path))

      if (!mine) {
        await route.fallback()

        return
      }

      requests.push({
        method,
        path,
        authorization: request.headers()['authorization'],
        query: url.search.slice(1),
        body: Object.keys(body).length === 0 ? null : body,
      })

      // --- Lectura -----------------------------------------------------------
      if (method === 'GET' && path === '/api/v1/employees') {
        const status = url.searchParams.get('status')
        const data = [...state.values()]
          .map((person) => person.employee)
          .filter((employee) => status === null || employee.status === status)

        await json(route, 200, {
          data,
          meta: { page: 1, per_page: 30, total: data.length, total_pages: 1 },
        })

        return
      }

      if (method === 'GET' && uuid !== undefined && suffix === '') {
        await json(route, 200, withPeriods(find(uuid)))

        return
      }

      if (method === 'GET' && suffix === '/contracts') {
        await json(route, 200, { data: [] })

        return
      }

      // --- Impresion -----------------------------------------------------------
      if (path.startsWith('/api/v1/credentials/')) {
        const batch = path.endsWith('/print-batch')

        await route.fulfill({
          status: 200,
          contentType: 'application/pdf',
          headers: {
            ...PDF_HEADERS,
            'Content-Disposition': `attachment; filename="${batch ? 'credenciales' : 'credencial'}.pdf"`,
            ...(batch ? { 'X-Kronoqr-Printed-Count': String(credentials.size) } : {}),
          },
          body: Buffer.from('%PDF-1.7 tarjeta de prueba'),
        })

        return
      }

      // --- Lote ------------------------------------------------------------------
      if (path === '/api/v1/employees/rehire') {
        const uuids = (body['employee_uuids'] as string[] | undefined) ?? []
        const hiredAt = String(body['hired_at'])
        const errors: Record<string, string[]> = {}

        uuids.forEach((candidate, index) => {
          const person = state.get(candidate)

          if (person === undefined || person.employee.status !== 'terminated') {
            errors[`employee_uuids.${index}`] = ['Esta persona no está de baja.']
          } else if (person.rejectInBatch !== undefined) {
            errors[`employee_uuids.${index}`] = [person.rejectInBatch]
          } else if (
            person.employee.terminated_at !== null &&
            hiredAt <= person.employee.terminated_at
          ) {
            errors[`employee_uuids.${index}`] = [
              `La fecha de alta (${hiredAt}) tiene que ser posterior a la fecha de cese.`,
            ]
          }
        })

        if (Object.keys(errors).length > 0) {
          // Todo o nada: no se escribe nada.
          await invalid(route, errors)

          return
        }

        await json(route, 200, {
          rehired: uuids.map((candidate) =>
            rehire(find(candidate), {
              hired_at: hiredAt,
              reset_pin: body['reset_pin'] === true,
            }),
          ),
        })

        return
      }

      if (uuid === undefined) {
        await route.fallback()

        return
      }

      const person = find(uuid)
      const today = todayInCenter()

      // --- Baja, en el acto o programada (RN-14) -----------------------------------
      if (suffix === '/offboard') {
        const terminatedAt = String(body['terminated_at'])
        const applyNow = body['apply_now'] === true
        const hiredAt = person.employee.hired_at

        if (person.employee.status === 'terminated') {
          await problem(route, 409, 'urn:kronoqr:problem:employee-terminated')

          return
        }

        if (terminatedAt < hiredAt) {
          await invalid(route, { terminated_at: ['La fecha de cese es anterior a la de alta.'] })

          return
        }

        if (applyNow && terminatedAt > today && !(hiredAt > today && terminatedAt === hiredAt)) {
          await invalid(route, {
            apply_now: ['«Aplicar ya» solo vale con una fecha de cese de hoy o anterior.'],
          })

          return
        }

        const effective =
          terminatedAt < today ||
          (applyNow && (terminatedAt === today || (hiredAt > today && terminatedAt === hiredAt)))
        const open = current(person)

        open.terminated_at = terminatedAt
        open.state = effective ? 'terminated' : 'scheduled'
        person.employee = {
          ...person.employee,
          status: effective ? 'terminated' : 'active',
          terminated_at: terminatedAt,
          offboarding: {
            state: effective ? 'effective' : 'scheduled',
            terminated_at: terminatedAt,
          },
        }

        await json(route, 200, person.employee)

        return
      }

      if (suffix === '/offboard/cancel') {
        if (person.employee.status === 'terminated') {
          await problem(route, 409, 'urn:kronoqr:problem:employee-terminated')

          return
        }

        if (person.employee.offboarding?.state !== 'scheduled') {
          await problem(route, 409, 'urn:kronoqr:problem:offboarding-not-scheduled')

          return
        }

        const open = current(person)

        open.terminated_at = null
        open.state = 'active'
        person.employee = {
          ...person.employee,
          terminated_at: null,
          offboarding: null,
        }

        await json(route, 200, person.employee)

        return
      }

      // --- Readmision (RN-27) ---------------------------------------------------------
      if (suffix === '/rehire') {
        const hiredAt = String(body['hired_at'])

        if (person.employee.status !== 'terminated') {
          await problem(route, 409, 'urn:kronoqr:problem:employee-not-terminated')

          return
        }

        if (person.employee.terminated_at !== null && hiredAt <= person.employee.terminated_at) {
          await invalid(route, {
            hired_at: [
              `La fecha de alta (${hiredAt}) tiene que ser posterior a la fecha de cese (${person.employee.terminated_at}).`,
            ],
          })

          return
        }

        await json(
          route,
          200,
          rehire(person, {
            hired_at: hiredAt,
            reset_pin: body['reset_pin'] === true,
            ...(body['department_id'] === undefined
              ? {}
              : { department_id: body['department_id'] as number | null }),
            ...(typeof body['locale'] === 'string' ? { locale: body['locale'] } : {}),
          }),
        )

        return
      }

      // --- Correccion de la fecha de cese (RN-27, RL-04) ---------------------------------
      const correctionPeriodUuid =
        /\/employment-periods\/([0-9a-f-]{36})\/termination-corrections$/.exec(suffix)?.[1]
      const period = person.periods.find((candidate) => candidate.uuid === correctionPeriodUuid)

      if (period === undefined) {
        await problem(route, 404, 'urn:kronoqr:problem:not-found')

        return
      }

      if (period.state !== 'terminated' || period.terminated_at === null) {
        await problem(route, 409, 'urn:kronoqr:problem:offboarding-not-effective')

        return
      }

      const next = person.periods[person.periods.indexOf(period) + 1]
      const terminatedAt = String(body['terminated_at'])
      const reason = String(body['reason'] ?? '')

      if (reason.length < 10 || reason.length > 1000) {
        await invalid(route, { reason: ['El motivo tiene que tener entre 10 y 1000 caracteres.'] })

        return
      }

      if (
        terminatedAt > today ||
        terminatedAt < period.hired_at ||
        (next !== undefined && terminatedAt >= next.hired_at)
      ) {
        await invalid(route, { terminated_at: ['La fecha de cese está fuera de los límites.'] })

        return
      }

      const correction: EmploymentPeriodCorrection = {
        id: person.corrections.length + 1,
        employment_period_uuid: period.uuid,
        previous_terminated_at: period.terminated_at,
        terminated_at: terminatedAt,
        corrected_at: '2026-11-02T09:12:44.120Z',
      }

      person.corrections.push(correction)
      period.terminated_at = terminatedAt

      if (next === undefined) {
        person.employee = {
          ...person.employee,
          terminated_at: terminatedAt,
          offboarding: { state: 'effective', terminated_at: terminatedAt },
        }
      }

      await json(route, 201, correction)
    },
  )

  return { requests, person: find }
}
