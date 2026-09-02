// Doble de la API del asistente de puesta en marcha (RF-PD-03, RF-GP-05,
// tarea 5.5), independiente de `stubManagementApi`: aqui NO hay sesion ni
// usuario de partida, es exactamente lo que se contesta a una instalacion sin
// ninguna cuenta de gestion.
//
// El estado es mutable dentro del cierre, igual que `stubManagementApi` lo
// hace para la incidencia resuelta: el asistente en el navegador de verdad
// mantiene su propio estado (Pinia, en memoria) que se construye a partir de
// lo que este doble va devolviendo paso a paso, asi que el doble tiene que
// avanzar EXACTAMENTE como lo haria el servidor real.
import type { Page, Route } from '@playwright/test'
import type {
  Department,
  License,
  SetupStep,
  SetupStepState,
  SetupStepStatus,
  Site,
  TwoFactorChallenge,
  TwoFactorEnrolment,
} from '@/shared/api/types'

export const CHALLENGE_TOKEN = '41|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ'
export const TOTP_CODE = '492013'
export const SESSION_TOKEN = '17|GhK2mXpR9vLdN4tZbYcF1wQ8sE3rT6uI0oP5aS7d'
export const SITE: Site = { id: 1, name: 'Hotel Marina', timezone: 'Europe/Madrid' }

const STEP_ORDER: readonly SetupStep[] = [
  'administrator',
  'organisation',
  'site',
  'departments',
  'compliance_profile',
  'employees',
  'license',
  'kiosk',
]

const REQUIRED: Record<SetupStep, boolean> = {
  administrator: true,
  organisation: true,
  site: true,
  departments: false,
  compliance_profile: true,
  employees: false,
  license: false,
  kiosk: false,
}

const SKIPPABLE: Record<SetupStep, boolean> = {
  administrator: false,
  organisation: false,
  site: false,
  departments: true,
  compliance_profile: false,
  employees: true,
  license: true,
  kiosk: true,
}

const TWO_FACTOR_ENROLMENT: TwoFactorEnrolment = {
  secret: 'JBSWY3DPEHPK3PXP',
  otpauth_uri:
    'otpauth://totp/KronoQR:direccion%40hotel.example?secret=JBSWY3DPEHPK3PXP&issuer=KronoQR&algorithm=SHA1&digits=6&period=30',
}

const ABSENT_LICENSE: License = {
  data: {
    state: 'absent',
    severity: 'none',
    rejection_reason: null,
    customer_name: null,
    plan: null,
    license_id: null,
    valid_from: null,
    valid_until: null,
    issued_at: null,
    days_until_expiry: null,
    days_since_expiry: null,
    features: [],
    degraded_features: [],
    limits: [],
    activated_at: null,
    last_verified_at: null,
    key_fingerprint: null,
  },
  meta: { expiry_warning_days: 30, needs_notice: false, evaluated_at: '2026-09-02T09:00:00Z' },
}

const VALID_LICENSE: License = {
  data: {
    ...ABSENT_LICENSE.data,
    state: 'valid',
    customer_name: 'Hotel Marina, S.L.',
    plan: 'estandar',
    license_id: 'lic-1',
    valid_from: '2026-01-01T00:00:00.000000Z',
    valid_until: '2027-01-01T00:00:00.000000Z',
    days_until_expiry: 365,
    activated_at: '2026-09-02T09:00:00.000000Z',
    last_verified_at: '2026-09-02T09:00:00.000000Z',
    key_fingerprint: '4b1e9c07a2d8',
  },
  meta: ABSENT_LICENSE.meta,
}

async function json(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
}

async function problem(route: Route, status: number, type: string, detail: string): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/problem+json',
    body: JSON.stringify({ type, title: 'Problema', status, detail }),
  })
}

