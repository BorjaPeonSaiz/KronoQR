// Aritmetica de presentacion de la lista de accesos de soporte (RF-PD-11),
// pura y sin Vue: se prueba sin montar `SupportView`, igual que
// `useDeviceRows.ts` hace para la flota de quioscos.
import { minutesBetween } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import type { DurationParts } from '@kronoqr/web-kit/workdayTotals'

/**
 * Tiempo transcurrido desde el ultimo uso efectivo del token, en horas y
 * minutos enteros (nunca decimales). `null` cuando `accessed_at` es nulo -la
 * concesion nunca se ha usado-, que no es lo mismo que «hace cero minutos».
 *
 * Se mide contra el reloj del NAVEGADOR: `accessed_at` es meramente
 * informativo aqui (la caducidad real la decide el servidor en cada
 * peticion, RF-PD-11), y el contrato no publica un «ahora» de referencia para
 * esta lista.
 */
export function elapsedSinceAccess(accessedAt: string | null, nowMs: number): DurationParts | null {
  if (accessedAt === null) {
    return null
  }

  const minutes = minutesBetween(accessedAt, new Date(nowMs).toISOString())

  return minutes === null ? null : durationParts(minutes)
}
