// El centro y los departamentos de la instalacion. Viven en `shared` y no
// dentro de una feature porque los usan varias: el alta de empleado necesita
// los departamentos para su selector, y el panel de credenciales y la ficha
// necesitan la ZONA HORARIA del centro para poder enseñar una hora que
// signifique algo (regla dura 3, RN-05).
//
// Hay exactamente un centro por instalacion (ADR-040): el recurso es singular
// y ninguna pantalla lo elige.
import { requestJson } from '@kronoqr/web-kit/http'
import type {
  CreateDepartmentRequest,
  Department,
  DepartmentCollection,
  Site,
  UpdateDepartmentRequest,
  UpdateSiteRequest,
} from './types'

export function getSite(): Promise<Site> {
  return requestJson<Site>('/api/v1/site')
}

/** Renombra el centro. La zona horaria no se envia desde el panel (R6-AR-02). */
export function updateSite(body: UpdateSiteRequest): Promise<Site> {
  return requestJson<Site>('/api/v1/site', { method: 'PATCH', body })
}

export function listDepartments(): Promise<DepartmentCollection> {
  return requestJson<DepartmentCollection>('/api/v1/departments')
}

/** Alta de un departamento del centro (RF-GP-01, paso «departamentos» del asistente). */
export function createDepartment(body: CreateDepartmentRequest): Promise<Department> {
  return requestJson<Department>('/api/v1/departments', { method: 'POST', body })
}

/**
 * Renombra el departamento y/o cambia su responsable (RF-ID-03). El
 * `manager_user_uuid` solo lo admite `admin` con `accounts:*`: a cualquier otro
 * rol que lo envie, el servidor le responde `403`.
 */
export function updateDepartment(id: number, body: UpdateDepartmentRequest): Promise<Department> {
  return requestJson<Department>(`/api/v1/departments/${id}`, { method: 'PATCH', body })
}
