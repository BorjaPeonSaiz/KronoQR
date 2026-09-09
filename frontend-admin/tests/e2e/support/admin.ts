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
import { minutesBetween } from '@kronoqr/web-kit/datetime'
import type {
  AddShiftEntryRequest,
  Branding,
  ComplianceProfile,
  CorrectedShiftEntry,
  CorrectionAction,
  CorrectShiftEntryRequest,
  CreateEmployeeRequest,
  CredentialStatusBoard,
  DataExport,
  DepartmentCollection,
  Device,
  DeviceList,
  Employee,
  EmployeeCollection,
  EmployeeProvisioned,
  EmployeeWorkDays,
  Incident,
  IncidentCollection,
  IssuedSupportGrant,
  License,
  LivePresenceBoard,
  LivePresenceEntry,
  ManagementUser,
  PairingConfirmed,
  PeriodReport,
  Session,
  SetupStatus,
  Site,
  SupportGrant,
  TwoFactorChallenge,
  TwoFactorEnrolment,
  VoidShiftEntryRequest,
  WorkDayDetail,
  WorkDayShiftEntry,
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

/**
 * El estado del asistente de puesta en marcha (RF-PD-03) para el resto de
 * recorridos del panel, que NO son ese asistente: `available: false` es lo
 * que ve una instalacion normal, ya configurada. Sin `steps`: es exactamente
 * lo que responde `GET /setup/status`, que es PUBLICA y nunca los trae
 * (revision de la 5.5) — este doble sirve esa misma ruta. Tampoco
 * `completed_at`, por la misma razon y desde la revision del cierre de la Fase
 * 5. El recorrido propio del asistente (`setup-wizard.spec.ts`) construye su
 * propio `SetupStatus` con pasos pendientes, servido por `GET /setup/steps`.
 */
export const SETUP_STATUS_DONE: SetupStatus = {
  available: false,
}

export const DEPARTMENTS: DepartmentCollection = {
  data: [
    { id: 3, name: 'Recepción' },
    { id: 4, name: 'Pisos' },
  ],
}

/**
 * El perfil de cumplimiento del centro (RF-PD-07, tarea 5.2): lo que responde
 * `GET /api/v1/compliance-profile`. El mismo ejemplo (ES-hosteleria) que ya usa
 * `stubOnboardingApi` en su paso de convenio, para que las dos pantallas -el
 * asistente y `/compliance-profile'- cuenten la misma instalacion.
 */
export const COMPLIANCE_PROFILE: ComplianceProfile = {
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
}

// --- Marca de la instalacion (RF-PD-08, tarea 5.8) --------------------------

/** Lo que responde `GET /api/v1/branding` sin nada configurado: el producto. */
export const PRODUCT_BRANDING: Branding = {
  application_name: 'KronoQR',
  accent_color: null,
  logo_url: null,
  locales: { default: 'es', available: ['es', 'en'] },
}

/** La huella que lleva la URL del logotipo de ejemplo (`?v=`, contrato). */
export const LOGO_DIGEST = '3f9a1c2b7e4d'

/** Un hotel con nombre, color y logotipo propios (el ejemplo del contrato). */
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

// --- Licencia (RF-PD-04, RF-PD-05, tarea 5.3) --------------------------------

/**
 * Un plan sin la funcionalidad de marca propia (`white_label`, ADR-023): lo
 * que devuelve `GET /api/v1/license` cuando no esta contratada. Se usa en
 * `branding.spec.ts` para comprobar el aviso —persistente, no bloqueante— de
 * `BrandingView` (tarea 5.8).
 */
export const LICENSE_WITHOUT_WHITE_LABEL: License = {
  data: {
    state: 'valid',
    severity: 'none',
    rejection_reason: null,
    customer_name: 'Hotel Marina, S.L.',
    plan: 'estandar',
    license_id: 'lic-1',
    valid_from: '2026-01-01T00:00:00.000000Z',
    valid_until: '2027-01-01T00:00:00.000000Z',
    issued_at: '2025-12-15T10:00:00.000000Z',
    days_until_expiry: 90,
    days_since_expiry: null,
    features: [],
    degraded_features: [
      { feature: 'white_label', restriction: 'not_in_plan', since: null, implemented: true },
    ],
    limits: [],
    activated_at: '2026-01-01T09:00:00.000000Z',
    last_verified_at: '2026-01-01T09:00:00.000000Z',
    key_fingerprint: '4b1e9c07a2d8',
  },
  meta: { expiry_warning_days: 30, needs_notice: false, evaluated_at: '2026-01-01T09:00:00Z' },
}

export const USER: ManagementUser = {
  uuid: '0199f0aa-1111-7000-8000-0123456789ab',
  name: 'Dirección RRHH',
  email: 'rrhh@hotel.example',
  locale: 'es',
  roles: ['rrhh'],
  abilities: [
    'attendance:read',
    // Correccion del registro horario (RF-PA-04, tarea 5.11b): alta manual,
    // rectificar y anular. El catalogo real de roles (migracion
    // `seed_role_and_permission_catalog`) se lo da a `rrhh` junto con
    // `attendance:read`; sin este ambito aqui, ningun E2E podria ejercer las
    // tres operaciones con la cuenta que la guia de RRHH usa de principio a fin.
    'attendance:correct',
    'employees:read',
    'employees:*',
    'credentials:*',
    'incidents:*',
    // Informes de gestion (RF-IN-01..03, tarea 2.8). La familia, no el estrecho:
    // `reports:legal` es del `auditor` y solo abre la exportacion de Inspeccion.
    'reports:*',
  ],
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

/**
 * Administrador de instalacion (RF-ID-02): el unico rol que lleva
 * `settings:*` (doc 02 §7.3, nota 5) y por tanto el unico que ve «Quioscos»
 * (RF-PD-06, tarea 5.6). Abilities `['*']`, como el que crea el asistente.
 */
export const ADMIN_USER: ManagementUser = {
  uuid: '0199f0aa-4444-7000-8000-0123456789ae',
  name: 'Dirección del hotel',
  email: 'direccion@hotel.example',
  locale: 'es',
  roles: ['admin'],
  abilities: ['*'],
  scope: { kind: 'all', department_ids: [] },
}

/**
 * Auditor (RF-ID-02): `attendance:read` sin `attendance:correct`. Sirve para
 * comprobar que quien solo puede LEER el registro horario no ve ningun boton
 * de las tres operaciones de correccion (RF-PA-04, tarea 5.11b) — el mismo
 * `attendance:read` con el que se abre `EmployeeWorkDaysView`, y nada mas.
 */
export const AUDITOR_USER: ManagementUser = {
  uuid: '0199f0aa-5555-7000-8000-0123456789af',
  name: 'Auditoría externa',
  email: 'auditoria@hotel.example',
  locale: 'es',
  roles: ['auditor'],
  abilities: ['attendance:read', 'audit:read', 'reports:legal'],
  scope: { kind: 'all', department_ids: [] },
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
  summary: {
    employees: 1,
    pending_print: 1,
    without_delivered_credential: 1,
    retiring_key_id: null,
    pending_reprint: 0,
    active_unknown_key: 0,
  },
}

/** La clave saliente de la rotacion simulada (RF-QR-07). No es un secreto: va impresa en el QR. */
export const RETIRING_KEY_ID = 'a2'

/**
 * El tablero durante una rotacion de clave: 60 personas, 12 de ellas todavia
 * con la tarjeta firmada por la clave saliente. Todas pueden fichar —para eso
 * existe el solape—, asi que `without_delivered_credential` es cero.
 */
export const CREDENTIAL_BOARD_IN_ROTATION: CredentialStatusBoard = {
  data: [],
  summary: {
    employees: 60,
    pending_print: 12,
    without_delivered_credential: 0,
    retiring_key_id: RETIRING_KEY_ID,
    pending_reprint: 12,
    active_unknown_key: 0,
  },
}

/** Lo que devuelve el mismo tablero con `?key_id=`: solo a quien le falta reimprimir. */
export const CREDENTIAL_BOARD_PENDING_REPRINT: CredentialStatusBoard = {
  data: [
    {
      employee_uuid: EMPLOYEE_UUID,
      employee_code: 'E7K2M9QX4B',
      full_name: 'Lucia Martinez Prieto',
      department_name: 'Recepción',
      status: 'delivered',
      credential: {
        uuid: '0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01',
        employee_uuid: EMPLOYEE_UUID,
        key_id: RETIRING_KEY_ID,
        issued_at: '2026-08-19T06:02:31.000000Z',
        printed_at: '2026-08-20T09:11:02.000000Z',
        delivered_at: '2026-08-21T07:40:15.000000Z',
        revoked_at: null,
        revoked_reason: null,
        status: 'active',
      },
    },
  ],
  summary: CREDENTIAL_BOARD_IN_ROTATION.summary,
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
      unavailable_reason: null,
      unavailable_since: null,
    },
  },
}

// --- Informe de horas por periodo (RF-IN-01, RF-IN-02, RF-IN-03) -------------

/**
 * Marzo de una persona con **cambio de contrato el dia 16**: quince dias a 20 h
 * y dieciseis a 40 h. Las cifras son las mismas que comprueba la prueba de
 * feature del backend, para que el recorrido del panel y el calculo del servidor
 * cuenten la misma historia.
 *
 * Las duraciones llegan **ya en `HH:MM`** ademas de en minutos: el panel no
 * formatea horas y no puede hacerlo (regla dura 7).
 */
