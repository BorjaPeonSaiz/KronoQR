// Dobles de la API de gestion para el E2E del panel.
//
// Cada respuesta tiene la forma del contrato (`import type` de `schema.d.ts`:
// si el contrato cambia, esto deja de compilar antes de que la prueba mienta).
// El backend no participa: aqui se prueba el recorrido por el panel, y lo que
// el servidor autoriza o deniega se prueba en el backend (regla dura 18).
//
// Los datos son los del ejemplo del contrato y de las pruebas unitarias: un
// turno del 14 de marzo de 2026 en `Europe/Madrid` al que faltaba la salida y
// que RRHH cerro a las 14:05.
import type { Page, Route } from '@playwright/test'
import type {
  CredentialStatusBoard,
  DepartmentCollection,
  Employee,
  EmployeeCollection,
  EmployeeWorkDays,
  Incident,
  IncidentCollection,
  LivePresenceBoard,
  LivePresenceEntry,
  ManagementUser,
  Session,
  Site,
  TwoFactorChallenge,
  TwoFactorEnrolment,
} from '@/shared/api/types'

export const EMPLOYEE_UUID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'
export const SESSION_TOKEN = '17|GhK2mXpR9vLdN4tZbYcF1wQ8sE3rT6uI0oP5aS7d'
/** Token del reto de segundo factor (RS-06): NO es una sesion, solo alcanza `/auth/2fa/*`. */
export const CHALLENGE_TOKEN = '41|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ'
/** Codigo TOTP que el doble acepta como valido. Cualquier otro es un rechazo. */
export const TOTP_CODE = '492013'
/** Clave de `sessionStorage` del panel (`session.store.ts`). */
export const SESSION_STORAGE_KEY = 'kronoqr.admin.session'

export const SITE: Site = { id: 1, name: 'Hotel Marina', timezone: 'Europe/Madrid' }

export const DEPARTMENTS: DepartmentCollection = {
  data: [
    { id: 3, name: 'Recepción' },
    { id: 4, name: 'Pisos' },
  ],
}

export const USER: ManagementUser = {
  uuid: '0199f0aa-1111-7000-8000-0123456789ab',
  name: 'Dirección RRHH',
  email: 'rrhh@hotel.example',
  locale: 'es',
  roles: ['rrhh'],
  abilities: ['attendance:read', 'employees:read', 'employees:*', 'credentials:*', 'incidents:*'],
  // Alcance por departamento (RF-ID-03). RRHH llega a toda la plantilla: con
  // `kind: all` la lista no acota nada y por eso va vacía.
  scope: { kind: 'all', department_ids: [] },
}

/**
 * Responsable de departamento (RF-ID-03, tarea 2.5): sin plantilla ni
 * credenciales, pero con la presencia y la bandeja de incidencias de su
 * departamento. El ejemplo del contrato para `GET /auth/me`.
 */
export const MANAGER_USER: ManagementUser = {
  uuid: '0199f0aa-2222-7000-8000-0123456789ac',
  name: 'Jefatura de Cocina',
  email: 'cocina@hotel.example',
  locale: 'es',
  roles: ['responsable_departamento'],
  abilities: ['attendance:read', 'attendance:correct', 'incidents:*'],
  scope: { kind: 'departments', department_ids: [3] },
}

export const SESSION: Session = {
  token: SESSION_TOKEN,
  token_type: 'Bearer',
  expires_at: '2099-01-01T00:00:00Z',
  user: USER,
}

/** El `202` de `/auth/login` cuando la cuenta ya tiene el segundo factor activo. */
export const TWO_FACTOR_CHALLENGE: TwoFactorChallenge = {
  challenge_token: CHALLENGE_TOKEN,
  token_type: 'Bearer',
  expires_at: '2099-01-01T00:10:00Z',
  enrolment_required: false,
}

