// Doble de la API del portal del empleado, para las capturas de
// `docs/cliente/guia-portal-empleado.md` (tarea 5.11b, bloque C) y para el
// recorrido E2E que hoy no existe (nace aqui, listo para escribirse despues).
//
// Cada respuesta tiene la forma del contrato (`import type` de
// `@/shared/api/types`: si el contrato cambia, esto deja de compilar antes de
// que la prueba mienta). El backend no participa: aqui se prueba el
// recorrido por el portal, y lo que el servidor autoriza o deniega se prueba
// en el backend (regla dura 18).
//
// DATOS DE DEMOSTRACION, NUNCA REALES (regla dura 21): «Hotel Marina» y
// «Youssef Amrani» son los mismos que usan los dobles del panel y del
// quiosco (`frontend-admin/tests/e2e/support/admin.ts`), no una coincidencia:
// una sola persona ficticia recorre las tres guias.
//
// PAR DE SEMANAS CON UNA CORRECCION VISIBLE: seis jornadas entre el 9 y el 20
// de marzo de 2026, con la del sabado 14 corregida (RRHH cerro un turno al
// que le faltaba la salida) — los mismos valores que `WORKDAYS` del panel,
// para que quien compare las dos guias vea el mismo caso.
import type { Page, Route } from '@playwright/test'
import type {
  Branding,
  EmployeeWorkDays,
  PortalEmployee,
  PortalLoginRequest,
  PortalSession,
  WorkDayDetail,
} from '@/shared/api/types'

export type PortalLocale = 'es' | 'en'

export const EMPLOYEE_UUID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'
export const PORTAL_EMPLOYEE_CODE = 'E7K2M9XQ4'
export const PORTAL_PIN = '284016'
export const PORTAL_SESSION_TOKEN = '41|Zt6QpX1nR8vKcLm3bYdF9wS2eT5rU7iO0aP4sD8h'

/** Clave de `sessionStorage` del portal (`session.store.ts`). */
export const SESSION_STORAGE_KEY = 'kronoqr.portal.session'

/** Lo que responde `GET /api/v1/branding` sin nada configurado: el producto (RF-PD-08). */
export const PRODUCT_BRANDING: Branding = {
  application_name: 'KronoQR',
  accent_color: null,
  logo_url: null,
  locales: { default: 'es', available: ['es', 'en'] },
}

/** La huella que lleva la URL del logotipo de ejemplo (`?v=`, contrato). */
export const LOGO_DIGEST = '3f9a1c2b7e4d'

/**
 * Un hotel con nombre, color y logotipo propios (el ejemplo del contrato).
 * Los mismos valores que `HOTEL_BRANDING` de `frontend-admin/tests/e2e/support/admin.ts`
 * (RF-PD-08, tarea 5.8): las tres SPA aplican la misma marca del mismo cliente.
 */
export const HOTEL_BRANDING: Branding = {
  application_name: 'Hotel Marina',
  accent_color: '#0f5c8c',
  logo_url: `/api/v1/branding/logo?v=${LOGO_DIGEST}`,
  locales: { default: 'es', available: ['es'] },
}

/**
 * Un PNG de 1x1 transparente, de verdad: lo que sirve el doble de
 * `GET /api/v1/branding/logo`. No hace falta que se vea nada, solo que el
 * `<img>` cargue con el `Content-Type` correcto (RF-PD-08).
 */
const ONE_PIXEL_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
  'base64',
)

function portalEmployee(locale: PortalLocale): PortalEmployee {
  return {
    uuid: EMPLOYEE_UUID,
    display_name: 'Youssef Amrani',
    employee_code: PORTAL_EMPLOYEE_CODE,
    locale,
    time_zone: 'Europe/Madrid',
  }
}

function portalSession(locale: PortalLocale): PortalSession {
  return {
    token: PORTAL_SESSION_TOKEN,
    token_type: 'Bearer',
    // Sesion larga a proposito: una captura no debe caducar a media
    // generacion. El maximo real de dos horas (`session.store.ts`) es cosa
    // del servidor, no de este doble.
    expires_at: '2099-01-01T00:00:00Z',
    employee: portalEmployee(locale),
  }
}

// --- Dos semanas de jornadas, con una correccion visible --------------------