/**
 * Huella del contenido que publica `GET /reports/period/export` (RF-IN-04).
 *
 * Es la misma para los tres formatos del mismo informe: el servidor la calcula
 * sobre las filas, los criterios y el periodo, no sobre el binario.
 */
export const REPORT_DIGEST = '3d9c8f0a1b2c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6'

export const PERIOD_REPORT: PeriodReport = {
  from: '2026-03-01',
  to: '2026-03-31',
  granularity: 'month',
  group_by: 'employee',
  data: [
    {
      period: { from: '2026-03-01', to: '2026-03-31' },
      subject: {
        kind: 'employee',
        employee_uuid: EMPLOYEE_UUID,
        employee_code: 'E7QK2MXPR',
        full_name: 'Youssef El Amrani',
        department_id: 3,
        label: 'Youssef El Amrani',
      },
      worked_minutes: 9720,
      worked: '162:00',
      shift_count: 21,
      days_in_period: 31,
      days_with_activity: 21,
      days_without_activity: 10,
      open_shift_days: 0,
      incident_days: 1,
      contracted_minutes: 8057,
      contracted: '134:17',
      deviation_minutes: 1663,
      deviation: '27:43',
      overtime_minutes: 1663,
      overtime: '27:43',
      days_without_contract: 0,
    },
  ],
  meta: {
    time_zone: 'Europe/Madrid',
    generated_at: '2026-04-01T07:12:03.114000Z',
    row_count: 1,
    criteria: [
      'Los totales salen del registro horario ya consolidado (proyección de jornadas), no se recalculan para este informe.',
      'Cada turno se atribuye entero a la jornada en la que empezó, en la zona horaria del centro: un turno de 22:00 a 06:00 cuenta en el día de entrada y no se parte a medianoche.',
      'Los días sin actividad aparecen con cero y no se omiten.',
    ],
    contract_coverage: {
      days_without_contract: 0,
      employees_without_contract: 0,
      complete: true,
    },
  },
}

/**
 * Los mismos criterios en ingles (hallazgo 7 de la revision: iban fijos en
 * castellano para los dos idiomas). El mismo numero de filas que
 * `PERIOD_REPORT.meta.criteria`, en el mismo orden: es lo unico que comprueba
 * `period-report.spec.ts`.
 */
const PERIOD_REPORT_CRITERIA_EN: readonly string[] = [
  'Totals come from the time record already consolidated (working-day projection); they are not recalculated for this report.',
  "Each shift is attributed in full to the working day it started, in the site's time zone: a shift from 22:00 to 06:00 counts on the day it clocked in and is not split at midnight.",
  'Days without activity appear with zero and are not omitted.',
]

/** `PERIOD_REPORT`, con los criterios en el idioma que pide la peticion. */
function periodReportFor(requestLocale: 'es' | 'en'): PeriodReport {
  if (requestLocale === 'es') {
    return PERIOD_REPORT
  }

  return {
    ...PERIOD_REPORT,
    meta: { ...PERIOD_REPORT.meta, criteria: [...PERIOD_REPORT_CRITERIA_EN] },
  }
}

/**
 * El idioma que la SPA manda en `Accept-Language` (`setLocaleProvider` de
 * `@kronoqr/web-kit/http`, atado al `locale` de la sesion tras
 * `stubManagementApi(page, { locale })`). Por omision `es`, igual que el resto
 * del doble.
 */
