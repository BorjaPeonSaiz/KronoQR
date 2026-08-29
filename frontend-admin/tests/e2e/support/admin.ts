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
  ManagementUser,
  Session,
  Site,
} from '@/shared/api/types'

export const EMPLOYEE_UUID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'
export const SESSION_TOKEN = '17|GhK2mXpR9vLdN4tZbYcF1wQ8sE3rT6uI0oP5aS7d'
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
  abilities: ['attendance:read', 'employees:*', 'credentials:*'],
}

export const SESSION: Session = {
  token: SESSION_TOKEN,
  token_type: 'Bearer',
  expires_at: '2099-01-01T00:00:00Z',
  user: USER,
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
    },
  ],
  meta: { total: 1 },
}

/** Una peticion a la API tal y como salio del panel. */
export interface RecordedRequest {
  readonly method: string
  readonly path: string
  readonly authorization: string | undefined
}

export interface ManagementApiStub {
  /** Todas las peticiones a `/api/v1/*`, en orden. */
  readonly requests: RecordedRequest[]
}

export interface ManagementApiOptions {
  /** Si el acceso con contrasena se acepta. Por omision, si. */
  readonly loginOutcome?: 'ok' | 'invalid'
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

  await page.route(
    (url) => url.pathname.startsWith('/api/v1/'),
    async (route: Route) => {
      const request = route.request()
      const url = new URL(request.url())
      const method = request.method()

      requests.push({
        method,
        path: url.pathname,
        authorization: request.headers()['authorization'],
      })

      switch (`${method} ${url.pathname}`) {
        case 'POST /api/v1/auth/login':
          if (loginOutcome === 'invalid') {
            await problem(
              route,
              401,
              'urn:kronoqr:problem:invalid-credentials',
              'Credenciales no válidas',
            )
          } else {
            await json(route, 200, SESSION)
          }
          return
        case 'GET /api/v1/auth/me':
          await json(route, 200, USER)
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
          await json(route, 200, WORKDAYS)
          return
        case 'GET /api/v1/credentials/status':
          await json(route, 200, CREDENTIAL_BOARD)
          return
        default:
          await problem(route, 404, 'about:blank', 'Sin doble para esta ruta en el E2E')
      }
    },
  )

  return { requests }
}

/** Entra al panel por la pantalla de acceso, como lo hace una persona. */
export async function logIn(page: Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel(/Correo electrónico/).fill(USER.email)
  await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await page.waitForURL('**/employees')
}