const SHIFT_ENTRY_UUID = '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11'

function normalDay(date: string, clockInHourUtc: number): WorkDayDetail {
  const clockedInAt = `${date}T${String(clockInHourUtc).padStart(2, '0')}:00:00.000000Z`
  const clockedOutAt = `${date}T${String(clockInHourUtc + 8).padStart(2, '0')}:00:00.000000Z`
  const clockedInLocal = `${date}T${String(clockInHourUtc + 1).padStart(2, '0')}:00:00.000000+01:00`
  const clockedOutLocal = `${date}T${String(clockInHourUtc + 9).padStart(2, '0')}:00:00.000000+01:00`

  return {
    work_date: date,
    time_zone: 'Europe/Madrid',
    total_minutes: 480,
    shift_count: 1,
    has_open_shift: false,
    has_incident: false,
    recalculated_at: clockedOutAt,
    shift_entries: [
      {
        uuid: `${SHIFT_ENTRY_UUID.slice(0, -2)}${date.slice(-2)}`,
        version: 1,
        status: 'closed',
        time_zone: 'Europe/Madrid',
        clocked_in_at: clockedInAt,
        clocked_in_at_local: clockedInLocal,
        clocked_in_recorded_at: clockedInAt,
        clock_in_source: 'qr_kiosk',
        clocked_out_at: clockedOutAt,
        clocked_out_at_local: clockedOutLocal,
        clocked_out_recorded_at: clockedOutAt,
        clock_out_source: 'qr_kiosk',
        duration_minutes: 480,
        recorded_at: clockedOutAt,
      },
    ],
    corrections: [],
    incidents: [],
  }
}

/**
 * El sabado 14 de marzo (RN-05): un turno al que le faltaba la salida, que
 * RRHH cerro con el motivo `OLVIDO_FICHAJE_SALIDA`. Mismos valores que
 * `WORKDAYS` de `frontend-admin/tests/e2e/support/admin.ts`.
 */
