// El centro y los departamentos de la instalacion. Viven en `shared` y no
// dentro de una feature porque los usan varias: el alta de empleado necesita
// los departamentos para su selector, y el panel de credenciales y la ficha
// necesitan la ZONA HORARIA del centro para poder enseñar una hora que
// signifique algo (regla dura 3, RN-05).
//
// Hay exactamente un centro por instalacion (ADR-040): el recurso es singular
// y ninguna pantalla lo elige.
import { requestJson } from '@kronoqr/web-kit/http'
import type { DepartmentCollection, Site } from './types'

export function getSite(): Promise<Site> {
  return requestJson<Site>('/api/v1/site')
}

export function listDepartments(): Promise<DepartmentCollection> {
  return requestJson<DepartmentCollection>('/api/v1/departments')
}