/** El `202` de `/auth/login` la primera vez, sin segundo factor activo todavia. */
export const TWO_FACTOR_ENROLMENT_CHALLENGE: TwoFactorChallenge = {
  ...TWO_FACTOR_CHALLENGE,
  enrolment_required: true,
}

export const TWO_FACTOR_ENROLMENT: TwoFactorEnrolment = {
  secret: 'JBSWY3DPEHPK3PXP',
  otpauth_uri:
    'otpauth://totp/KronoQR:rrhh%40hotel.example?secret=JBSWY3DPEHPK3PXP&issuer=KronoQR&algorithm=SHA1&digits=6&period=30',
}

export const EMPLOYEE: Employee = {
  uuid: EMPLOYEE_UUID,
  employee_code: 'E7QK2MXPR',
  first_name: 'Youssef',
  last_name: 'Amrani',
  email: null,
  department_id: 3,
  status: 'active',
  hired_at: '2026-08-14',
  terminated_at: null,
  locale: 'es',
  pin_status: 'issued',
}

export const EMPLOYEES: EmployeeCollection = {
  data: [EMPLOYEE],
  meta: { page: 1, per_page: 30, total: 1, total_pages: 1 },
}

export const CREDENTIAL_BOARD: CredentialStatusBoard = {
  data: [],
  summary: { employees: 1, pending_print: 1, without_delivered_credential: 1 },
}

export const WORKDAYS: EmployeeWorkDays = {
  employee_uuid: EMPLOYEE_UUID,
  time_zone: 'Europe/Madrid',
  from: '2026-03-01',
  to: '2026-03-31',
  data: [
    {
      work_date: '2026-03-14',
      time_zone: 'Europe/Madrid',
      total_minutes: 485,
      shift_count: 1,
      has_open_shift: false,
      has_incident: false,
      recalculated_at: '2026-03-14T15:22:41.900000Z',
      shift_entries: [
        {
          uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
          version: 2,
          status: 'closed',
          time_zone: 'Europe/Madrid',
          clocked_in_at: '2026-03-14T05:00:00.000000Z',
          clocked_in_at_local: '2026-03-14T06:00:00.000000+01:00',
          clocked_in_recorded_at: '2026-03-14T05:00:02.113000Z',
          clock_in_source: 'qr_kiosk',
          clocked_out_at: '2026-03-14T13:05:00.000000Z',
          clocked_out_at_local: '2026-03-14T14:05:00.000000+01:00',
          clocked_out_recorded_at: null,
          clock_out_source: 'manual_admin',
          duration_minutes: 485,
          recorded_at: '2026-03-14T15:22:41.900000Z',
        },
      ],
      corrections: [
        {
          shift_entry_uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
          action: 'closed',
          performed_at: '2026-03-14T15:22:41.900000Z',
          performed_at_local: '2026-03-14T16:22:41.900000+01:00',
          performed_by: { uuid: USER.uuid, name: 'Cuenta de RRHH' },
          reason_code: 'OLVIDO_FICHAJE_SALIDA',
          reason_text: null,
          before: {
            version: 1,
            clocked_in_at: '2026-03-14T05:00:00.000000Z',
            clocked_out_at: null,
            worked_minutes: 0,
          },
          after: {
            version: 2,
            clocked_in_at: '2026-03-14T05:00:00.000000Z',
            clocked_out_at: '2026-03-14T13:05:00.000000Z',
            worked_minutes: 485,
          },
        },
      ],
      // Sin incidencias en el recorrido de la 1.16 (RF-PA-05). El campo va
      // siempre porque el contrato lo exige.
      incidents: [],
    },
  ],
  meta: { total: 1 },
}

/**
 * La misma jornada, pero con la ficha minima de la incidencia 412 incrustada
 * (RF-PA-05, tarea 2.5): lo que usa el E2E para comprobar que la marca aparece
 * en el detalle de jornada sin una segunda llamada.
 */
