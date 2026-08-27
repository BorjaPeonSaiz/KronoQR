// Centros y departamentos. Viven en `shared` y no dentro de una feature porque
// los usan las dos: el alta de empleado los necesita para sus selectores y el
// panel de credenciales necesita la ZONA HORARIA del centro para poder enseñar
// una hora que signifique algo (regla dura 3, RN-05).
import { requestJson } from './http'
import type { DepartmentCollection, SiteCollection } from './types'

export function listSites(): Promise<SiteCollection> {
  return requestJson<SiteCollection>('/api/v1/sites')
}

export function listDepartments(siteId?: number): Promise<DepartmentCollection> {
  return requestJson<DepartmentCollection>('/api/v1/departments', {
    query: { site_id: siteId },
  })
}
