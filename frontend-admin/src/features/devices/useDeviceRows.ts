// Aritmetica de presentacion de la flota de quioscos (RF-PA-07), pura y sin
// Vue: se prueba sin montar `DevicesView`, igual que `useCredentialRows.ts`
// hace para el tablero de credenciales.
import { minutesBetween } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import type { DurationParts } from '@kronoqr/web-kit/workdayTotals'

/**
 * Tiempo transcurrido desde el ultimo latido, en horas y minutos enteros
 * (nunca decimales: regla dura de presentacion del tiempo). `null` cuando el
 * dispositivo no ha latido nunca —`last_seen_at` a `null`—, que no es lo mismo
 * que «cero minutos»: no hay nada que restar.
 *
 * Se mide contra el reloj del NAVEGADOR (`nowMs`) y no contra uno del
 * servidor: a diferencia de la presencia en vivo, esta cifra es meramente
 * operativa (el contrato lo dice: «lo declara el propio dispositivo y nadie lo
 * comprueba»), y el contrato no publica un «ahora» de referencia para esta
 * lista sin paginar.
 */
export function elapsedSinceHeartbeat(
  lastSeenAt: string | null,
  nowMs: number,
): DurationParts | null {
  if (lastSeenAt === null) {
    return null
  }

  const minutes = minutesBetween(lastSeenAt, new Date(nowMs).toISOString())

  return minutes === null ? null : durationParts(minutes)
}

// `sortDevices` se retiro (revision post-lanzamiento): el orden -activos
// primero, luego por nombre- lo pone ahora el servidor en `GET /devices`, y
// la regla no debe vivir en dos sitios a la vez (la de aqui podia divergir de
// la del backend sin que ninguna prueba lo detectara).