const [FIRST_WORKDAY] = WORKDAYS.data

export const WORKDAYS_WITH_INCIDENT: EmployeeWorkDays = {
  ...WORKDAYS,
  data:
    FIRST_WORKDAY === undefined
      ? []
      : [
          {
            ...FIRST_WORKDAY,
            incidents: [{ id: 412, type: 'insufficient_rest', severity: 'high', status: 'open' }],
          },
        ],
}

// --- Presencia en vivo (RF-PA-01, RF-PA-02) ----------------------------------

export const LIVE_ENTRY: LivePresenceEntry = {
  employee_uuid: EMPLOYEE_UUID,
  full_name: 'Youssef Amrani',
  department: { id: 3, name: 'Recepción' },
  status: 'present',
  shift_entry_uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
  clocked_in_at: '2026-03-14T05:00:00.000000Z',
  origin: 'qr_kiosk',
  device: { uuid: '0199f0d3-3c71-7e52-9a13-6f7a8b9c0d12', name: 'Entrada de personal' },
}

/** La foto del servidor con Reverb disponible. `generated_at` a las 09:12 UTC del mismo dia. */
export const LIVE_BOARD: LivePresenceBoard = {
  data: [
    LIVE_ENTRY,
    {
      ...LIVE_ENTRY,
      employee_uuid: '0199f0c2-2222-7c3e-9b21-4d5e6f7a8b91',
      full_name: 'Lucía Martínez Prieto',
      department: { id: 4, name: 'Pisos' },
      shift_entry_uuid: '0199f2c1-9b21-7b40-9c50-6d7e8f9a0b12',
      clocked_in_at: '2026-03-14T06:30:00.000000Z',
      origin: 'pin_kiosk',
      device: null,
    },
  ],
  meta: {
    generated_at: '2026-03-14T09:12:03.418000Z',
    time_zone: 'Europe/Madrid',
    present_count: 2,
    absent_count: 3,
    total: 5,
    realtime: {
      enabled: true,
      key: 'kronoqr',
      path: '/app',
      auth_endpoint: '/api/v1/broadcasting/auth',
      event: 'presence.updated',
      channels: ['presence.all'],
      poll_interval_seconds: 15,
    },
  },
}

// --- Bandeja de incidencias (RF-PA-05, RF-PR-01) -----------------------------

export const INCIDENT_ID = 412

/** El ejemplo del contrato: descanso insuficiente de Youssef, pendiente. */
export const OPEN_INCIDENT: Incident = {
  id: INCIDENT_ID,
  type: 'insufficient_rest',
  severity: 'high',
  status: 'open',
  employee: {
    uuid: EMPLOYEE_UUID,
    employee_code: 'E7QK2MXPR',
    full_name: 'Youssef Amrani',
    department: { id: 3, name: 'Recepción' },
  },
  work_date: '2026-03-14',
  shift_entry_uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
  detected_at: '2026-03-15T03:30:00.000000Z',
  context: { rest_minutes: 420, threshold_minutes: 720 },
  assigned_to: { uuid: MANAGER_USER.uuid, name: MANAGER_USER.name },
  resolved_at: null,
  resolved_by: null,
  resolution_note: null,
}

/** La misma incidencia, ya cerrada por otra persona: lo que devuelve la relectura tras un `409`. */
export const INCIDENT_CLOSED_BY_OTHER: Incident = {
  ...OPEN_INCIDENT,
  status: 'resolved',
  resolved_at: '2026-03-15T08:45:00.000000Z',
  resolved_by: { uuid: '0199f0aa-3333-7000-8000-0123456789ad', name: 'Segunda jefatura de turno' },
  resolution_note: 'Ya se habia revisado en el cambio de turno.',
}