function correctedDay(): WorkDayDetail {
  return {
    work_date: '2026-03-14',
    time_zone: 'Europe/Madrid',
    total_minutes: 485,
    shift_count: 1,
    has_open_shift: false,
    has_incident: false,
    recalculated_at: '2026-03-14T15:22:41.900000Z',
    shift_entries: [
      {
        uuid: SHIFT_ENTRY_UUID,
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
        shift_entry_uuid: SHIFT_ENTRY_UUID,
        action: 'closed',
        performed_at: '2026-03-14T15:22:41.900000Z',
        performed_at_local: '2026-03-14T16:22:41.900000+01:00',
        performed_by: { uuid: '0199f0aa-1111-7000-8000-0123456789ab', name: 'Cuenta de RRHH' },
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
    incidents: [],
  }
}

/** El viernes 20, todavia en curso: sin fichar la salida (regla dura 19: nunca se cierra sola). */
function openDay(): WorkDayDetail {
  return {
    work_date: '2026-03-20',
    time_zone: 'Europe/Madrid',
    total_minutes: 210,
    shift_count: 1,
    has_open_shift: true,
    has_incident: false,
    recalculated_at: '2026-03-20T10:30:00.000000Z',
    shift_entries: [
      {
        uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b20',
        version: 1,
        status: 'open',
        time_zone: 'Europe/Madrid',
        clocked_in_at: '2026-03-20T07:00:00.000000Z',
        clocked_in_at_local: '2026-03-20T08:00:00.000000+01:00',
        clocked_in_recorded_at: '2026-03-20T07:00:01.500000Z',
        clock_in_source: 'qr_kiosk',
        clocked_out_at: null,
        clocked_out_at_local: null,
        clocked_out_recorded_at: null,
        clock_out_source: null,
        duration_minutes: null,
        recorded_at: '2026-03-20T07:00:01.500000Z',
      },
    ],
    corrections: [],
    incidents: [],
  }
}

export const WORKDAYS_FROM = '2026-03-09'
export const WORKDAYS_TO = '2026-03-20'

export const PORTAL_WORKDAYS: WorkDayDetail[] = [
  normalDay('2026-03-09', 6),
  normalDay('2026-03-10', 6),
  normalDay('2026-03-12', 6),
  correctedDay(),
  normalDay('2026-03-17', 6),
  openDay(),
]

function employeeWorkDays(from: string, to: string): EmployeeWorkDays {
  const data = PORTAL_WORKDAYS.filter((day) => day.work_date >= from && day.work_date <= to)

  return {
    employee_uuid: EMPLOYEE_UUID,
    time_zone: 'Europe/Madrid',
    from,
    to,
    data,
    meta: { total: data.length },
  }
}

// --- CSV de exportacion (RF-ID-05, RL-05, art. 20 RGPD) ----------------------

const EXPORT_CSV =
  'jornada,entrada,salida,duracion,motivo_correccion\n' +
  '2026-03-09,08:00,16:00,08:00,\n' +
  '2026-03-10,08:00,16:00,08:00,\n' +
  '2026-03-11,08:00,16:00,08:00,\n' +
  '2026-03-14,06:00,14:05,08:05,OLVIDO_FICHAJE_SALIDA\n' +
  '2026-03-17,08:00,16:00,08:00,\n' +
  '2026-03-20,08:00,,03:30,\n'

export interface PortalApiOptions {
  /** El idioma de la sesion (`PortalEmployee.locale`), NO el del navegador. */
  locale: PortalLocale
  /**
   * Si el acceso con codigo y PIN se acepta. Por omision, `'ok'`.
   *
   * `'invalid'`: cualquier PIN se rechaza con el `401` generico de siempre
   * (RS-03). `'rateLimited'`: simula el bloqueo creciente por intentos
   * fallidos (RS-12, §7.5) con el `429` del contrato — el portal no distingue
   * «PIN incorrecto» de «demasiados intentos»: los dos avisos son genericos,
   * pero el `429` SI lleva su propio texto («demasiados intentos seguidos»)
   * porque es un limite de trafico, no una confirmacion de que la cuenta
   * existe.
   */
  loginOutcome?: 'ok' | 'invalid' | 'rateLimited'
  /** La marca que devuelve `GET /api/v1/branding`. Por omision, la del producto. */
  branding?: Branding
}

export interface RecordedRequest {
  readonly method: string
  readonly path: string
  readonly authorization: string | undefined
  readonly query: string
}

export interface PortalApiStub {
  readonly requests: RecordedRequest[]
}

async function json(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
}

/**
 * Intercepta la API completa del portal: acceso, mi registro y mi
 * exportacion. Lo que no esta previsto responde `404 problem+json`, para que
 * una pantalla que llame a algo nuevo falle aqui y no se quede esperando a un
 * servidor que no existe.
 */
export async function stubPortalApi(page: Page, options: PortalApiOptions): Promise<PortalApiStub> {
  const requests: RecordedRequest[] = []
  const loginOutcome = options.loginOutcome ?? 'ok'
  const branding = options.branding ?? PRODUCT_BRANDING

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
        query: url.search.slice(1),
      })

      switch (`${method} ${url.pathname}`) {
        case 'GET /api/v1/branding':
          // Publica, sin token (RF-PD-08): la pantalla de acceso la pide
          // antes de que exista ninguna sesion.
          await json(route, 200, branding)
          return

        case 'GET /api/v1/branding/logo':
          // Publica tambien. Un PNG de verdad, no un doble vacio: lo que
          // comprueba el E2E es que el `<img>` carga con el tipo correcto.
          await route.fulfill({
            status: 200,
            contentType: 'image/png',
            headers: { 'Cache-Control': 'public, max-age=31536000, immutable' },
            body: ONE_PIXEL_PNG,
          })
          return

        case 'POST /api/v1/me/login': {
          if (loginOutcome === 'rateLimited') {
            // El bloqueo creciente por intentos fallidos (RS-12, §7.5): un
            // `429` con `Retry-After`, nunca un `401` que confirmaria que la
            // cuenta esta bloqueada (RS-03). 300 s = los 5 min del primer
            // escalon (3 fallos).
            await route.fulfill({
              status: 429,
              contentType: 'application/problem+json',
              headers: { 'Retry-After': '300' },
              body: JSON.stringify({
                type: 'urn:kronoqr:problem:too-many-requests',
                title: 'Demasiadas peticiones',
                status: 429,
                detail: 'Reintenta pasados unos segundos.',
              }),
            })

            return
          }

          const body = request.postDataJSON() as PortalLoginRequest

          if (
            loginOutcome === 'invalid' ||
            body.employee_code !== PORTAL_EMPLOYEE_CODE ||
            body.pin !== PORTAL_PIN
          ) {
            // Una sola respuesta para cualquier rechazo (RS-03, regla dura 17):
            // el doble no distingue codigo inexistente de PIN incorrecto.
            await route.fulfill({
              status: 401,
              contentType: 'application/problem+json',
              body: JSON.stringify({
                type: 'urn:kronoqr:problem:invalid-credentials',
                title: 'Credenciales no validas',
                status: 401,
              }),
            })

            return
          }

          await json(route, 200, portalSession(options.locale))
          return
        }

        case 'GET /api/v1/me/workdays': {
          const from = url.searchParams.get('from') ?? WORKDAYS_FROM
          const to = url.searchParams.get('to') ?? WORKDAYS_TO

          await json(route, 200, employeeWorkDays(from, to))
          return
        }

        case 'GET /api/v1/me/export': {
          const from = url.searchParams.get('from') ?? WORKDAYS_FROM
          const to = url.searchParams.get('to') ?? WORKDAYS_TO

          await route.fulfill({
            status: 200,
            contentType: 'text/csv; charset=utf-8',
            headers: {
              // Sin ningun nombre de persona (regla dura 21): solo el periodo.
              'Content-Disposition': `attachment; filename=mi-registro-horario-${from}_${to}.csv`,
              'Cache-Control': 'no-store',
            },
            body: EXPORT_CSV,
          })

          return
        }

        default:
          await route.fulfill({
            status: 404,
            contentType: 'application/problem+json',
            body: JSON.stringify({
              type: 'about:blank',
              title: 'Sin doble para esta ruta en el E2E',
              status: 404,
            }),
          })
      }
    },
  )

  return { requests }
}

