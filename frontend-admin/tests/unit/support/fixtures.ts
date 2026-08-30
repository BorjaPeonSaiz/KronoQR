// Datos de ejemplo, calcados de los del contrato. Si el contrato cambia de
// forma, estas pruebas dejan de compilar antes que la aplicacion falle.
import { EMPLOYEE_LIST_PER_PAGE } from '@/features/employees/employees.api'
import type {
  Credential,
  CredentialCoverage,
  CredentialStatusBoard,
  CredentialStatusRow,
  Employee,
  EmployeeCollection,
  EmployeeWorkDays,
  Incident,
  IncidentCollection,
  ManagementUser,
  Session,
  Site,
  TwoFactorChallenge,
  TwoFactorEnrolment,
  WorkDayCorrection,
  WorkDayDetail,
  WorkDayShiftEntry,
} from '@/shared/api/types'

export const SITE: Site = { id: 1, name: 'Hotel Marina', timezone: 'Europe/Madrid' }

export const EMPLOYEE_UUID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'
export const CREDENTIAL_UUID = '0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01'

export function employee(overrides: Partial<Employee> = {}): Employee {
  return {
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
    ...overrides,
  }
}

// Este mock no lee `per_page` de la peticion: pagina con el tamano real del
// listado para que la aritmetica de las pruebas sea la del codigo.
const EMPLOYEE_COLLECTION_PER_PAGE = EMPLOYEE_LIST_PER_PAGE

export function employeeCollection(data: Employee[], total = data.length): EmployeeCollection {
  return {
    data,
    meta: {
      page: 1,
      per_page: EMPLOYEE_COLLECTION_PER_PAGE,
      total,
      total_pages: Math.max(Math.ceil(total / EMPLOYEE_COLLECTION_PER_PAGE), 1),
    },
  }
}

export function managementUser(overrides: Partial<ManagementUser> = {}): ManagementUser {
  return {
    uuid: '0199f0aa-1111-7000-8000-0123456789ab',
    name: 'Direccion RRHH',
    email: 'rrhh@hotel.example',
    locale: 'es',
    roles: ['rrhh'],
    abilities: ['attendance:read', 'employees:read', 'employees:*', 'credentials:*'],
    // Alcance por departamento (RF-ID-03). RRHH llega a toda la plantilla, y por
    // eso `department_ids` va vacio: con `kind: all` la lista no acota nada. Un
    // responsable seria `{ kind: 'departments', department_ids: [3] }`, y ahi la
    // lista vacia significaria «nadie».
    scope: { kind: 'all', department_ids: [] },
    ...overrides,
  }
}

export function session(overrides: Partial<Session> = {}): Session {
  return {
    token: '17|GhK2mXpR9vLdN4tZbYcF1wQ8sE3rT6uI0oP5aS7d',
    token_type: 'Bearer',
    expires_at: '2099-01-01T00:00:00Z',
    user: managementUser(),
    ...overrides,
  }
}

export function twoFactorChallenge(
  overrides: Partial<TwoFactorChallenge> = {},
): TwoFactorChallenge {
  return {
    challenge_token: '41|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ',
    token_type: 'Bearer',
    expires_at: '2099-01-01T00:10:00Z',
    enrolment_required: false,
    ...overrides,
  }
}

export function twoFactorEnrolment(
  overrides: Partial<TwoFactorEnrolment> = {},
): TwoFactorEnrolment {
  return {
    secret: 'JBSWY3DPEHPK3PXP',
    otpauth_uri:
      'otpauth://totp/KronoQR:rrhh%40hotel.example?secret=JBSWY3DPEHPK3PXP&issuer=KronoQR&algorithm=SHA1&digits=6&period=30',
    ...overrides,
  }
}

export function credential(overrides: Partial<Credential> = {}): Credential {
  return {
    uuid: CREDENTIAL_UUID,
    employee_uuid: EMPLOYEE_UUID,
    key_id: null,
    issued_at: '2026-08-19T06:02:31.000000Z',
    printed_at: null,
    delivered_at: null,
    revoked_at: null,
    revoked_reason: null,
    status: 'active',
    ...overrides,
  }
}

export function boardRow(overrides: Partial<CredentialStatusRow> = {}): CredentialStatusRow {
  return {
    employee_uuid: EMPLOYEE_UUID,
    employee_code: 'E7K2M9QX4B',
    full_name: 'Lucia Martinez Prieto',
    department_name: 'Recepcion',
    status: 'pending_print',
    credential: credential(),
    ...overrides,
  }
}

