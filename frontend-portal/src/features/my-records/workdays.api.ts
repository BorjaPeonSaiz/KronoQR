// Mi propio registro de jornada (RF-ID-05, RF-ID-06, RF-ID-07, RL-05).
//
// Un unico endpoint, de solo lectura: `GET /api/v1/me/workdays`. **No lleva
// ningun identificador de empleado**, ni en la ruta ni en la consulta, y esa
// ausencia es la autorizacion (regla dura 18): el empleado se resuelve del
// token de portal (`self:read`), que es lo unico que este cliente no puede
// falsificar. No hay una URL que manipular para llegar al registro de otra
// persona porque no hay ningun parametro que apunte a "otra persona".
import { requestJson } from '@kronoqr/web-kit/http'
import type { EmployeeWorkDays } from '@/shared/api/types'

/** Rango de **jornadas** (`work_date`), no de instantes. Vacio = lo resuelve el servidor. */
export interface WorkDateRange {
  from: string
  to: string
}

/** Un rango sin pedir: el servidor decide, que es quien sabe que dia es hoy en el centro. */
export const UNBOUNDED_RANGE: WorkDateRange = { from: '', to: '' }

export function listMyWorkDays(range: WorkDateRange = UNBOUNDED_RANGE): Promise<EmployeeWorkDays> {
  return requestJson<EmployeeWorkDays>('/api/v1/me/workdays', {
    query: {
      // `undefined` no se serializa. Sin `from`/`to`, el servidor toma los 31
      // dias que terminan hoy **en la zona del centro**: calcular aqui ese «hoy»
      // usaria la zona del navegador, que es justo la que no vale (regla dura 3).
      from: range.from === '' ? undefined : range.from,
      to: range.to === '' ? undefined : range.to,
    },
  })
}