function requestLocaleOf(request: import('@playwright/test').Request): 'es' | 'en' {
  return request.headers()['accept-language']?.startsWith('en') === true ? 'en' : 'es'
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

// --- Quioscos y emparejamiento por codigo (RF-PA-07, RF-PD-06, tarea 5.6) ---

export const DEVICE_UUID = '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81'

export const DEVICE: Device = {
  uuid: DEVICE_UUID,
  name: 'Recepción',
  status: 'active',
  app_version: '1.4.2',
  last_seen_at: '2026-09-07T09:59:41.000000Z',
  pending_queue_size: 0,
  paired_at: '2026-09-01T08:12:00.000000Z',
}

export const DEVICES: DeviceList = { devices: [DEVICE] }

/** El codigo que el doble acepta en `POST /kiosk/pair/confirm`. Cualquier otro se rechaza. */
export const PAIRING_CODE = '483921'

/** Lo que declaro la tablet al pedir el codigo (`PairingConfirmed.request`), para contrastar. */
const PAIRING_REQUEST = {
  app_version: '1.4.3',
  requested_at: '2026-09-07T09:55:00.000000Z',
}

// --- Soporte: paquete de diagnostico y accesos temporales (RF-PD-09,
// RF-PD-11, tarea 5.9, ADR-020) ----------------------------------------------

export const SUPPORT_GRANT_UUID = '0199f4d0-1a2b-7c3d-9e4f-5a6b7c8d9e01'
export const SUPPORT_GRANT_REVOKED_UUID = '0199f4d0-2b3c-7d4e-9f5a-6b7c8d9e0a12'

/** El token que el doble emite al conceder (`POST /support/grants`). Solo viaja una vez. */
export const ISSUED_SUPPORT_TOKEN = '23|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ'

/** Una concesion activa, con alcance `diagnostics` y sin usar todavia. */
export const SUPPORT_GRANT_ACTIVE: SupportGrant = {
  uuid: SUPPORT_GRANT_UUID,
  status: 'active',
  scope: 'diagnostics',
  reason: 'Incidencia #123: la cola del quiosco de recepción no vacía',
  granted_by: { uuid: ADMIN_USER.uuid, name: ADMIN_USER.name },
  granted_at: '2026-09-08T09:00:00.000000Z',
  expires_at: '2026-09-09T09:00:00.000000Z',
  revoked_at: null,
  accessed_at: null,
}

/** La misma concesion, ya revocada: no desaparece de la lista (regla dura 5). */
export const SUPPORT_GRANT_REVOKED: SupportGrant = {
  ...SUPPORT_GRANT_ACTIVE,
  uuid: SUPPORT_GRANT_REVOKED_UUID,
  status: 'revoked',
  reason: 'Incidencia #98: revision del informe de horas de febrero',
  revoked_at: '2026-09-08T10:00:00.000000Z',
}

/**
 * Lo que devuelve `POST /api/v1/support/grants` (RF-PD-11): la concesion recien
 * creada, **con el token en claro**. Es la unica vez que sale del servidor; el
 * doble de `stubManagementApi` construye una copia con un `uuid` nuevo por cada
 * llamada real, y esta constante sirve para las pruebas que solo necesitan un
 * ejemplo suelto (sin pasar por el flujo completo de conceder).
 */
export const ISSUED_SUPPORT_GRANT: IssuedSupportGrant = {
  data: { ...SUPPORT_GRANT_ACTIVE, token: ISSUED_SUPPORT_TOKEN },
}

/** El nombre de fichero que trae `Content-Disposition` de `POST /diagnostics/bundle`. */
export const DIAGNOSTICS_BUNDLE_FILENAME = 'kronoqr-diagnostics-2.2.0-20260908T101500Z.json'

// --- Exportacion integra de datos (RF-PD-14, RL-20, tarea 5.10) -------------

/** El `uuid` que el doble asigna a la exportacion que crea `POST /api/v1/data-export`. */
export const DATA_EXPORT_UUID = '0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f61'

/** El nombre de fichero que trae `Content-Disposition` de la descarga, ya completada. */
export const DATA_EXPORT_FILENAME = 'kronoqr-export-2.2.0-20260908T101500Z.zip'

/** Una exportacion ya `completed`, lista para descargar. Para las pruebas que no pasan por «pedir». */
export const DATA_EXPORT_COMPLETED: DataExport = {
  uuid: DATA_EXPORT_UUID,
  status: 'completed',
  requested_via: 'panel',
  requested_by: { uuid: ADMIN_USER.uuid, name: ADMIN_USER.name },
  requested_at: '2026-09-08T10:15:00.000000Z',
  started_at: '2026-09-08T10:15:02.000000Z',
  completed_at: '2026-09-08T10:16:40.000000Z',
  failed_at: null,
  failure_reason: null,
  file_name: DATA_EXPORT_FILENAME,
  size_bytes: 15_728_640,
  sha256: '0'.repeat(64),
  row_counts: { employees: 62, shift_entries: 48210, audit_log: 12045 },
  expires_at: '2026-09-15T10:16:40.000000Z',
  purged_at: null,
  downloaded_at: null,
  download_count: 0,
}

/**
 * La misma exportacion, pero todavia `running`: para la prueba que comprueba
 * que, con una ya en curso, el boton de generar aparece deshabilitado y con
 * su explicacion, sin tener que pasar por el recorrido completo.
 */
export const DATA_EXPORT_RUNNING: DataExport = {
  ...DATA_EXPORT_COMPLETED,
  status: 'running',
  completed_at: null,
  file_name: null,
  size_bytes: null,
  sha256: null,
  row_counts: {},
  expires_at: null,
}

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
   * `incidents:*` y sin plantilla ni credenciales (RF-ID-03); `admin` es
   * `ADMIN_USER`, el unico que ve «Quioscos» (RF-PD-06, tarea 5.6); `auditor`
   * es `AUDITOR_USER`, con `attendance:read` y sin `attendance:correct` (RF-ID-02,
   * tarea 5.11b): ve el registro horario y ningun boton de sus tres correcciones.
   */
  readonly role?: 'rrhh' | 'manager' | 'admin' | 'auditor'
  /**
   * El idioma de la cuenta que entra (`session.user.locale`). Por omision,
   * `'es'`, como las tres cuentas de ejemplo. `main.ts` adopta este idioma
   * para TODA la aplicacion en cuanto hay sesion -no el del navegador-, asi
   * que un generador de capturas con un proyecto `en` (locale del navegador
   * `en-US`, pantalla de `/login` en ingles por `resolveLocale`) necesita
   * este campo para que el resto de pantallas, YA autenticadas, tambien
   * salgan en ingles: sin el, la sesion volveria al español en el primer
   * repintado tras entrar.
   */
  readonly locale?: 'es' | 'en'
  /**
   * La flota de quioscos que devuelve `GET /devices` de partida (RF-PA-07). Por
   * omision, `DEVICES`: un unico quiosco activo, «Recepción». El doble la
   * mantiene mutable: `confirm` añade o reactiva, `unpair` revoca.
   */
  readonly devices?: DeviceList
  /**
   * Que responde `POST /incidents/{id}/resolve`. `ok` (por omision) cierra la
   * incidencia y la devuelve entera. `conflict` simula que otra persona se
   * adelanto: la peticion responde `409` y las relecturas posteriores devuelven
   * la incidencia ya cerrada por `INCIDENT_CLOSED_BY_OTHER`.
   */
  readonly resolveOutcome?: 'ok' | 'conflict'
  /** El registro horario que devuelve `GET /employees/{uuid}/workdays`. Por omision, `WORKDAYS`. */
  readonly workdays?: EmployeeWorkDays
  /**
   * Como responde `GET /reports/period/export` (RF-IN-04). `forbidden` sirve
   * para el recorrido en el que la descarga se deniega **despues** de haber
   * generado el informe: es lo que pasa si a alguien le retiran el ambito con la
   * pantalla abierta, y el panel tiene que decirlo en vez de quedarse pensando.
   */
  readonly exportOutcome?: 'ok' | 'forbidden'
  /**
   * El tablero de credenciales. Por omision, `CREDENTIAL_BOARD`: sin ninguna
   * rotacion de clave abierta, que es el estado normal (RF-QR-07).
   */
  readonly credentialBoard?: CredentialStatusBoard
  /**
   * Lo que devuelve el tablero cuando se pide con `?key_id=`: solo quien sigue
   * fichando con la clave saliente. Por omision, el mismo que sin filtro.
   */
  readonly credentialBoardByKey?: CredentialStatusBoard
  /**
   * Lo que devuelve `GET /api/v1/setup/status` (RF-PD-03). Por omision,
   * `SETUP_STATUS_DONE`: los recorridos que no son el propio asistente
   * suceden en una instalacion ya configurada, y sin este doble la guarda de
   * rutas nueva mandaria cualquier prueba existente a `/setup`.
   */
  readonly setupStatus?: SetupStatus
  /**
   * Lo que devuelve `GET /api/v1/branding` (RF-PD-08, tarea 5.8), publica y
   * sin sesion. Por omision, `PRODUCT_BRANDING`: la instalacion recien
   * instalada, sin nada configurado. `PATCH /api/v1/settings` la actualiza en
   * el acto cuando cambia alguna de las tres claves `BRANDING_*`, para que
   * `BrandingView` pueda comprobar que la cabecera se refresca tras guardar.
   */
  readonly branding?: Branding
  /**
   * Los umbrales operativos (`ATTENDANCE_*`) y los idiomas (`LOCALE_*`) que
   * completan el catalogo de `GET/PATCH /api/v1/settings` (RF-PD-01, tarea
   * 5.13, `OperationalSettingsView`). Por omision, los valores de serie del
   * producto (12, 60, 15, 120, `es`, `[es, en]`): una instalacion recien
   * puesta en marcha, sin nada configurado. El doble los mantiene mutables y
   * los valida con el mismo rango que declara `SettingKey` en el backend, para
   * que la prueba de «validacion de rango» reciba un `422` de verdad.
   */
  readonly operationalSettings?: {
    readonly maxShiftHours?: number
    readonly debounceSeconds?: number
    readonly maxClockSkewMinutes?: number
    readonly minTransitSeconds?: number
    readonly localeDefault?: string
    readonly localeAvailable?: string[]
  }
  /**
   * Lo que devuelve `GET /api/v1/license` (RF-PD-04, RF-PD-05, tarea 5.3).
   * Sin este campo, la ruta responde `404` -tal y como hacia antes de que
   * existiera este doble-: `LicenseNotice`/`BrandingView` lo tratan como «sin
   * datos de licencia» y no enseñan nada, que es el estado por omision de
   * casi todos los recorridos del panel. Se usa, por ejemplo, para que
   * `BrandingView` avise cuando `white_label` (ADR-023) esta fuera del plan
   * o la licencia ha caducado (tarea 5.8).
   */
  readonly license?: License
  /**
   * Lo que devuelve `GET /api/v1/compliance-profile` (RF-PD-07, tarea 5.2).
   * Por omision, `COMPLIANCE_PROFILE`: el perfil ES-hosteleria de la
   * instalacion de referencia.
   */
  readonly complianceProfile?: ComplianceProfile
  /**
   * Las concesiones de soporte que devuelve `GET /api/v1/support/grants`
   * (RF-PD-11, tarea 5.9) al arrancar. Por omision, ninguna: la mayoria de
   * los recorridos del panel no pasan por «Soporte». El doble la mantiene
   * mutable, para que conceder y revocar la vayan cambiando exactamente como
   * lo haria el servidor.
   */
  readonly supportGrants?: SupportGrant[]
  /**
   * Las exportaciones integras que devuelve `GET /api/v1/data-export` de
   * partida (RF-PD-14, RL-20, tarea 5.10). Por omision, ninguna. El doble la
   * mantiene mutable: `POST` crea una fila `pending` que el propio doble hace
   * progresar sola a `running` y luego a `completed` pasados unos segundos
   * reales, para que el sondeo de 5 s del panel tenga algo que enseñar sin
   * depender de un backend.
   */
  readonly dataExports?: DataExport[]
  /**
   * Como responden `POST /api/v1/shift-entries` y `PATCH
   * /api/v1/shift-entries/{uuid}` (RF-PA-04, tarea 5.11b, segunda vuelta). `ok`
   * (por omision) corrige o da de alta de verdad. Las otras cuatro simulan las
   * causas que puede dar un `409`/`422` real, cada una con el `type` propio
   * que le da el backend (bloque A2, en paralelo a esta tarea) -sea cual sea
   * el `uuid`, y el registro horario no cambia-, para probar que el dialogo
   * distingue las tres causas del `409` (regla dura 5) y no confunde el `422`
   * de cambio de jornada con el resto:
   *
   *  - `superseded`: la version vigente ya no es la que tenia el dialogo
   *    abierto (`urn:kronoqr:problem:shift-entry-superseded`).
   *  - `shiftAlreadyOpen`: esa persona ya tiene un turno abierto (RN-01,
   *    `urn:kronoqr:problem:shift-already-open`) -la causa mas probable al
   *    «Añadir un tramo» por un olvido de salida.
   *  - `overlap`: las horas se solapan con otro tramo (RN-02,
   *    `urn:kronoqr:problem:overlapping-shift-entry`).
   *  - `workDateChange`: SOLO en `PATCH` (`correct`); corregir la entrada
   *    moveria la jornada a otro dia (RN-05, ADR-035,
   *    `urn:kronoqr:problem:correction-would-change-work-date`, `422`).
   */
  readonly correctionOutcome?:
    'ok' | 'superseded' | 'shiftAlreadyOpen' | 'overlap' | 'workDateChange'
}

async function json(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
}

async function problem(
  route: Route,
  status: number,
  type: string,
  title: string,
  detail?: string,
): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/problem+json',
    body: JSON.stringify({ type, title, status, ...(detail === undefined ? {} : { detail }) }),
  })
}

/**
 * El `422` de validacion por campo (`ValidationProblem` del contrato), para
 * simular el de RN-05/ADR-035 (`urn:kronoqr:problem:correction-would-change-work-date`,
 * tarea 5.11b): corregir la entrada moveria la jornada a otro dia.
 */
async function validationProblem(
  route: Route,
  type: string,
  title: string,
  errors: Record<string, string[]>,
): Promise<void> {
  await route.fulfill({
    status: 422,
    contentType: 'application/problem+json',
    body: JSON.stringify({ type, title, status: 422, errors }),
  })
}

// --- Correccion del registro horario (RF-PA-04, RN-13, ADR-026, ADR-035,
// tarea 5.11b) ----------------------------------------------------------------
//
// El momento fijo de toda correccion simulada en esta suite: posterior al
// unico que trae `WORKDAYS` (14 de marzo, 16:22 hora del centro), para que un
// historial con dos entradas siga leyendose de mas antigua a mas reciente.
const CORRECTION_NOW = '2026-03-20T09:00:00.000000Z'