export function board(
  rows: CredentialStatusRow[],
  // Rotacion de la clave de firma (RF-QR-07). Fuera de una rotacion —lo
  // normal— no hay clave saliente y el avance vale cero.
  rotation: Partial<
    Pick<CredentialCoverage, 'retiring_key_id' | 'pending_reprint' | 'active_unknown_key'>
  > = {},
): CredentialStatusBoard {
  return {
    data: rows,
    summary: {
      employees: 60,
      pending_print: rows.filter((row) => row.status === 'pending_print').length,
      without_delivered_credential: rows.filter((row) => row.status !== 'delivered').length,
      retiring_key_id: null,
      pending_reprint: 0,
      active_unknown_key: 0,
      ...rotation,
    },
  }
}

// --- Detalle de jornada (RF-PA-03) -------------------------------------------
//
// Los valores son los del ejemplo del contrato: un turno del 14 de marzo de 2026
// en `Europe/Madrid` (UTC+1) al que le faltaba la salida y que RRHH cerro. Sirve
// para lo mismo que sirve en el contrato: que la suma cuadre y que el historico
// diga que decia antes.

export const SHIFT_ENTRY_UUID = '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11'

export function shiftEntry(overrides: Partial<WorkDayShiftEntry> = {}): WorkDayShiftEntry {
  return {
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
    ...overrides,
  }
}

export function correction(overrides: Partial<WorkDayCorrection> = {}): WorkDayCorrection {
  return {
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
    ...overrides,
  }
}

export function workDay(overrides: Partial<WorkDayDetail> = {}): WorkDayDetail {
  const entries = overrides.shift_entries ?? [shiftEntry()]

  return {
    work_date: '2026-03-14',
    time_zone: 'Europe/Madrid',
    // Por omision, el total DECLARADO coincide con la suma de los tramos. Que no
    // coincida es un caso de prueba, no el punto de partida.
    total_minutes: entries.reduce((sum, entry) => sum + (entry.duration_minutes ?? 0), 0),
    shift_count: entries.length,
    has_open_shift: entries.some((entry) => entry.clocked_out_at === null),
    has_incident: false,
    recalculated_at: '2026-03-14T15:22:41.900000Z',
    shift_entries: entries,
    corrections: [correction()],
    // Sin incidencias por omision (RF-PA-05): que las haya es un caso de prueba,
    // no el punto de partida. El campo va siempre porque el contrato lo exige.
    incidents: [],
    ...overrides,
  }
}

export function employeeWorkDays(
  days: WorkDayDetail[] = [workDay()],
  overrides: Partial<EmployeeWorkDays> = {},
): EmployeeWorkDays {
  return {
    employee_uuid: EMPLOYEE_UUID,
    time_zone: 'Europe/Madrid',
    from: days[0]?.work_date ?? '2026-03-14',
    to: days.at(-1)?.work_date ?? '2026-03-14',
    data: days,
    meta: { total: days.length },
    ...overrides,
  }
}

// --- Bandeja de incidencias (RF-PA-05, RF-PR-01) -----------------------------
//
// El mismo ejemplo del contrato para `GET /api/v1/incidents`: el descanso
// insuficiente de Youssef Amrani, pendiente y asignado a la jefatura de cocina.

export const INCIDENT_ID = 412

export function incident(overrides: Partial<Incident> = {}): Incident {
  return {
    id: INCIDENT_ID,
    type: 'insufficient_rest',
    severity: 'high',
    status: 'open',
    employee: {
      uuid: EMPLOYEE_UUID,
      employee_code: 'E7QK2MXPR',
      full_name: 'Youssef Amrani',
      department: { id: 3, name: 'Cocina' },
    },
    work_date: '2026-03-14',
    shift_entry_uuid: SHIFT_ENTRY_UUID,
    detected_at: '2026-03-15T03:30:00.000000Z',
    context: { rest_minutes: 420, threshold_minutes: 720 },
    assigned_to: { uuid: '0199f0aa-1111-7000-8000-0123456789ab', name: 'Jefatura de cocina' },
    resolved_at: null,
    resolved_by: null,
    resolution_note: null,
    ...overrides,
  }
}

export function incidentCollection(
  data: Incident[] = [incident()],
  overrides: Partial<IncidentCollection['meta']> = {},
): IncidentCollection {
  return {
    data,
    meta: {
      page: 1,
      per_page: 25,
      total: data.length,
      total_pages: 1,
      time_zone: 'Europe/Madrid',
      generated_at: '2026-03-15T08:00:00.000000Z',
      ...overrides,
    },
  }
}
