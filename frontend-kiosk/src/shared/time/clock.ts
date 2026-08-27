// Puerto de reloj del cliente.
//
// El backend tiene la regla dura 2 (nunca `now()` en el dominio) por un motivo
// que aqui vale igual: sin un reloj inyectable no se puede probar el saludo del
// turno de noche, ni la marca `occurred_at` de un fichaje encolado, ni el
// desfase contra el servidor. `Date.now()` esparcido por los composables
// convierte esas pruebas en pruebas que dependen de la hora a la que se
// ejecutan.

export interface Clock {
  /** Instante actual. Siempre se convierte a UTC antes de viajar (regla dura 3). */
  now(): Date
}

export const systemClock: Clock = {
  now: () => new Date(),
}

/**
 * Instante en UTC con forma ISO-8601, que es lo que acepta el contrato
 * (`UtcTimestamp`). `toISOString()` ya devuelve UTC con sufijo `Z`.
 */
export function toUtcIso(instant: Date): string {
  return instant.toISOString()
}

/**
 * Reloj fijo para pruebas. Vive aqui y no en `tests/` para que cualquier
 * composable pueda recibirlo sin que las pruebas tengan que reimplementarlo.
 */
export function fixedClock(instant: Date): Clock {
  return { now: () => new Date(instant.getTime()) }
}
