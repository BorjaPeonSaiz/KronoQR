// Vista de cumplimiento (RF-PA-06, tarea 3.4): descanso insuficiente entre
// jornadas (RN-10), jornada diaria excesiva (RN-11), tramo continuado sin
// pausa (RN-12, suspendida) y exceso semanal informativo (RN-17). La forma
// sale del contrato; aqui no se inventa ninguna.
//
// SOLO LEE. El alcance por departamento de RF-ID-03 entra en la consulta del
// servidor: este cliente no filtra nada por su cuenta, solo pasa lo que la
// persona ha pedido. El umbral de cada regla y el perfil que lo fija viajan en
// la respuesta (`meta.rules[]`, `meta.profile`, regla dura 14): nunca se copia
// un limite legal aqui.
import { requestJson } from '@kronoqr/web-kit/http'
import type { ComplianceRuleName, ComplianceSummary } from '@/shared/api/types'

/** Filtros de la vista, en la forma que usa el panel (camelCase). */
export interface ComplianceSummaryQuery {
  /** Primera jornada, `YYYY-MM-DD`. Sin ella, el servidor toma los 28 dias que terminan en `to`. */
  from?: string
  /** Ultima jornada, inclusive. Sin ella, el servidor toma hoy en la zona del centro. */
  to?: string
  departmentId?: number
  /** Identificador **publico** del empleado (`employees.uuid`). */
  employeeUuid?: string
  rule?: ComplianceRuleName
}

export function getComplianceSummary(
  query: ComplianceSummaryQuery = {},
): Promise<ComplianceSummary> {
  return requestJson<ComplianceSummary>('/api/v1/compliance/summary', {
    query: {
      // `undefined` no se serializa: sin filtro no se manda el parametro, y el
      // servidor aplica sus valores por omision (28 dias hasta hoy).
      from: query.from,
      to: query.to,
      department_id: query.departmentId,
      employee_uuid: query.employeeUuid,
      rule: query.rule,
    },
  })
}
