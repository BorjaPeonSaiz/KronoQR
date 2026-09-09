// Detalle de jornada (RF-PA-03).
//
// Un unico endpoint, y de solo lectura: `GET /employees/{uuid}/workdays`. Aqui
// no se compone nada ni se suma nada — la forma sale entera del contrato— y
// tampoco se convierte ninguna hora: las marcas ya vienen en UTC y resueltas en
// la zona del centro (regla dura 3).
//
// **Rectificar sigue sin pasar por aqui, a proposito.** Añadir un tramo,
// corregirlo o anularlo (RF-PA-04) es `corrections.api.ts`, que exige el
// ambito `attendance:correct` y no el `attendance:read` con el que se puede
// pedir esta lectura. Dos ficheros y no uno es lo que permite que un rol de
// solo lectura (el `auditor`, `attendance:read` sin `attendance:correct`) siga
// pudiendo consultar el registro sin que este modulo le ofrezca, ni de lejos,
// nada que escriba en el.
import { requestJson } from '@kronoqr/web-kit/http'
import type { EmployeeWorkDays } from '@/shared/api/types'

/** Rango de **jornadas** (`work_date`), no de instantes. Vacio = lo resuelve el servidor. */
export interface WorkDateRange {
  from: string
  to: string
}

/** Un rango sin pedir: el servidor decide, que es quien sabe que dia es hoy en el centro. */
export const UNBOUNDED_RANGE: WorkDateRange = { from: '', to: '' }

export function listEmployeeWorkDays(
  uuid: string,
  range: WorkDateRange = UNBOUNDED_RANGE,
): Promise<EmployeeWorkDays> {
  return requestJson<EmployeeWorkDays>(`/api/v1/employees/${uuid}/workdays`, {
    query: {
      // `undefined` no se serializa. Sin `from`/`to`, el servidor toma los 31
      // dias que terminan hoy **en la zona del centro**: calcular aqui ese «hoy»
      // usaria la zona del navegador, que es justo la que no vale.
      from: range.from === '' ? undefined : range.from,
      to: range.to === '' ? undefined : range.to,
    },
  })
}