/** Una sola pagina, que es lo unico que necesita el E2E: nunca hay mas de 25 filas de mentira. */
function incidentPage(data: Incident[]): IncidentCollection {
  return {
    data,
    meta: {
      page: 1,
      per_page: 25,
      total: data.length,
      total_pages: 1,
      time_zone: 'Europe/Madrid',
      generated_at: '2026-03-15T09:00:00.000000Z',
    },
  }
}

export const INCIDENT_BOARD = incidentPage([OPEN_INCIDENT])
export const EMPTY_INCIDENT_BOARD = incidentPage([])

/** Una peticion a la API tal y como salio del panel. */
export interface RecordedRequest {
  readonly method: string
  readonly path: string
  readonly authorization: string | undefined
  /** La cadena de consulta, sin el «?». */
  readonly query: string
  /** El cuerpo JSON, o `null` cuando la peticion no traia uno legible (un `GET`, por ejemplo). */
  readonly body: unknown
}

export interface ManagementApiStub {
  /** Todas las peticiones a `/api/v1/*`, en orden. */
  readonly requests: RecordedRequest[]
}

export interface ManagementApiOptions {
  /** Si el acceso con contrasena se acepta. Por omision, si. */
  readonly loginOutcome?: 'ok' | 'invalid'
  /**
   * Segundo factor tras una contrasena correcta (RS-06). `off` (por omision)
   * deja `/auth/login` con la sesion directa, como antes de la 2.1. `verify`
   * simula una cuenta con TOTP ya activo: `/auth/login` responde `202` y hay
   * que canjear el reto en `/auth/2fa/verify`. `enrol` simula la primera vez:
   * `/auth/login` tambien responde `202`, pero con `enrolment_required` y hay
   * que pasar por `/auth/2fa/enrol` + `/auth/2fa/confirm`. En los dos casos el
   * codigo valido es `TOTP_CODE`; cualquier otro se rechaza con `401`.
   */
  readonly twoFactor?: 'off' | 'verify' | 'enrol'
  /** La foto de presencia que devuelve `GET /attendance/live`. Por omision, `LIVE_BOARD`. */
  readonly liveBoard?: LivePresenceBoard
  /**
   * Que cuenta entra por `logIn()`. `rrhh` (por omision) es `USER`, con alcance
   * completo; `manager` es `MANAGER_USER`, un `responsable_departamento` con
   * `incidents:*` y sin plantilla ni credenciales (RF-ID-03).
   */
  readonly role?: 'rrhh' | 'manager'
  /**
   * Que responde `POST /incidents/{id}/resolve`. `ok` (por omision) cierra la
   * incidencia y la devuelve entera. `conflict` simula que otra persona se
   * adelanto: la peticion responde `409` y las relecturas posteriores devuelven
   * la incidencia ya cerrada por `INCIDENT_CLOSED_BY_OTHER`.
   */
  readonly resolveOutcome?: 'ok' | 'conflict'
  /** El registro horario que devuelve `GET /employees/{uuid}/workdays`. Por omision, `WORKDAYS`. */
  readonly workdays?: EmployeeWorkDays
}

async function json(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
}

async function problem(route: Route, status: number, type: string, title: string): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/problem+json',
    body: JSON.stringify({ type, title, status }),
  })
}

/**
 * Intercepta toda la API de gestion que usan el acceso, la plantilla, la ficha
 * y el registro horario. Lo que no esta previsto responde `404 problem+json`,
 * para que una pantalla que llame a algo nuevo falle aqui y no se quede
 * esperando a un servidor que no existe.
 */