/** `LocalTimestamp` (`readLocalTimestamp` de `@kronoqr/web-kit/datetime`) para un instante UTC en `timeZone`. Sin libreria de zonas, mismo criterio que `zonedTime.ts` del panel: `Intl.DateTimeFormat`. */
function toLocalTimestamp(utcIso: string, timeZone: string): string {
  const instant = new Date(utcIso)
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).formatToParts(instant)
  const value = (type: string): string => parts.find((part) => part.type === type)?.value ?? '00'
  const offsetParts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    timeZoneName: 'longOffset',
  }).formatToParts(instant)
  const offsetRaw = offsetParts.find((part) => part.type === 'timeZoneName')?.value ?? 'GMT'
  const offset = /^GMT([+-]\d{2}:\d{2})$/.exec(offsetRaw)?.[1] ?? '+00:00'

  return `${value('year')}-${value('month')}-${value('day')}T${value('hour')}:${value('minute')}:${value('second')}${offset}`
}

let syntheticShiftEntrySeed = 0

/** Un `uuid` con forma de UUID: distinto en cada llamada, y valido para la regex `[0-9a-f-]+` de las rutas. */
function syntheticShiftEntryUuid(): string {
  syntheticShiftEntrySeed += 1

  return `0199f7c1-${String(syntheticShiftEntrySeed).padStart(4, '0')}-7a10-9c50-6d7e8f9a0b11`
}

function findShiftEntry(
  workdays: EmployeeWorkDays,
  uuid: string,
): { day: WorkDayDetail; entry: WorkDayShiftEntry } | undefined {
  for (const day of workdays.data) {
    const entry = day.shift_entries.find((candidate) => candidate.uuid === uuid)

    if (entry !== undefined) {
      return { day, entry }
    }
  }

  return undefined
}

function ensureWorkDay(workdays: EmployeeWorkDays, workDate: string): WorkDayDetail {
  const existing = workdays.data.find((candidate) => candidate.work_date === workDate)

  if (existing !== undefined) {
    return existing
  }

  const created: WorkDayDetail = {
    work_date: workDate,
    time_zone: workdays.time_zone,
    total_minutes: 0,
    shift_count: 0,
    has_open_shift: false,
    has_incident: false,
    recalculated_at: null,
    shift_entries: [],
    corrections: [],
    incidents: [],
  }

  workdays.data.push(created)
  workdays.data.sort((a, b) => a.work_date.localeCompare(b.work_date))

  return created
}