export interface OnboardingApiOptions {
  /** Simula que se abandono el asistente justo despues de confirmar el TOTP. */
  administratorAlreadyDone?: boolean
  /** Simula una instalacion cuyo asistente ya se cerro (`POST /setup/complete`). */
  alreadyCompleted?: boolean
  /**
   * Deja unos pasos ya resueltos de partida, para aterrizar directamente en
   * uno de ellos sin recorrer todo el asistente (pensado para las
   * comprobaciones de accesibilidad, que solo necesitan CADA paso por
   * separado, no el recorrido completo repetido una vez por paso).
   */
  stepsDone?: Partial<Record<Exclude<SetupStep, 'administrator' | 'site'>, SetupStepState>>
  /** Igual que `stepsDone`, para los dos pasos DERIVADOS. */
  siteDone?: boolean
}

export interface OnboardingApiStub {
  readonly requests: { method: string; path: string }[]
}

/** Clave de `sessionStorage` del panel (`session.store.ts`). */
const SESSION_STORAGE_KEY = 'kronoqr.admin.session'

export async function stubOnboardingApi(
  page: Page,
  options: OnboardingApiOptions = {},
): Promise<OnboardingApiStub> {
  const requests: { method: string; path: string }[] = []

  let accountConfirmed =
    options.administratorAlreadyDone === true || options.alreadyCompleted === true
  let siteCreated = options.siteDone === true || options.alreadyCompleted === true
  let available = options.alreadyCompleted !== true
  let completedAt: string | null = options.alreadyCompleted === true ? '2026-01-01T00:00:00Z' : null
  const steps: Record<Exclude<SetupStep, 'administrator' | 'site'>, SetupStepState> = {
    organisation: 'pending',
    departments: 'pending',
    compliance_profile: 'pending',
    employees: 'pending',
    license: 'pending',
    kiosk: 'pending',
    ...options.stepsDone,
  }
  const departments: Department[] = []
  let appName = ''
  let credentialsPending = 0
  let licenseActivated = false

  // `administratorAlreadyDone` es exactamente el caso «el administrador ya se
  // creo y confirmo el segundo factor»: en la aplicacion de verdad eso deja un
  // token en `sessionStorage` (`POST /auth/2fa/confirm`). Sin sembrarlo aqui,
  // el panel arrancaria sin sesion y `GET /setup/steps` (autenticada, revision
  // de la 5.5) nunca se pediria: se quedaria en el paso especial del primer
  // administrador en vez de saltar al paso que la prueba quiere comprobar.
  // `alreadyCompleted` NO lo necesita: ese recorrido comprueba que `/setup`
  // remite al acceso, sin llegar a pintar ningun paso.
  if (options.administratorAlreadyDone === true) {
    await page.addInitScript(
      ({ key, token }: { key: string; token: string }) => {
        window.sessionStorage.setItem(
          key,
          JSON.stringify({ token, expiresAt: '2099-01-01T00:00:00Z' }),
        )
      },
      { key: SESSION_STORAGE_KEY, token: SESSION_TOKEN },
    )
  }

  function stepStatusOf(step: SetupStep): SetupStepStatus {
    const state: SetupStepState =
      step === 'administrator'
        ? accountConfirmed
          ? 'completed'
          : 'pending'
        : step === 'site'
          ? siteCreated
            ? 'completed'
            : 'pending'
          : steps[step]

    return { step, state, required: REQUIRED[step], skippable: SKIPPABLE[step] }
  }

  /** `GET /setup/status`: PUBLICA, nunca trae `steps` (revision de la 5.5). */
  function publicStatus() {
    return available
      ? { available: true, completed_at: null }
      : { available: false, completed_at: completedAt }
  }

  /**
   * `GET /setup/steps`, y las respuestas de `PUT /setup/steps/{step}` y
   * `POST /setup/complete`: las tres exigen sesion de administrador y las
   * tres traen `steps` (vacio si el asistente ya se cerro).
   */
  function fullStatus() {
    return available
      ? { available: true, completed_at: null, steps: STEP_ORDER.map(stepStatusOf) }
      : { available: false, completed_at: completedAt, steps: [] }
  }

  function isAuthenticated(route: Route): boolean {
    return route.request().headers()['authorization'] === `Bearer ${SESSION_TOKEN}`
  }

  await page.route(
    (url) => url.pathname.startsWith('/api/v1/'),
    async (route: Route) => {
      const request = route.request()
      const url = new URL(request.url())
      const method = request.method()

      requests.push({ method, path: url.pathname })

      const stepMatch = /^\/api\/v1\/setup\/steps\/([a-z_]+)$/.exec(url.pathname)

      if (stepMatch !== null) {
        const step = stepMatch[1] as Exclude<SetupStep, 'administrator' | 'site'>
        const body = request.postDataJSON() as { state: 'completed' | 'skipped' }

        steps[step] = body.state
        await json(route, 200, fullStatus())

        return
      }

      switch (`${method} ${url.pathname}`) {
        case 'GET /api/v1/setup/status':
          await json(route, 200, publicStatus())
          return
        case 'GET /api/v1/setup/steps':
          // Autenticada (revision de la 5.5): sin `Authorization` responde
          // `401`, igual que el backend real — es justo lo que hace que
          // `setup.store` tenga que pasar por el alta del administrador antes
          // de poder conocer el resto de pasos.
          if (!isAuthenticated(route)) {
            await problem(route, 401, 'urn:kronoqr:problem:unauthenticated', 'No autenticado.')

            return
          }

          await json(route, 200, fullStatus())
          return
        case 'POST /api/v1/setup/administrator': {
          if (accountConfirmed) {
            await problem(
              route,
              409,
              'urn:kronoqr:problem:conflict',
              'Esta instalación ya tiene una cuenta de gestión.',
            )

            return
          }

          const challenge: TwoFactorChallenge = {
            challenge_token: CHALLENGE_TOKEN,
            token_type: 'Bearer',
            expires_at: '2099-01-01T00:10:00Z',
            enrolment_required: true,
          }

          await json(route, 201, challenge)
          return
        }
        case 'POST /api/v1/auth/2fa/enrol':
          await json(route, 200, TWO_FACTOR_ENROLMENT)
          return
        case 'POST /api/v1/auth/2fa/confirm': {
          const body = request.postDataJSON() as { code: string }

          if (body.code !== TOTP_CODE) {
            await problem(
              route,
              401,
              'urn:kronoqr:problem:invalid-credentials',
              'Código incorrecto.',
            )

            return
          }

          accountConfirmed = true
          await json(route, 200, {
            token: SESSION_TOKEN,
            token_type: 'Bearer',
            expires_at: '2099-01-01T00:00:00Z',
            user: {
              uuid: '0199f0aa-1111-7000-8000-0123456789ab',
              name: 'Dirección del hotel',
              email: 'direccion@hotel.example',
              locale: 'es',
              roles: ['admin'],
              abilities: ['*'],
              scope: { kind: 'all', department_ids: [] },
            },
          })
          return
        }
        case 'GET /api/v1/auth/me':
          await json(route, 200, {
            uuid: '0199f0aa-1111-7000-8000-0123456789ab',
            name: 'Dirección del hotel',
            email: 'direccion@hotel.example',
            locale: 'es',
            roles: ['admin'],
            abilities: ['*'],
            scope: { kind: 'all', department_ids: [] },
          })
          return
        case 'POST /api/v1/setup/site': {
          if (siteCreated) {
            await problem(
              route,
              409,
              'urn:kronoqr:problem:conflict',
              'Esta instalación ya tiene su centro de trabajo.',
            )

            return
          }

          siteCreated = true
          await json(route, 201, SITE)
          return
        }
        case 'GET /api/v1/settings':
          await json(route, 200, {
            data: [
              {
                key: 'BRANDING_APP_NAME',
                value: appName,
                type: 'text',
                impact: 'presentation',
                affects_worked_hours: false,
                source: appName === '' ? 'product_default' : 'installation',
              },
              {
                key: 'LOCALE_DEFAULT',
                value: 'es',
                type: 'text',
                impact: 'presentation',
                affects_worked_hours: false,
                source: 'product_default',
              },
              {
                key: 'LOCALE_AVAILABLE',
                value: ['es', 'en'],
                type: 'text_list',
                impact: 'presentation',
                affects_worked_hours: false,
                source: 'product_default',
              },
            ],
            meta: { unknown_keys: [], invalid_keys: [] },
          })
          return
        case 'PATCH /api/v1/settings': {
          const body = request.postDataJSON() as { settings: Record<string, unknown> }

          if (typeof body.settings['BRANDING_APP_NAME'] === 'string') {
            appName = body.settings['BRANDING_APP_NAME']
          }

          await json(route, 200, {
            data: [
              {
                key: 'BRANDING_APP_NAME',
                value: appName,
                type: 'text',
                impact: 'presentation',
                affects_worked_hours: false,
                source: 'installation',
              },
            ],
            meta: { unknown_keys: [], invalid_keys: [] },
          })
          return
        }
        case 'GET /api/v1/departments':
          await json(route, 200, { data: departments })
          return
        case 'POST /api/v1/departments': {
          const body = request.postDataJSON() as { name: string }
          const created: Department = { id: departments.length + 3, name: body.name }

          departments.push(created)
          await json(route, 201, created)
          return
        }
        case 'GET /api/v1/compliance-profile':
          await json(route, 200, {
            data: {
              id: 1,
              name: 'ES-hosteleria',
              jurisdiction: 'ES',
              min_rest_hours: 12,
              max_daily_hours: 9,
              max_weekly_hours: 40,
              break_required_after_hours: 6,
              week_starts_on: 1,
              holiday_calendar: [],
              retention_years: 4,
              is_default: true,
              source: 'installation_default',
              updated_at: null,
            },
          })
          return
        case 'POST /api/v1/employees/import': {
          const raw = request.postData() ?? ''
          const mode = /name="mode"\r\n\r\n(validate|apply)/.exec(raw)?.[1] ?? 'validate'
          const checksum = '3f786850e387550fdab836ed7e6dc881de23001b4f4a1f9d5a2b0a1c2f3e4d5a'

          if (mode === 'apply') {
            credentialsPending += 1
          }

          await json(route, 200, {
            mode,
            file: { sha256: checksum, rows: 1, warnings: [] },
            summary: { create: 1, update: 0, unchanged: 0, reject: 0 },
            truncated: false,
            rows: [
              {
                line: 2,
                label: 'Youssef Amrani',
                outcome: 'create',
                employee_uuid: mode === 'apply' ? '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90' : null,
                changes: [],
                messages: [],
              },
            ],
          })
          return
        }
        case 'GET /api/v1/license':
          await json(route, 200, licenseActivated ? VALID_LICENSE : ABSENT_LICENSE)
          return
        case 'POST /api/v1/license/activate':
          licenseActivated = true
          await json(route, 200, VALID_LICENSE)
          return
        case 'POST /api/v1/setup/complete': {
          const pending = STEP_ORDER.filter((step) => {
            const status = stepStatusOf(step)

            return status.required && status.state === 'pending'
          })

          if (pending.length > 0) {
            await problem(
              route,
              409,
              'urn:kronoqr:problem:conflict',
              `Faltan pasos por resolver: ${pending.join(', ')}.`,
            )

            return
          }

          available = false
          completedAt = '2026-09-02T09:14:00Z'

          await json(route, 200, {
            status: fullStatus(),
            summary: {
              employees: credentialsPending,
              departments: departments.length,
              credentials_pending: credentialsPending,
              license: licenseActivated ? 'valid' : 'absent',
              kiosks: 0,
            },
          })
          return
        }
        case 'GET /api/v1/employees':
          await json(route, 200, {
            data: [],
            meta: { page: 1, per_page: 30, total: 0, total_pages: 1 },
          })
          return
        case 'GET /api/v1/credentials/status':
          await json(route, 200, {
            data: [],
            summary: {
              employees: credentialsPending,
              pending_print: credentialsPending,
              without_delivered_credential: credentialsPending,
              retiring_key_id: null,
              pending_reprint: 0,
              active_unknown_key: 0,
            },
          })
          return
        default:
          await problem(route, 404, 'about:blank', 'Sin doble para esta ruta en el E2E')
      }
    },
  )

  return { requests }
}
