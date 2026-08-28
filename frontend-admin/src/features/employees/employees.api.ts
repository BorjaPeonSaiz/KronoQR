// Plantilla: listado, ficha, alta, modificacion, baja y PIN (RF-GP-01, RF-GP-03,
// RF-ID-09). Todas las formas salen del contrato; aqui no se inventa ninguna.
import { requestJson } from '@kronoqr/web-kit/http'
import type {
  CreateEmployeeRequest,
  Employee,
  EmployeeCollection,
  EmployeeProvisioned,
  EmploymentStatus,
  IssuedPin,
  OffboardEmployeeRequest,
  PinDeliveryReceipt,
  UpdateEmployeeRequest,
} from '@/shared/api/types'

/** Filtros y pagina del listado. La paginacion es del servidor (contrato §PerPage). */
export interface EmployeeListQuery {
  page: number
  perPage: number
  status?: EmploymentStatus
  siteId?: number
  departmentId?: number
  /**
   * Subcadena sobre nombre, apellidos, «nombre apellidos» y codigo de
   * empleado, insensible a mayusculas y a acentos. La resuelve el servidor (parametro `q`
   * del contrato), no el cliente.
   */
  q?: string
}

export function listEmployees(query: EmployeeListQuery): Promise<EmployeeCollection> {
  return requestJson<EmployeeCollection>('/api/v1/employees', {
    query: {
      page: query.page,
      per_page: query.perPage,
      status: query.status,
      site_id: query.siteId,
      department_id: query.departmentId,
      q: query.q,
    },
  })
}

export function getEmployee(uuid: string): Promise<Employee> {
  return requestJson<Employee>(`/api/v1/employees/${uuid}`)
}

/**
 * Alta. La respuesta trae el PIN recien emitido y **es la unica vez que existe
 * en claro**: quien llame a esto tiene que enseñarlo en el acto (RF-ID-09).
 */
export function createEmployee(body: CreateEmployeeRequest): Promise<EmployeeProvisioned> {
  return requestJson<EmployeeProvisioned>('/api/v1/employees', { method: 'POST', body })
}

export function updateEmployee(uuid: string, body: UpdateEmployeeRequest): Promise<Employee> {
  return requestJson<Employee>(`/api/v1/employees/${uuid}`, { method: 'PATCH', body })
}

/** Baja logica. Nunca borra: la ficha y su historico se conservan (regla dura 5, RL-02). */
export function offboardEmployee(uuid: string, body: OffboardEmployeeRequest): Promise<Employee> {
  return requestJson<Employee>(`/api/v1/employees/${uuid}/offboard`, { method: 'POST', body })
}

/** Restablece el PIN. Devuelve el nuevo en claro, una sola vez. */
export function resetEmployeePin(uuid: string): Promise<IssuedPin> {
  return requestJson<IssuedPin>(`/api/v1/employees/${uuid}/pin/reset`, { method: 'POST' })
}

/** Acuse de la entrega presencial del PIN. No devuelve ningun PIN. */
export function deliverEmployeePin(uuid: string): Promise<PinDeliveryReceipt> {
  return requestJson<PinDeliveryReceipt>(`/api/v1/employees/${uuid}/pin/deliver`, {
    method: 'POST',
  })
}
