// Aritmetica de presentacion de la flota de quioscos (RF-PA-07), pura y sin
// Vue: se prueba sin montar `DevicesView`, igual que `useCredentialRows.ts`
// hace para el tablero de credenciales.
import { minutesBetween } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import type { DurationParts } from '@kronoqr/web-kit/workdayTotals'

/**
 * Tiempo transcurrido entre un instante UTC y el «ahora», en horas y minutos
 * enteros (nunca decimales: regla dura de presentacion del tiempo). `null`
 * cuando no hay instante que medir -`last_seen_at`/`oldest_pending_at` a
 * `null`-, que no es lo mismo que «cero minutos»: no hay nada que restar.
 *
 * **Se mide contra el reloj del SERVIDOR** (`serverNowMs`, extrapolado desde
 * `meta.generated_at` con el mismo patron que `incidents.store.ts` y
 * `errors.store.ts`: regla dura 3), nunca contra `Date.now()` del navegador
 * -a diferencia de la version anterior a la tarea 3.3, que medía contra el
 * navegador porque el contrato no publicaba un «ahora» de referencia para esta
 * lista sin paginar. Desde `DeviceListMeta` (tarea 3.3), sí lo publica.
 *
 * **Clava a cero un instante «del futuro»**: un reloj de dispositivo mal
 * puesto (la tablet, no el panel) podria declarar un latido posterior al
 * `generated_at` del servidor, y una antiguedad negativa no significa nada
 * para quien lee la pantalla.
 */
function elapsedSince(value: string | null, serverNowMs: number): DurationParts | null {
  if (value === null) {
    return null
  }

  const minutes = minutesBetween(value, new Date(serverNowMs).toISOString())

  return minutes === null ? null : durationParts(Math.max(minutes, 0))
}

/** Tiempo desde el ultimo latido (`Device.last_seen_at`). */
export function elapsedSinceHeartbeat(
  lastSeenAt: string | null,
  serverNowMs: number,
): DurationParts | null {
  return elapsedSince(lastSeenAt, serverNowMs)
}

/** Tiempo desde el fichaje mas antiguo sin sincronizar (`Device.oldest_pending_at`). */
export function elapsedSinceOldestPending(
  oldestPendingAt: string | null,
  serverNowMs: number,
): DurationParts | null {
  return elapsedSince(oldestPendingAt, serverNowMs)
}

// `sortDevices` se retiro (revision post-lanzamiento): el orden -activos
// primero, luego por nombre- lo pone ahora el servidor en `GET /devices`, y
// la regla no debe vivir en dos sitios a la vez (la de aqui podia divergir de
// la del backend sin que ninguna prueba lo detectara).
