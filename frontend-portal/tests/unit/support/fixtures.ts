// Datos de ejemplo, calcados de los del contrato. Si el contrato cambia de
// forma, estas pruebas dejan de compilar antes que la aplicacion falle.
import type {
  EmployeeWorkDays,
  PortalEmployee,
  PortalSession,
  WorkDayCorrection,
  WorkDayDetail,
  WorkDayShiftEntry,
} from '@/shared/api/types'

export const EMPLOYEE_UUID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'

export function portalEmployee(overrides: Partial<PortalEmployee> = {}): PortalEmployee {
  return {
    uuid: EMPLOYEE_UUID,
    display_name: 'Lucía Gómez Ruiz',
    employee_code: 'E7K2M9XQ4',
    locale: 'es',
    time_zone: 'Europe/Madrid',
    ...overrides,
  }
}

export function portalSession(overrides: Partial<PortalSession> = {}): PortalSession {
  return {
    token: '41|Zt6QpX1nR8vKcLm3bYdF9wS2eT5rU7iO0aP4sD8h',
    token_type: 'Bearer',
    expires_at: '2099-01-01T00:00:00Z',
    employee: portalEmployee(),
    ...overrides,
  }
}

// --- Detalle de jornada (RF-ID-05, RL-05) ------------------------------------
//
// Los valores son los del ejemplo del contrato: un turno del 14 de marzo de
// 2026 en `Europe/Madrid` (UTC+1) al que le faltaba la salida y que RRHH cerro.
// Sirve para lo mismo que sirve en el contrato: que la suma cuadre y que el
// historico diga que decia antes.

export const SHIFT_ENTRY_UUID = '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11'

export function shiftEntry(overrides: Partial<WorkDayShiftEntry> = {}): WorkDayShiftEntry {
  return {
    uuid: SHIFT_ENTRY_UUID,
    version: 2,
    status: 'closed',
    site_id: 1,
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
    // Por omision, el total DECLARADO coincide con la suma de los tramos. Que
    // no coincida es un caso de prueba, no el punto de partida.
    total_minutes: entries.reduce((sum, entry) => sum + (entry.duration_minutes ?? 0), 0),
    shift_count: entries.length,
    has_open_shift: entries.some((entry) => entry.clocked_out_at === null),
    has_incident: false,
    recalculated_at: '2026-03-14T15:22:41.900000Z',
    shift_entries: entries,
    corrections: [correction()],
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