/** Recalcula el total del dia sobre sus tramos vigentes (RN-06, regla dura 7): nunca se acumula. */
function recalcWorkDay(day: WorkDayDetail): void {
  day.shift_count = day.shift_entries.length
  day.total_minutes = day.shift_entries.reduce(
    (sum, entry) => sum + (entry.duration_minutes ?? 0),
    0,
  )
  day.has_open_shift = day.shift_entries.some((entry) => entry.status === 'open')
  day.recalculated_at = CORRECTION_NOW
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
  const exportOutcome = options.exportOutcome ?? 'ok'
  const correctionOutcome = options.correctionOutcome ?? 'ok'
  const baseUser =
    options.role === 'manager'
      ? MANAGER_USER
      : options.role === 'admin'
        ? ADMIN_USER
        : options.role === 'auditor'
          ? AUDITOR_USER
          : USER
  // Copia, nunca la constante compartida: `options.locale` no puede filtrarse
  // a otra prueba que reutilice `USER`/`MANAGER_USER`/`ADMIN_USER`/`AUDITOR_USER` tal cual.
  const currentUser: ManagementUser =
    options.locale === undefined ? baseUser : { ...baseUser, locale: options.locale }
  const currentSession: Session = { ...SESSION, user: currentUser }

  // Si `resolveOutcome` es `conflict`, la incidencia se da por cerrada -por
  // otra persona- justo cuando llega el `POST /resolve`: antes de eso la
  // bandeja la sigue enseñando abierta, que es lo que hace falta para que la
  // prueba pueda pulsar «Resolver».
  let incidentClosed = false

  // La flota de quioscos (RF-PA-07, RF-PD-06): mutable, para que `confirm` y
  // `unpair` la vayan cambiando exactamente como lo haria el servidor.
  // Copia PROFUNDA de cada fila, no solo del array: `confirmUnpair` muta
  // `target.status` en sitio, y sin esto esa mutacion se colaria en el
  // objeto compartido `DEVICE`/`DEVICES` y contaminaria el resto de pruebas
  // de este fichero (un solo proceso, `DEVICE` es el mismo modulo para todas).
  const devices: Device[] = (options.devices?.devices ?? DEVICES.devices).map((candidate) => ({
    ...candidate,
  }))

  // Los accesos de soporte (RF-PD-11, tarea 5.9): mutable, para que conceder y
  // revocar la vayan cambiando exactamente como lo haria el servidor. Vacia
  // por omision: la mayoria de los recorridos no pasan por «Soporte».
  const supportGrants: SupportGrant[] = (options.supportGrants ?? []).map((candidate) => ({
    ...candidate,
  }))

  // El registro horario (RF-PA-03, RF-PA-04, tarea 5.11b): mutable, y con
  // copia PROFUNDA de `options.workdays ?? WORKDAYS` -no solo del array-,
  // porque las tres operaciones de correccion mutan jornadas y tramos en
  // sitio; sin esta copia, una prueba dejaria su tramo añadido o corregido
  // dentro de la constante compartida y contaminaria el resto del fichero.
  const workdaysState: EmployeeWorkDays = structuredClone(options.workdays ?? WORKDAYS)

  // La exportacion integra (RF-PD-14, RL-20, tarea 5.10): mutable, y con una
  // progresion de estado atada al reloj de VERDAD (no al de Playwright), para
  // que el sondeo de 5 s del panel encuentre algo que cambie sin que este
  // doble tenga que saber cuantas veces se le ha preguntado.
  const dataExports: DataExport[] = (options.dataExports ?? []).map((candidate) => ({
    ...candidate,
  }))
  const dataExportCreatedAt = new Map<string, number>()

  /** El estado de cada fila EN ESTE INSTANTE: pending -> running -> completed. */
  function currentDataExports(): DataExport[] {
    const now = Date.now()

    return dataExports.map((row) => {
      const createdAt = dataExportCreatedAt.get(row.uuid)

      if (createdAt === undefined) {
        return row
      }

      const elapsedMs = now - createdAt

      if (elapsedMs < 1_500) {
        return { ...row, status: 'pending' }
      }

      if (elapsedMs < 5_500) {
        return { ...row, status: 'running', started_at: '2026-09-08T10:15:02.000000Z' }
      }

      return {
        ...row,
        status: 'completed',
        started_at: '2026-09-08T10:15:02.000000Z',
        completed_at: '2026-09-08T10:16:40.000000Z',
        file_name: DATA_EXPORT_FILENAME,
        size_bytes: 15_728_640,
        sha256: '0'.repeat(64),
        row_counts: { employees: 62, shift_entries: 48210, audit_log: 12045 },
        expires_at: '2026-09-15T10:16:40.000000Z',
      }
    })
  }

  // La marca de la instalacion (RF-PD-08, tarea 5.8): mutable, para que
  // `PATCH /api/v1/settings` la actualice exactamente como lo haria el
  // servidor y `GET /api/v1/branding` -que `branding.store.load()` vuelve a
  // pedir tras guardar- devuelva el cambio en el acto.
  let branding: Branding = { ...(options.branding ?? PRODUCT_BRANDING) }
  let logoPath = branding.logo_url === null ? '' : '/var/kronoqr/branding/logo.png'

  // El perfil de cumplimiento (RF-PD-07, tarea 5.2): mutable, para que
  // `PATCH /api/v1/compliance-profile` lo actualice exactamente como lo haria
  // el servidor y `ComplianceProfileView` pueda comprobar que un umbral
  // guardado sigue ahi tras recargar.
  let complianceProfile: ComplianceProfile = structuredClone(
    options.complianceProfile ?? COMPLIANCE_PROFILE,
  )

  // Los umbrales operativos y los idiomas (RF-PD-01, tarea 5.13): mutables,
  // con los mismos valores de serie y el mismo rango que `SettingKey` declara
  // en el backend (12/1-24, 60/0-3600, 15/1-1440, 120/0-3600, `es`/`[es, en]`).
  let attendanceMaxShiftHours = options.operationalSettings?.maxShiftHours ?? 12
  let attendanceDebounceSeconds = options.operationalSettings?.debounceSeconds ?? 60
  let attendanceMaxClockSkewMinutes = options.operationalSettings?.maxClockSkewMinutes ?? 15
  let attendanceMinTransitSeconds = options.operationalSettings?.minTransitSeconds ?? 120
  let localeDefault = options.operationalSettings?.localeDefault ?? 'es'
  let localeAvailable = options.operationalSettings?.localeAvailable ?? ['es', 'en']

  /** El catalogo completo de `installation_settings`, con la forma de `GET/PATCH /settings`. */
  function settingsCatalog(): unknown {
    return {
      data: [
        {
          key: 'ATTENDANCE_MAX_SHIFT_HOURS',
          value: attendanceMaxShiftHours,
          type: 'integer',
          impact: 'compliance_review',
          affects_worked_hours: false,
          source: attendanceMaxShiftHours === 12 ? 'product_default' : 'installation',
          constraints: { minimum: 1, maximum: 24 },
        },
        {
          key: 'ATTENDANCE_DEBOUNCE_SECONDS',
          value: attendanceDebounceSeconds,
          type: 'integer',
          impact: 'worked_hours',
          affects_worked_hours: true,
          source: attendanceDebounceSeconds === 60 ? 'product_default' : 'installation',
          constraints: { minimum: 0, maximum: 3600 },
        },
        {
          key: 'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES',
          value: attendanceMaxClockSkewMinutes,
          type: 'integer',
          impact: 'compliance_review',
          affects_worked_hours: false,
          source: attendanceMaxClockSkewMinutes === 15 ? 'product_default' : 'installation',
          constraints: { minimum: 1, maximum: 1440 },
        },
        {
          key: 'ATTENDANCE_MIN_TRANSIT_SECONDS',
          value: attendanceMinTransitSeconds,
          type: 'integer',
          impact: 'compliance_review',
          affects_worked_hours: false,
          source: attendanceMinTransitSeconds === 120 ? 'product_default' : 'installation',
          constraints: { minimum: 0, maximum: 3600 },
        },
        {
          key: 'BRANDING_APP_NAME',
          value: branding.application_name,
          type: 'text',
          impact: 'presentation',
          affects_worked_hours: false,
          source:
            branding.application_name === PRODUCT_BRANDING.application_name
              ? 'product_default'
              : 'installation',
          constraints: { maximum_length: 60 },
        },
        {
          key: 'BRANDING_ACCENT_COLOR',
          value: branding.accent_color ?? '#b8542a',
          type: 'text',
          impact: 'presentation',
          affects_worked_hours: false,
          source: branding.accent_color === null ? 'product_default' : 'installation',
          constraints: { pattern: '^#[0-9a-fA-F]{6}$' },
        },
        {
          key: 'BRANDING_LOGO_PATH',
          value: logoPath,
          type: 'text',
          impact: 'presentation',
          affects_worked_hours: false,
          source: logoPath === '' ? 'product_default' : 'installation',
        },
        {
          key: 'LOCALE_DEFAULT',
          value: localeDefault,
          type: 'text',
          impact: 'presentation',
          affects_worked_hours: false,
          source: localeDefault === 'es' ? 'product_default' : 'installation',
          constraints: { allowed: ['es', 'en'] },
        },
        {
          key: 'LOCALE_AVAILABLE',
          value: localeAvailable,
          type: 'text_list',
          impact: 'presentation',
          affects_worked_hours: false,
          source:
            JSON.stringify([...localeAvailable].sort()) === JSON.stringify(['en', 'es'])
              ? 'product_default'
              : 'installation',
          constraints: { allowed: ['es', 'en'] },
        },
      ],
      meta: { unknown_keys: [], invalid_keys: [] },
    }
  }

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

      // La desvinculacion tambien lleva un `uuid` dinamico en la ruta
      // (`/devices/{uuid}/unpair`, RF-PD-06).
      const unpairMatch = /^\/api\/v1\/devices\/([0-9a-f-]+)\/unpair$/.exec(url.pathname)

      if (method === 'POST' && unpairMatch !== null) {
        const target = devices.find((candidate) => candidate.uuid === unpairMatch[1])

        if (target === undefined) {
          await problem(route, 404, 'urn:kronoqr:problem:not-found', 'Quiosco no encontrado')

          return
        }

        target.status = 'revoked'
        await json(route, 200, target)
        return
      }

      // Revocar tambien lleva un `uuid` dinamico en la ruta
      // (`DELETE /support/grants/{uuid}`, RF-PD-11).
      const revokeGrantMatch = /^\/api\/v1\/support\/grants\/([0-9a-f-]+)$/.exec(url.pathname)

      if (method === 'DELETE' && revokeGrantMatch !== null) {
        const target = supportGrants.find((candidate) => candidate.uuid === revokeGrantMatch[1])

        if (target === undefined) {
          await problem(route, 404, 'urn:kronoqr:problem:not-found', 'Concesión no encontrada')

          return
        }

        // Idempotente (el contrato lo garantiza): revocar una ya revocada
        // vuelve a responder 204 sin cambiar la fecha de revocacion.
        if (target.revoked_at === null) {
          target.status = 'revoked'
          target.revoked_at = '2026-09-08T10:30:00.000000Z'
        }

        await route.fulfill({ status: 204 })
        return
      }

      // La descarga tambien lleva un `uuid` dinamico en la ruta
      // (`GET /data-export/{uuid}/download`, RF-PD-14, RL-20).
      const dataExportDownloadMatch = /^\/api\/v1\/data-export\/([0-9a-f-]+)\/download$/.exec(
        url.pathname,
      )

      if (method === 'GET' && dataExportDownloadMatch !== null) {
        const target = currentDataExports().find(
          (candidate) => candidate.uuid === dataExportDownloadMatch[1],
        )

        if (target === undefined) {
          await problem(route, 404, 'urn:kronoqr:problem:not-found', 'Exportación no encontrada')

          return
        }

        if (target.status !== 'completed') {
          await route.fulfill({
            status: 409,
            contentType: 'application/problem+json',
            body: JSON.stringify({
              type: 'urn:kronoqr:problem:data-export-not-ready',
              title: 'La exportación todavía no ha terminado',
              status: 409,
              detail: 'Sigue en curso. Vuelve a consultarla dentro de unos segundos.',
            }),
          })

          return
        }

        const stored = dataExports.find((candidate) => candidate.uuid === target.uuid)

        if (stored !== undefined) {
          stored.downloaded_at = '2026-09-08T10:20:00.000000Z'
          stored.download_count += 1
        }

        await route.fulfill({
          status: 200,
          contentType: 'application/zip',
          headers: {
            'Content-Disposition': `attachment; filename=${target.file_name ?? DATA_EXPORT_FILENAME}`,
            'X-Kronoqr-Export-Sha256': target.sha256 ?? '0'.repeat(64),
            'X-Kronoqr-Export-Rows': '60317',
          },
          body: Buffer.from('contenido-de-prueba-del-zip-de-exportacion'),
        })

        return
      }

      // Corregir tambien lleva un `uuid` dinamico en la ruta
      // (`PATCH /shift-entries/{uuid}`, RF-PA-04, ADR-035).
      const correctShiftEntryMatch = /^\/api\/v1\/shift-entries\/([0-9a-f-]+)$/.exec(url.pathname)

      if (method === 'PATCH' && correctShiftEntryMatch !== null) {
        if (correctionOutcome === 'superseded') {
          await problem(
            route,
            409,
            'urn:kronoqr:problem:shift-entry-superseded',
            'Conflicto con el estado actual',
            'Ese tramo ya no es la version vigente. Vuelve a cargar la jornada antes de corregir.',
          )

          return
        }

        if (correctionOutcome === 'shiftAlreadyOpen') {
          await problem(
            route,
            409,
            'urn:kronoqr:problem:shift-already-open',
            'Conflicto con el estado actual',
            'Esa persona ya tiene un turno abierto. Cierralo o anulalo antes de dejar otro sin salida.',
          )

          return
        }

        if (correctionOutcome === 'overlap') {
          await problem(
            route,
            409,
            'urn:kronoqr:problem:overlapping-shift-entry',
            'Conflicto con el estado actual',
            'Las horas indicadas se solapan con otro tramo de esa persona. Revisa la jornada antes de corregir.',
          )

          return
        }

        if (correctionOutcome === 'workDateChange') {
          await validationProblem(
            route,
            'urn:kronoqr:problem:correction-would-change-work-date',
            'No se puede procesar la solicitud',
            {
              clocked_in_at: [
                'Esa hora de entrada llevaria la jornada a otro dia. Para mover las horas de un ' +
                  'dia a otro, anula el tramo en la jornada de origen y dalo de alta en la de ' +
                  'destino: son dos acciones, cada una con su motivo.',
              ],
            },
          )

          return
        }

        const targetUuid = correctShiftEntryMatch[1] ?? ''
        const found = findShiftEntry(workdaysState, targetUuid)

        if (found === undefined) {
          // Ese `uuid` existio y ya no es la version vigente -o nunca existio
          // en este doble-: `409`, nunca `404` (ADR-035).
          await problem(
            route,
            409,
            'urn:kronoqr:problem:shift-entry-superseded',
            'Conflicto con el estado actual',
            'Ese tramo ya no es la version vigente. Vuelve a cargar la jornada antes de corregir.',
          )

          return
        }

        const { day, entry } = found
        const payload = request.postDataJSON() as CorrectShiftEntryRequest
        const newClockedIn = payload.clocked_in_at ?? entry.clocked_in_at
        const newClockedOut =
          payload.clocked_out_at !== undefined ? payload.clocked_out_at : entry.clocked_out_at
        const wasOpen = entry.clocked_out_at === null
        const action: CorrectionAction = wasOpen && newClockedOut !== null ? 'closed' : 'modified'
        const newUuid = syntheticShiftEntryUuid()
        const durationMinutes =
          newClockedOut === null ? null : minutesBetween(newClockedIn, newClockedOut)

        const newEntry: WorkDayShiftEntry = {
          ...entry,
          uuid: newUuid,
          version: entry.version + 1,
          status: newClockedOut === null ? 'open' : 'closed',
          clocked_in_at: newClockedIn,
          clocked_in_at_local:
            payload.clocked_in_at === undefined
              ? entry.clocked_in_at_local
              : toLocalTimestamp(newClockedIn, day.time_zone),
          clock_in_source:
            payload.clocked_in_at === undefined ? entry.clock_in_source : 'manual_admin',
          clocked_in_recorded_at:
            payload.clocked_in_at === undefined ? entry.clocked_in_recorded_at : null,
          clocked_out_at: newClockedOut,
          clocked_out_at_local:
            newClockedOut === null
              ? null
              : payload.clocked_out_at === undefined
                ? entry.clocked_out_at_local
                : toLocalTimestamp(newClockedOut, day.time_zone),
          clock_out_source:
            newClockedOut === null
              ? null
              : payload.clocked_out_at === undefined
                ? entry.clock_out_source
                : 'manual_admin',
          clocked_out_recorded_at:
            payload.clocked_out_at === undefined ? entry.clocked_out_recorded_at : null,
          duration_minutes: durationMinutes,
          recorded_at: CORRECTION_NOW,
        }

        day.shift_entries = day.shift_entries.filter((candidate) => candidate.uuid !== entry.uuid)
        day.shift_entries.push(newEntry)
        day.corrections.push({
          shift_entry_uuid: newUuid,
          action,
          performed_at: CORRECTION_NOW,
          performed_at_local: toLocalTimestamp(CORRECTION_NOW, day.time_zone),
          performed_by: { uuid: currentUser.uuid, name: currentUser.name },
          reason_code: payload.reason_code,
          reason_text: payload.reason_text ?? null,
          before: {
            version: entry.version,
            clocked_in_at: entry.clocked_in_at,
            clocked_out_at: entry.clocked_out_at,
            worked_minutes: entry.duration_minutes ?? 0,
          },
          after: {
            version: newEntry.version,
            clocked_in_at: newEntry.clocked_in_at,
            clocked_out_at: newEntry.clocked_out_at,
            worked_minutes: newEntry.duration_minutes ?? 0,
          },
        })
        recalcWorkDay(day)

        const result: CorrectedShiftEntry = {
          employee_uuid: workdaysState.employee_uuid,
          work_date: day.work_date,
          action,
          shift_entry_uuid: newUuid,
          superseded_shift_entry_uuid: entry.uuid,
          version: newEntry.version,
          status: newEntry.status,
          clocked_in_at: newEntry.clocked_in_at,
          clocked_out_at: newEntry.clocked_out_at,
          daily_total_minutes: day.total_minutes,
        }

        await json(route, 200, result)
        return
      }

      // Anular tambien lleva un `uuid` dinamico en la ruta
      // (`POST /shift-entries/{uuid}/void`, RF-PA-04, ADR-026).
      const voidShiftEntryMatch = /^\/api\/v1\/shift-entries\/([0-9a-f-]+)\/void$/.exec(
        url.pathname,
      )

      if (method === 'POST' && voidShiftEntryMatch !== null) {
        const targetUuid = voidShiftEntryMatch[1] ?? ''
        const found = findShiftEntry(workdaysState, targetUuid)

        if (found === undefined) {
          // Anular solo tiene una causa de conflicto: el tramo ya no es el
          // vigente (ya anulado o ya sustituido). No hay «turno abierto» ni
          // «solape» que anular pueda provocar.
          await problem(
            route,
            409,
            'urn:kronoqr:problem:shift-entry-superseded',
            'Conflicto con el estado actual',
            'Ese tramo ya no es la version vigente. Vuelve a cargar la jornada antes de anular.',
          )

          return
        }

        const { day, entry } = found
        const payload = request.postDataJSON() as VoidShiftEntryRequest

        day.shift_entries = day.shift_entries.filter((candidate) => candidate.uuid !== entry.uuid)
        day.corrections.push({
          shift_entry_uuid: entry.uuid,
          action: 'voided',
          performed_at: CORRECTION_NOW,
          performed_at_local: toLocalTimestamp(CORRECTION_NOW, day.time_zone),
          performed_by: { uuid: currentUser.uuid, name: currentUser.name },
          reason_code: payload.reason_code,
          reason_text: payload.reason_text ?? null,
          before: {
            version: entry.version,
            clocked_in_at: entry.clocked_in_at,
            clocked_out_at: entry.clocked_out_at,
            worked_minutes: entry.duration_minutes ?? 0,
          },
          after: null,
        })
        recalcWorkDay(day)

        const result: CorrectedShiftEntry = {
          employee_uuid: workdaysState.employee_uuid,
          work_date: day.work_date,
          action: 'voided',
          shift_entry_uuid: entry.uuid,
          superseded_shift_entry_uuid: null,
          version: entry.version,
          status: 'voided',
          clocked_in_at: entry.clocked_in_at,
          clocked_out_at: entry.clocked_out_at,
          daily_total_minutes: day.total_minutes,
        }

        await json(route, 200, result)
        return
      }

      switch (`${method} ${url.pathname}`) {
        case 'GET /api/v1/devices':
          await json(route, 200, { devices })
          return
        case 'POST /api/v1/kiosk/pair/confirm': {
          const payload = request.postDataJSON() as { code?: string; name?: string }

          if (payload.code !== PAIRING_CODE) {
            await problem(
              route,
              422,
              'urn:kronoqr:problem:pairing-code-rejected',
              'Codigo de emparejamiento no valido',
            )

            return
          }

          const name = payload.name ?? ''
          const revokedByName = devices.find(
            (candidate) => candidate.name === name && candidate.status === 'revoked',
          )
          const activeByName = devices.find(
            (candidate) => candidate.name === name && candidate.status === 'active',
          )

          if (activeByName !== undefined) {
            await route.fulfill({
              status: 422,
              contentType: 'application/problem+json',
              body: JSON.stringify({
                type: 'urn:kronoqr:problem:validation-failed',
                title: 'Peticion no valida',
                status: 422,
                errors: { name: ['Ya hay un quiosco activo con ese nombre.'] },
              }),
            })

            return
          }

          if (revokedByName !== undefined) {
            revokedByName.status = 'active'
            const confirmed: PairingConfirmed = {
              device: {
                uuid: revokedByName.uuid,
                name: revokedByName.name,
                status: 'active',
                reactivated: true,
              },
              request: PAIRING_REQUEST,
            }
            await json(route, 200, confirmed)
            return
          }

          const created: Device = {
            uuid: `0199f3c9-${String(devices.length).padStart(4, '0')}-7a44-8e02-3c4d5e6f7a81`,
            name,
            status: 'active',
            app_version: null,
            last_seen_at: null,
            pending_queue_size: 0,
            paired_at: '2026-09-07T10:00:00.000000Z',
          }
          devices.push(created)

          const confirmed: PairingConfirmed = {
            device: {
              uuid: created.uuid,
              name: created.name,
              status: 'active',
              reactivated: false,
            },
            request: PAIRING_REQUEST,
          }
          await json(route, 200, confirmed)
          return
        }
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
        case 'GET /api/v1/setup/status':
          // Publico, sin token: la guarda de rutas lo consulta antes de
          // decidir si hay que mandar al asistente (RF-PD-03).
          await json(route, 200, options.setupStatus ?? SETUP_STATUS_DONE)
          return
        case 'GET /api/v1/branding':
          // Publica, sin token (RF-PD-08, tarea 5.8): la cabecera y el acceso
          // la piden antes de que exista ninguna sesion, y `BrandingView` la
          // vuelve a pedir tras guardar.
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
        case 'GET /api/v1/license':
          // Sin `options.license`, sigue respondiendo `404` como hacia antes
          // de que existiera este caso: los recorridos que no pasan el
          // campo no ven ni el banner ni el aviso de marca de la licencia.
          if (options.license === undefined) {
            await problem(route, 404, 'urn:kronoqr:problem:not-found', 'Sin licencia en este doble')

            return
          }

          await json(route, 200, options.license)
          return
        case 'GET /api/v1/settings':
          await json(route, 200, settingsCatalog())
          return
        case 'PATCH /api/v1/settings': {
          const patch = request.postDataJSON() as { settings: Record<string, unknown> }
          const errors: Record<string, string[]> = {}

          /**
           * Un entero dentro de rango, o `undefined` -y un error colgado de
           * `settings.<CLAVE>`- si lo que llega no lo es. Mismo rango que
           * `SettingKey::catalog()` en el backend (comentario de la funcion
           * que llama a esta).
           */
          function checkInteger(key: string, minimum: number, maximum: number): number | undefined {
            const raw = patch.settings[key]

            if (raw === undefined) {
              return undefined
            }

            if (
              typeof raw !== 'number' ||
              !Number.isInteger(raw) ||
              raw < minimum ||
              raw > maximum
            ) {
              errors[`settings.${key}`] = [
                `El valor de «${key}» tiene que ser un numero entero entre ${minimum} y ${maximum}.`,
              ]

              return undefined
            }

            return raw
          }

          const maxShiftHours = checkInteger('ATTENDANCE_MAX_SHIFT_HOURS', 1, 24)
          const debounceSeconds = checkInteger('ATTENDANCE_DEBOUNCE_SECONDS', 0, 3600)
          const maxClockSkewMinutes = checkInteger('ATTENDANCE_MAX_CLOCK_SKEW_MINUTES', 1, 1440)
          const minTransitSeconds = checkInteger('ATTENDANCE_MIN_TRANSIT_SECONDS', 0, 3600)

          const localeDefaultRaw = patch.settings['LOCALE_DEFAULT']
          const localeAvailableRaw = patch.settings['LOCALE_AVAILABLE']

          if (typeof localeDefaultRaw === 'string' && !['es', 'en'].includes(localeDefaultRaw)) {
            errors['settings.LOCALE_DEFAULT'] = [
              'Ese idioma no esta entre los que trae el producto.',
            ]
          }

          const nextLocaleAvailable = Array.isArray(localeAvailableRaw)
            ? localeAvailableRaw.filter((entry): entry is string => typeof entry === 'string')
            : localeAvailable

          if (Array.isArray(localeAvailableRaw) && nextLocaleAvailable.length === 0) {
            errors['settings.LOCALE_AVAILABLE'] = ['Selecciona al menos un idioma.']
          }

          const nextLocaleDefault =
            typeof localeDefaultRaw === 'string' && ['es', 'en'].includes(localeDefaultRaw)
              ? localeDefaultRaw
              : localeDefault

          // La invariante ENTRE claves (ResolvedSettings::with en el backend):
          // el idioma por defecto tiene que quedar entre los disponibles.
          // Cuelga de `settings` a secas, nunca de una de las dos claves.
          if (
            errors['settings.LOCALE_DEFAULT'] === undefined &&
            errors['settings.LOCALE_AVAILABLE'] === undefined &&
            !nextLocaleAvailable.includes(nextLocaleDefault)
          ) {
            errors['settings'] = ['El idioma por defecto tiene que estar entre los disponibles.']
          }

          if (Object.keys(errors).length > 0) {
            await validationProblem(
              route,
              'urn:kronoqr:problem:validation-failed',
              'Peticion no valida',
              errors,
            )

            return
          }

          if (maxShiftHours !== undefined) {
            attendanceMaxShiftHours = maxShiftHours
          }

          if (debounceSeconds !== undefined) {
            attendanceDebounceSeconds = debounceSeconds
          }

          if (maxClockSkewMinutes !== undefined) {
            attendanceMaxClockSkewMinutes = maxClockSkewMinutes
          }

          if (minTransitSeconds !== undefined) {
            attendanceMinTransitSeconds = minTransitSeconds
          }

          localeDefault = nextLocaleDefault
          localeAvailable = nextLocaleAvailable

          const appName = patch.settings['BRANDING_APP_NAME']
          const accentColor = patch.settings['BRANDING_ACCENT_COLOR']
          const brandingLogoPath = patch.settings['BRANDING_LOGO_PATH']

          if (typeof appName === 'string') {
            branding = { ...branding, application_name: appName }
          }

          if (typeof accentColor === 'string') {
            branding = { ...branding, accent_color: accentColor }
          }

          if (typeof brandingLogoPath === 'string') {
            logoPath = brandingLogoPath
          }

          await json(route, 200, settingsCatalog())
          return
        }
        case 'GET /api/v1/site':
          await json(route, 200, SITE)
          return
        case 'GET /api/v1/departments':
          await json(route, 200, DEPARTMENTS)
          return
        case 'GET /api/v1/employees':
          await json(route, 200, EMPLOYEES)
          return
        case 'POST /api/v1/employees': {
          // Alta de empleado (RF-GP-01): el PIN se emite en la MISMA transaccion
          // y viaja en la respuesta una sola vez (RF-ID-09). El contenido de
          // verdad -huella del documento, unicidad del codigo- lo prueba el
          // backend; aqui basta con devolver una ficha coherente con lo que se
          // tecleo, para que el dialogo del PIN tenga algo que enseñar.
          const payload = request.postDataJSON() as CreateEmployeeRequest
          const created: Employee = {
            uuid: '0199f5b1-0001-7000-8000-0123456789bb',
            employee_code: 'E9K3M2QXPR',
            first_name: payload.first_name,
            last_name: payload.last_name,
            email: payload.email ?? null,
            department_id: payload.department_id ?? null,
            status: 'active',
            hired_at: payload.hired_at,
            terminated_at: null,
            locale: payload.locale,
            pin_status: 'issued',
          }
          const provisioned: EmployeeProvisioned = {
            employee: created,
            pin: {
              employee_uuid: created.uuid,
              pin: '384920',
              issued_at: '2026-09-08T09:00:00.000000Z',
              pin_status: 'issued',
            },
          }

          await json(route, 201, provisioned)
          return
        }
        case `GET /api/v1/employees/${EMPLOYEE_UUID}`:
          await json(route, 200, EMPLOYEE)
          return
        case `GET /api/v1/employees/${EMPLOYEE_UUID}/workdays`:
          await json(route, 200, workdaysState)
          return
        case 'POST /api/v1/shift-entries': {
          // Alta manual de un tramo que nunca se ficho (RF-PA-04, accion
          // `created`). El doble no aplica RN-01/RN-02/RN-03 al construir el
          // resultado -eso lo prueba el backend-, pero SI simula sus tres
          // causas de `409` cuando `correctionOutcome` lo pide: «Añadir un
          // tramo» es donde un turno ya abierto (RN-01, un olvido de salida)
          // es mas probable que en cualquier otra operacion.
          if (correctionOutcome === 'superseded') {
            await problem(
              route,
              409,
              'urn:kronoqr:problem:shift-entry-superseded',
              'Conflicto con el estado actual',
              'Ese tramo ya no es la version vigente. Vuelve a cargar la jornada antes de anadir uno nuevo.',
            )

            return
          }

          if (correctionOutcome === 'shiftAlreadyOpen') {
            await problem(
              route,
              409,
              'urn:kronoqr:problem:shift-already-open',
              'Conflicto con el estado actual',
              'Esa persona ya tiene un turno abierto. Cierralo o anulalo antes de dejar otro sin salida.',
            )

            return
          }

          if (correctionOutcome === 'overlap') {
            await problem(
              route,
              409,
              'urn:kronoqr:problem:overlapping-shift-entry',
              'Conflicto con el estado actual',
              'Las horas indicadas se solapan con otro tramo de esa persona. Revisa la jornada antes de anadir el nuevo.',
            )

            return
          }

          const payload = request.postDataJSON() as AddShiftEntryRequest
          const day = ensureWorkDay(workdaysState, payload.work_date)
          const newUuid = syntheticShiftEntryUuid()
          // Normalizado una vez: `AddShiftEntryRequest.clocked_out_at` es
          // opcional Y nulable (`null` da de alta el tramo abierto), y de aqui
          // en adelante solo importa si hay hora de salida o no.
          const clockedOutAt = payload.clocked_out_at ?? null
          const durationMinutes =
            clockedOutAt === null ? null : minutesBetween(payload.clocked_in_at, clockedOutAt)

          const entry: WorkDayShiftEntry = {
            uuid: newUuid,
            version: 1,
            status: clockedOutAt === null ? 'open' : 'closed',
            time_zone: day.time_zone,
            clocked_in_at: payload.clocked_in_at,
            clocked_in_at_local: toLocalTimestamp(payload.clocked_in_at, day.time_zone),
            clocked_in_recorded_at: null,
            clock_in_source: 'manual_admin',
            clocked_out_at: clockedOutAt,
            clocked_out_at_local:
              clockedOutAt === null ? null : toLocalTimestamp(clockedOutAt, day.time_zone),
            clocked_out_recorded_at: null,
            clock_out_source: clockedOutAt === null ? null : 'manual_admin',
            duration_minutes: durationMinutes,
            recorded_at: CORRECTION_NOW,
          }

          day.shift_entries.push(entry)
          day.corrections.push({
            shift_entry_uuid: newUuid,
            action: 'created',
            performed_at: CORRECTION_NOW,
            performed_at_local: toLocalTimestamp(CORRECTION_NOW, day.time_zone),
            performed_by: { uuid: currentUser.uuid, name: currentUser.name },
            reason_code: payload.reason_code,
            reason_text: payload.reason_text ?? null,
            before: null,
            after: {
              version: 1,
              clocked_in_at: entry.clocked_in_at,
              clocked_out_at: entry.clocked_out_at,
              worked_minutes: entry.duration_minutes ?? 0,
            },
          })
          recalcWorkDay(day)

          const result: CorrectedShiftEntry = {
            employee_uuid: payload.employee_uuid,
            work_date: payload.work_date,
            action: 'created',
            shift_entry_uuid: newUuid,
            superseded_shift_entry_uuid: null,
            version: 1,
            status: entry.status,
            clocked_in_at: entry.clocked_in_at,
            clocked_out_at: entry.clocked_out_at,
            daily_total_minutes: day.total_minutes,
          }

          await json(route, 201, result)
          return
        }
        case 'GET /api/v1/compliance-profile':
          await json(route, 200, complianceProfile)
          return
        case 'PATCH /api/v1/compliance-profile': {
          // Sin validacion de rango: el 422 de verdad lo prueba el backend
          // (regla dura 18); aqui basta con reflejar el cambio, como haria el
          // servidor, y sellar `updated_at` para que se note que ha cambiado.
          const patch = request.postDataJSON() as Partial<ComplianceProfile['data']>

          complianceProfile = {
            data: {
              ...complianceProfile.data,
              ...patch,
              updated_at: '2026-09-10T09:00:00.000000Z',
            },
          }

          await json(route, 200, complianceProfile)
          return
        }
        case 'GET /api/v1/credentials/status': {
          // Con `?key_id=` el servidor devuelve solo a quien le falta
          // reimprimir (RF-QR-07): el doble distingue las dos respuestas para
          // que la prueba pueda comprobar que el filtro va al servidor y no se
          // resuelve en cliente.
          const board = new URL(route.request().url()).searchParams.has('key_id')
            ? (options.credentialBoardByKey ?? options.credentialBoard ?? CREDENTIAL_BOARD)
            : (options.credentialBoard ?? CREDENTIAL_BOARD)

          await json(route, 200, board)
          return
        }
        case 'GET /api/v1/credentials/instructions-sheet': {
          // La hoja de instrucciones (tarea 5.11b, RL-05): un PDF por idioma,
          // sin datos de ninguna persona. Aqui basta con que la peticion lleve
          // el `locale` correcto y que el panel reciba algo descargable con el
          // nombre que trae `Content-Disposition`; el contenido de verdad lo
          // prueba el backend.
          const sheetLocale = url.searchParams.get('locale') ?? branding.locales.default

          await route.fulfill({
            status: 200,
            contentType: 'application/pdf',
            headers: {
              'Cache-Control': 'no-store',
              // El contrato y el servidor de verdad llaman al fichero
              // `hoja-empleado-<locale>.pdf` (`docs/api/openapi.yaml`); el
              // doble tenia otro nombre y la prueba lo afirmaba sin comprobar
              // contra el contrato (hallazgo 7 de la revision).
              'Content-Disposition': `attachment; filename=hoja-empleado-${sheetLocale}.pdf`,
            },
            body: Buffer.from('contenido-de-prueba-de-la-hoja'),
          })

          return
        }
        case 'GET /api/v1/attendance/live':
          await json(route, 200, options.liveBoard ?? LIVE_BOARD)
          return
        case 'GET /api/v1/reports/period':
          // Informe de horas por periodo (RF-IN-01..03, tarea 2.8). Un mes de
          // una persona con un cambio de contrato a mitad de mes, que es el caso
          // en el que lo contratado NO es una regla de tres sobre el ultimo
          // contrato. Los criterios de `meta` viajan en el idioma de quien pide
          // el informe, igual que hace el servidor de verdad (hallazgo 7).
          await json(route, 200, periodReportFor(requestLocaleOf(request)))
          return
        case 'GET /api/v1/reports/period/export':
          // La descarga del mismo informe (RF-IN-04, tarea 2.9). El cuerpo es un
          // fichero y aqui da igual cual: lo que el E2E comprueba es el recorrido
          // —que la peticion sale con el formato y el periodo correctos y que el
          // panel dispara la descarga—, no el contenido del fichero, que se
          // comprueba en el backend con la libreria que lo escribio.
          //
          // Las dos cabeceras son las que publica el servidor de verdad: sin
          // `Content-Disposition`, el panel no sabria como llamar al fichero.
          if (exportOutcome === 'forbidden') {
            await problem(route, 403, 'urn:kronoqr:problem:forbidden', 'Sin permiso')

            return
          }

          await route.fulfill({
            status: 200,
            contentType: 'text/csv; charset=utf-8',
            headers: {
              'Content-Disposition':
                'attachment; filename=kronoqr-horas-2026-03-01_2026-03-31.' +
                (url.searchParams.get('format') ?? 'csv'),
              'X-Kronoqr-Report-Digest': REPORT_DIGEST,
              'X-Kronoqr-Report-Rows': String(PERIOD_REPORT.meta.row_count),
            },
            body: 'contenido-de-prueba',
          })

          return
        case 'GET /api/v1/reports/legal-export':
          // Exportacion normalizada para la Inspeccion de Trabajo (RF-IN-05,
          // RL-06). Igual que el informe de periodo: aqui no importa el
          // contenido del CSV -eso lo prueba el backend-, solo que la peticion
          // lleve el periodo pedido y que las dos cifras de la cabecera lleguen
          // al panel para que pueda decir cuanto entrego.
          await route.fulfill({
            status: 200,
            contentType: 'text/csv; charset=utf-8',
            headers: {
              'Content-Disposition':
                `attachment; filename=registro-horario-${url.searchParams.get('from') ?? ''}_` +
                `${url.searchParams.get('to') ?? ''}.csv`,
              'X-Kronoqr-Export-Shift-Rows': '21',
              'X-Kronoqr-Export-Correction-Rows': '1',
            },
            body: 'contenido-de-prueba',
          })

          return
        case 'POST /api/v1/broadcasting/auth':
          // La firma real la calcula el servidor con su secreto; aqui basta con
          // que el cliente reciba el campo con la forma del protocolo.
          await json(route, 200, { auth: 'kronoqr:firma-de-prueba' })
          return
        case 'POST /api/v1/diagnostics/bundle': {
          // El paquete de diagnostico (RF-PD-09, ADR-020). El contenido de
          // verdad lo prueba el backend; aqui basta con que el panel reciba
          // un documento descargable con el nombre que trae
          // `Content-Disposition`, y que la peticion lleve lo que se marco en
          // pantalla.
          const payload = request.postDataJSON() as {
            include_personal_data?: boolean
            period_days?: number
          } | null

          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            headers: {
              'Content-Disposition': `attachment; filename=${DIAGNOSTICS_BUNDLE_FILENAME}`,
            },
            body: JSON.stringify({
              manifest: {
                schema_version: 1,
                product_version: '2.2.0',
                generated_at: '2026-09-08T10:15:00.000000Z',
                anonymized: !(payload?.include_personal_data ?? false),
                generated_by: 'user',
                sections: ['manifest', 'installation', 'doctor'],
                sha256: '0'.repeat(64),
              },
            }),
          })

          return
        }
        case 'GET /api/v1/support/grants':
          // Las 100 mas recientes, de la mas nueva a la mas antigua (RF-PD-11):
          // el doble ya inserta las concesiones nuevas al principio.
          await json(route, 200, { data: supportGrants })
          return
        case 'POST /api/v1/support/grants': {
          const payload = request.postDataJSON() as {
            reason?: string
            scope?: SupportGrant['scope']
            hours?: number
          }

          const created: SupportGrant = {
            uuid: `0199f4d0-${String(supportGrants.length).padStart(4, '0')}-7000-8000-0123456789ff`,
            status: 'active',
            scope: payload.scope ?? 'diagnostics',
            reason: payload.reason ?? '',
            granted_by: { uuid: currentUser.uuid, name: currentUser.name },
            granted_at: '2026-09-08T09:30:00.000000Z',
            expires_at: '2026-09-09T09:30:00.000000Z',
            revoked_at: null,
            accessed_at: null,
          }

          supportGrants.unshift(created)
          // El token viaja **una sola vez**, en esta respuesta (RF-PD-11): la
          // fila que queda en `supportGrants` -y que devuelve el `GET`
          // siguiente- no lo lleva.
          await json(route, 201, { data: { ...created, token: ISSUED_SUPPORT_TOKEN } })
          return
        }
        case 'GET /api/v1/data-export':
          // Las 20 mas recientes, de la mas nueva a la mas antigua (RF-PD-14,
          // RL-20): el doble ya inserta la que crea `POST` al principio.
          await json(route, 200, { data: currentDataExports() })
          return
        case 'POST /api/v1/data-export': {
          const inProgress = currentDataExports().find(
            (row) => row.status === 'pending' || row.status === 'running',
          )

          if (inProgress !== undefined) {
            await route.fulfill({
              status: 409,
              contentType: 'application/problem+json',
              body: JSON.stringify({
                type: 'urn:kronoqr:problem:data-export-in-progress',
                title: 'Ya hay una exportación en curso',
                status: 409,
                detail: `Espera a que termine la exportación pedida a las ${inProgress.requested_at}.`,
                export: inProgress,
              }),
            })

            return
          }

          const created: DataExport = {
            uuid: DATA_EXPORT_UUID,
            status: 'pending',
            requested_via: 'panel',
            requested_by: { uuid: currentUser.uuid, name: currentUser.name },
            requested_at: '2026-09-08T10:15:00.000000Z',
            started_at: null,
            completed_at: null,
            failed_at: null,
            failure_reason: null,
            file_name: null,
            size_bytes: null,
            sha256: null,
            row_counts: {},
            expires_at: null,
            purged_at: null,
            downloaded_at: null,
            download_count: 0,
          }

          dataExports.push(created)
          dataExportCreatedAt.set(created.uuid, Date.now())
          await json(route, 202, { data: created })
          return
        }
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
 * Entra como `ADMIN_USER` (RF-ID-02): abilities `['*']`, asi que llega a la
 * plantilla igual que RRHH. Exige `stubManagementApi(page, { role: 'admin' })`.
 */
export async function logInAsAdmin(page: Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel(/Correo electrónico/).fill(ADMIN_USER.email)
  await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await page.waitForURL('**/employees')
}

/**
 * Entra como `AUDITOR_USER` (RF-ID-02): `attendance:read` sin
 * `employees:*` ni `attendance:correct`, asi que la primera seccion a su
 * alcance es la exportacion legal (doc 02 §7.3, `router/guards.ts`). Exige
 * `stubManagementApi(page, { role: 'auditor' })`.
 */
export async function logInAsAuditor(page: Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel(/Correo electrónico/).fill(AUDITOR_USER.email)
  await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await page.waitForURL('**/reports/legal-export')
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