export async function stubManagementApi(
  page: Page,
  options: ManagementApiOptions = {},
): Promise<ManagementApiStub> {
  const requests: RecordedRequest[] = []
  const loginOutcome = options.loginOutcome ?? 'ok'
  const twoFactor = options.twoFactor ?? 'off'
  const resolveOutcome = options.resolveOutcome ?? 'ok'
  const currentUser = options.role === 'manager' ? MANAGER_USER : USER
  const currentSession: Session = { ...SESSION, user: currentUser }

  // Si `resolveOutcome` es `conflict`, la incidencia se da por cerrada -por
  // otra persona- justo cuando llega el `POST /resolve`: antes de eso la
  // bandeja la sigue enseñando abierta, que es lo que hace falta para que la
  // prueba pueda pulsar «Resolver».
  let incidentClosed = false

  /** El codigo del cuerpo, o cadena vacia si la peticion no llevaba uno legible. */
  function codeFrom(route: Route): string {
    const body: unknown = route.request().postDataJSON()

    return typeof body === 'object' && body !== null && 'code' in body
      ? String((body as { code: unknown }).code)
      : ''
  }

  await page.route(
    (url) => url.pathname.startsWith('/api/v1/'),
    async (route: Route) => {
      const request = route.request()
      const url = new URL(request.url())
      const method = request.method()

      let body: unknown = null

      try {
        body = request.postDataJSON()
      } catch {
        body = null
      }

      requests.push({
        method,
        path: url.pathname,
        authorization: request.headers()['authorization'],
        query: url.search.slice(1),
        body,
      })

      // La bandeja de incidencias tiene un identificador dinamico en la ruta de
      // resolver (`/incidents/{id}/resolve`), asi que no encaja en el `switch`
      // literal de abajo.
      if (method === 'POST' && /^\/api\/v1\/incidents\/\d+\/resolve$/.test(url.pathname)) {
        if (resolveOutcome === 'conflict') {
          incidentClosed = true
          await problem(
            route,
            409,
            'urn:kronoqr:problem:incident-already-resolved',
            'La incidencia ya se ha resuelto',
          )

          return
        }

        const payload = request.postDataJSON() as { outcome?: string; note?: string }
        incidentClosed = true
        await json(route, 200, {
          ...OPEN_INCIDENT,
          status: payload.outcome === 'dismissed' ? 'dismissed' : 'resolved',
          resolved_at: '2026-03-15T09:05:00.000000Z',
          resolved_by: { uuid: currentUser.uuid, name: currentUser.name },
          resolution_note: payload.note ?? null,
        })

        return
      }

      if (method === 'GET' && url.pathname === '/api/v1/incidents') {
        const status = url.searchParams.get('status') ?? 'open'

        if (incidentClosed) {
          await json(
            route,
            200,
            status === 'resolved' ? incidentPage([INCIDENT_CLOSED_BY_OTHER]) : EMPTY_INCIDENT_BOARD,
          )
        } else {
          await json(route, 200, status === 'open' ? INCIDENT_BOARD : EMPTY_INCIDENT_BOARD)
        }

        return
      }

      switch (`${method} ${url.pathname}`) {
        case 'POST /api/v1/auth/login':
          if (loginOutcome === 'invalid') {
            await problem(
              route,
              401,
              'urn:kronoqr:problem:invalid-credentials',
              'Credenciales no válidas',
            )
          } else if (twoFactor === 'verify') {
            await route.fulfill({
              status: 202,
              contentType: 'application/json',
              body: JSON.stringify(TWO_FACTOR_CHALLENGE),
            })
          } else if (twoFactor === 'enrol') {
            await route.fulfill({
              status: 202,
              contentType: 'application/json',
              body: JSON.stringify(TWO_FACTOR_ENROLMENT_CHALLENGE),
            })
          } else {
            await json(route, 200, currentSession)
          }
          return
        case 'POST /api/v1/auth/2fa/verify':
          if (codeFrom(route) === TOTP_CODE) {
            await json(route, 200, currentSession)
          } else {
            await problem(
              route,
              401,
              'urn:kronoqr:problem:invalid-credentials',
              'Credenciales no válidas',
            )
          }
          return
        case 'POST /api/v1/auth/2fa/enrol':
          await json(route, 200, TWO_FACTOR_ENROLMENT)
          return
        case 'POST /api/v1/auth/2fa/confirm':
          if (codeFrom(route) === TOTP_CODE) {
            await json(route, 200, currentSession)
          } else {
            await problem(
              route,
              401,
              'urn:kronoqr:problem:invalid-credentials',
              'Credenciales no válidas',
            )
          }
          return
        case 'GET /api/v1/auth/me':
          await json(route, 200, currentUser)
          return
        case 'POST /api/v1/auth/logout':
          await route.fulfill({ status: 204 })
          return
        case 'GET /api/v1/site':
          await json(route, 200, SITE)
          return
        case 'GET /api/v1/departments':
          await json(route, 200, DEPARTMENTS)
          return
        case 'GET /api/v1/employees':
          await json(route, 200, EMPLOYEES)
          return
        case `GET /api/v1/employees/${EMPLOYEE_UUID}`:
          await json(route, 200, EMPLOYEE)
          return
        case `GET /api/v1/employees/${EMPLOYEE_UUID}/workdays`:
          await json(route, 200, options.workdays ?? WORKDAYS)
          return
        case 'GET /api/v1/credentials/status':
          await json(route, 200, CREDENTIAL_BOARD)
          return
        case 'GET /api/v1/attendance/live':
          await json(route, 200, options.liveBoard ?? LIVE_BOARD)
          return
        case 'POST /api/v1/broadcasting/auth':
          // La firma real la calcula el servidor con su secreto; aqui basta con
          // que el cliente reciba el campo con la forma del protocolo.
          await json(route, 200, { auth: 'kronoqr:firma-de-prueba' })
          return
        default:
          await problem(route, 404, 'about:blank', 'Sin doble para esta ruta en el E2E')
      }
    },
  )

  return { requests }
}

