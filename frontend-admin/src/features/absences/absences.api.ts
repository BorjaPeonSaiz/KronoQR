// Ausencias: registro, correccion, anulacion e importacion (RF-GP-04). Las
// formas salen del contrato (`docs/api/openapi.yaml`); aqui no se inventa
// ninguna.
import { requestJson } from '@kronoqr/web-kit/http'
import type {
  Absence,
  AbsenceCollection,
  AbsenceDetail,
  AbsenceImportReport,
  AbsenceStatus,
  AbsenceType,
  CorrectAbsenceRequest,
  CreateAbsenceRequest,
  EmployeeImportMode,
  VoidAbsenceRequest,
} from '@/shared/api/types'

/** Tamano de pagina del listado, igual que la plantilla (`employees.api.ts`). */
export const ABSENCE_LIST_PER_PAGE = 30

export interface AbsenceListQuery {
  page: number
  perPage: number
  /** `AAAA-MM-DD`. Sin filtro, el servidor aplica su propio periodo por omision. */
  from?: string
  to?: string
  employeeUuid?: string
  departmentId?: number
  type?: AbsenceType
  /** `active` por omision en el servidor. `'all'` incluye supersedidas y anuladas. */
  status?: AbsenceStatus | 'all'
}

export function listAbsences(query: AbsenceListQuery): Promise<AbsenceCollection> {
  return requestJson<AbsenceCollection>('/api/v1/absences', {
    query: {
      page: query.page,
      per_page: query.perPage,
      from: query.from,
      to: query.to,
      employee_uuid: query.employeeUuid,
      department_id: query.departmentId,
      type: query.type,
      status: query.status,
    },
  })
}

/**
 * `GET /absences/{uuid}` — la ausencia con su historico (`history`, el mas
 * antiguo primero, sin incluir la propia version).
 */
export function getAbsence(uuid: string): Promise<AbsenceDetail> {
  return requestJson<AbsenceDetail>(`/api/v1/absences/${uuid}`)
}

/** `POST /absences` — alta. `409` si solapa con otra activa del mismo empleado. */
export function createAbsence(body: CreateAbsenceRequest): Promise<Absence> {
  return requestJson<Absence>('/api/v1/absences', { method: 'POST', body })
}

/**
 * `PATCH /absences/{uuid}` — corrige creando la version siguiente (RN-13). El
 * `uuid` de la ruta deja de ser vigente en cuanto la peticion tiene exito: la
 * respuesta trae uno nuevo. `409` si la de la ruta ya no es `active` o si la
 * nueva version solapa con otra ausencia activa.
 */
export function correctAbsence(uuid: string, body: CorrectAbsenceRequest): Promise<Absence> {
  return requestJson<Absence>(`/api/v1/absences/${uuid}`, { method: 'PATCH', body })
}

/** `POST /absences/{uuid}/void` — declara que la ausencia no rige. No crea version nueva. */
export function voidAbsence(uuid: string, body: VoidAbsenceRequest): Promise<Absence> {
  return requestJson<Absence>(`/api/v1/absences/${uuid}/void`, { method: 'POST', body })
}

export interface ImportAbsencesInput {
  file: File
  mode: EmployeeImportMode
  /** Obligatorio con `mode: 'apply'`: el `file.sha256` que devolvio la validacion. */
  confirmChecksum?: string
}

/** `POST /absences/import`, multipart, dos fases: `validate` no escribe nada. */
export function importAbsences(input: ImportAbsencesInput): Promise<AbsenceImportReport> {
  const form = new FormData()

  form.set('file', input.file)
  form.set('mode', input.mode)

  if (input.confirmChecksum !== undefined) {
    form.set('confirm_checksum', input.confirmChecksum)
  }

  return requestJson<AbsenceImportReport>('/api/v1/absences/import', {
    method: 'POST',
    body: form,
  })
}
