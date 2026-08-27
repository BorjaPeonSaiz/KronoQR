// Orden y troceado de la cola. Es la garantia «Orden correcto» del §6.
//
// POR QUE IMPORTA TANTO. Una entrada y una salida encoladas sin red y enviadas
// del reves crean una salida sin turno abierto seguida de una entrada: una
// jornada inventada, en un registro con valor legal. El servidor tambien ordena
// el lote antes de tocar nada, pero el cliente NO puede delegar en eso: los
// elementos que no caben en un lote de 50 viajan en la peticion siguiente, y si
// el cliente los reparte mal, ningun orden del servidor lo arregla.
//
// El criterio es `occurred_at`, nunca el orden de llegada ni el de encolado.

/** Techo del contrato para `POST /api/v1/scan/batch` y del §6 al drenar. */
export const MAX_BATCH_SIZE = 50

export interface HasOccurredAt {
  readonly scan_id: string
  readonly occurred_at: string
}

/**
 * Ascendente por `occurred_at`; a igualdad, por `scan_id`.
 *
 * El desempate no es cosmetico: dos escaneos pueden compartir milisegundo, y
 * como el `scan_id` es un UUID v7 —ordenable temporalmente por construccion
 * (§6)— desempatar por el mantiene el orden real de los dos fichajes en lugar
 * de dejarlo al azar del motor de ordenacion.
 */
export function compareByOccurredAt(left: HasOccurredAt, right: HasOccurredAt): number {
  const leftMs = Date.parse(left.occurred_at)
  const rightMs = Date.parse(right.occurred_at)

  // Una marca ilegible no puede tumbar el drenaje ni colarse la primera: se
  // manda al final y viaja igual, que el servidor decida (regla dura 19).
  const leftKey = Number.isNaN(leftMs) ? Number.POSITIVE_INFINITY : leftMs
  const rightKey = Number.isNaN(rightMs) ? Number.POSITIVE_INFINITY : rightMs

  if (leftKey !== rightKey) return leftKey - rightKey
  return left.scan_id < right.scan_id ? -1 : left.scan_id > right.scan_id ? 1 : 0
}

/** Copia ordenada para sincronizar. No muta la entrada. */
export function orderForSync<T extends HasOccurredAt>(records: readonly T[]): T[] {
  return [...records].sort(compareByOccurredAt)
}

interface HasKind {
  readonly kind: string
}

/**
 * Trocea una lista YA ORDENADA por `occurred_at` en tramos maximos de la misma
 * `kind` (tarea 1.12). Existe porque `POST /api/v1/scan/pin` no tiene una
 * variante de lote: un QR y un PIN encolados sin red no pueden viajar en la
 * misma llamada, pero SI tienen que aplicarse en el mismo orden en que
 * ocurrieron (§6, «orden correcto»). El drenaje procesa un tramo entero antes
 * de pasar al siguiente, nunca al reves.
 */
export function splitRuns<T extends HasKind>(records: readonly T[]): T[][] {
  const runs: T[][] = []
  for (const record of records) {
    const last = runs.at(-1)
    if (last !== undefined && last[0]?.kind === record.kind) {
      last.push(record)
    } else {
      runs.push([record])
    }
  }
  return runs
}

/**
 * Trocea en lotes del tamano del contrato, **ya ordenados**. El primer lote
 * lleva siempre los mas antiguos: si solo llega a enviarse uno, lo que se
 * consolida es el principio de la jornada y no un trozo suelto del medio.
 */
export function batchesOf<T extends HasOccurredAt>(
  records: readonly T[],
  size: number = MAX_BATCH_SIZE,
): T[][] {
  const ordered = orderForSync(records)
  const batches: T[][] = []
  for (let index = 0; index < ordered.length; index += size) {
    batches.push(ordered.slice(index, index + size))
  }
  return batches
}