/** Rellena y envia el primer paso: correo y contrasena. No espera a lo que venga despues. */
async function submitCredentials(page: Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel(/Correo electrónico/).fill(USER.email)
  await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
  await page.getByRole('button', { name: 'Entrar' }).click()
}

/** Entra al panel por la pantalla de acceso, como lo hace una persona sin segundo factor. */
export async function logIn(page: Page): Promise<void> {
  await submitCredentials(page)
  await page.waitForURL('**/employees')
}

/**
 * Entra como el `responsable_departamento` de `MANAGER_USER` (RF-ID-03). Sin
 * `employees:*`, la raiz no lo lleva a la plantilla: la primera seccion a su
 * alcance es la presencia (doc 02 §7.3, `router/guards.ts`). Exige
 * `stubManagementApi(page, { role: 'manager' })`.
 */
export async function logInAsManager(page: Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel(/Correo electrónico/).fill(MANAGER_USER.email)
  await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await page.waitForURL('**/live')
}

/**
 * Entra con contrasena y segundo factor ya activo (RS-06): `submitCredentials`
 * deja `202` y la pantalla del codigo; se teclea `TOTP_CODE` y se verifica.
 * Exige `stubManagementApi(page, { twoFactor: 'verify' })`.
 */
export async function logInWithTwoFactorCode(page: Page): Promise<void> {
  await submitCredentials(page)
  await page.getByLabel(/Código de verificación/).fill(TOTP_CODE)
  await page.getByRole('button', { name: 'Verificar' }).click()
  await page.waitForURL('**/employees')
}

/**
 * Entra dando de alta el segundo factor por primera vez (RS-06):
 * `submitCredentials` deja `202` con `enrolment_required` y la pantalla del
 * QR; se teclea `TOTP_CODE` en el campo de confirmacion. Exige
 * `stubManagementApi(page, { twoFactor: 'enrol' })`.
 */
export async function logInWithTwoFactorEnrolment(page: Page): Promise<void> {
  await submitCredentials(page)
  await page.getByLabel(/Código del autenticador/).fill(TOTP_CODE)
  await page.getByRole('button', { name: 'Activar y entrar' }).click()
  await page.waitForURL('**/employees')
}
