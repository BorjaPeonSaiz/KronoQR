// Informe de horas por periodo (RF-IN-01, RF-IN-02, RF-IN-03). Las formas salen
// del contrato; aqui no se inventa ninguna.
//
// EL ALCANCE POR DEPARTAMENTO DE RF-ID-03 ENTRA EN LA CONSULTA DEL SERVIDOR:
// este cliente no filtra nada por su cuenta, solo pasa lo que la persona ha
// pedido. Y NO RECALCULA NINGUNA CIFRA: los minutos y el `HH:MM` vienen los dos
// del servidor, que es el unico que lee la proyeccion de jornadas (regla dura 7).
import { requestJson } from '@kronoqr/web-kit/http'
import type { PeriodReport, ReportGranularity, ReportGrouping } from '@/shared/api/types'

/** Lo que se pide al informe, en la forma que usa el panel (camelCase). */
export interface PeriodReportQuery {
  /** Primera jornada, `YYYY-MM-DD` en la zona del centro. Obligatoria. */
  from: string
  /** Ultima jornada, inclusive. Obligatoria. */
  to: string
  /** `day` por omision en el servidor. */
  granularity?: ReportGranularity
  /** `employee` por omision en el servidor. */
  groupBy?: ReportGrouping
  departmentId?: number
  /** Identificador **publico** del empleado (`employees.uuid`). */
  employeeUuid?: string
  /**
   * Si los dias con un turno todavia abierto aportan los minutos que ya tienen
   * cerrados. Por omision no (ver `meta.criteria` de la respuesta).
   */
  includeOpenShifts?: boolean
}

export function generatePeriodReport(query: PeriodReportQuery): Promise<PeriodReport> {
  return requestJson<PeriodReport>('/api/v1/reports/period', {
    query: {
      // `undefined` no se serializa: sin filtro no se manda el parametro.
      from: query.from,
      to: query.to,
      granularity: query.granularity,
      group_by: query.groupBy,
      department_id: query.departmentId,
      employee_uuid: query.employeeUuid,
      // Solo se manda cuando esta activo: el valor por omision lo pone el
      // servidor y mandarlo siempre lo escribiria en la URL sin necesidad.
      include_open_shifts: query.includeOpenShifts === true ? true : undefined,
    },
  })
}