/**
 * Entra al portal por la pantalla de acceso, como lo hace una persona
 * empleada: codigo y PIN, sin correo ni contrasena (regla dura 12, ADR-015).
 */
export async function logInToPortal(
  page: Page,
  credentials: { employeeCode: string; pin: string } = {
    employeeCode: PORTAL_EMPLOYEE_CODE,
    pin: PORTAL_PIN,
  },
): Promise<void> {
  await page.goto('/login')
  await page.locator('input[name="employee_code"]').fill(credentials.employeeCode)
  await page.locator('input[name="pin"]').fill(credentials.pin)
  await page.locator('form button[type="submit"]').click()
  await page.waitForURL('**/records')
}

/**
 * Igual que `logInToPortal`, pero para el camino que NO abre sesion (PIN
 * incorrecto, bloqueo por intentos): rellena y envia sin esperar la
 * redireccion, que aqui no llega.
 */
export async function submitLoginForm(
  page: Page,
  credentials: { employeeCode: string; pin: string } = {
    employeeCode: PORTAL_EMPLOYEE_CODE,
    pin: PORTAL_PIN,
  },
): Promise<void> {
  await page.goto('/login')
  await page.locator('input[name="employee_code"]').fill(credentials.employeeCode)
  await page.locator('input[name="pin"]').fill(credentials.pin)
  await page.locator('form button[type="submit"]').click()
}

export interface ClientErrorsEndpointStub {
  readonly count: () => number
}

/**
 * Doble de `POST /api/v1/client-errors` (RF-PD-15, tarea 5.12) que SIEMPRE
 * falla con `500`: lo que importa aqui no es que el envio tenga exito, sino
 * que un fallo al reportar un error no bloquee el portal ni reintente en
 * bucle. Registrarlo DESPUES de `stubPortalApi` para que esta ruta, mas
 * especifica, gane a la generica de `/api/v1/*` (mismo orden que
 * `frontend-admin/tests/e2e/support/errors.ts`).
 */
export function stubClientErrorsEndpoint(page: Page): ClientErrorsEndpointStub {
  let count = 0

  void page.route('**/api/v1/client-errors', async (route: Route) => {
    count += 1
    await route.fulfill({
      status: 500,
      contentType: 'application/problem+json',
      body: JSON.stringify({ type: 'about:blank', title: 'Error interno', status: 500 }),
    })
  })

  return { count: () => count }
}
