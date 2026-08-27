// La cola. Es lo mas parecido que tiene este producto a un libro de registro.
//
// TRES INVARIANTES, y ninguno es negociable:
//
//  1. **Nada se borra sin confirmacion explicita del servidor** (§6, «No se
//     pierde nada»). `confirm()` es el unico camino que borra, y solo lo llama
//     quien ha leido un `200` o un `422` para ESE `scan_id`. Un fallo de red no
//     borra; un `503` no borra; un tiempo de espera agotado no borra.
//  2. **Encolar no puede fallar de cara al empleado** (regla dura 19). Si
//     IndexedDB no esta disponible se cae al respaldo en memoria y se avisa,
//     pero el fichaje entra.
//  3. **El contador que se ve en pantalla no espera a IndexedDB.** Se mantiene
//     un espejo en memoria que se actualiza tras cada mutacion y se publica a
//     los suscriptores. El indicador de RF-KI-04 se pinta de ahi.
//
// ARRENDAMIENTO EN MEMORIA. Los elementos que estan viajando se marcan en un
// `Set`, no en disco: ver la cabecera de `dexieStorage.ts`.

import type { QueuedScan } from '@/features/scan/application/ports'
import type { Clock } from '@/shared/time/clock'
import { systemClock } from '@/shared/time/clock'
import { nextAttemptAt } from '../domain/backoff'
import { orderForSync } from '../domain/queueOrder'
import type { QueuedScanRecord, QueueStorage, RetrySchedule } from '../infrastructure/queueStorage'
import { createMemoryQueueStorage } from '../infrastructure/queueStorage'

/**
 * Techo de filas que se leen de una vez. Con 50 por lote, 500 son diez envios
 * por delante: mas que suficiente para no quedarse corto y poco para que la
 * lectura no crezca sin limite en una tablet que lleva dias sin red. **No es un
 * techo de la cola**: la cola no descarta nada nunca.
 */
export const MAX_ROWS_PER_READ = 500

export interface QueueStats {
  readonly size: number
  /** `occurred_at` del mas antiguo, para el latido (`oldest_pending_at`). */
  readonly oldestOccurredAt: string | null
  /** Epoch ms del proximo intento elegible, o `null` si no hay nada que esperar. */
  readonly nextAttemptAt: number | null
  /** `false` si la cola esta corriendo sobre el respaldo en memoria. */
  readonly durable: boolean
}

export const EMPTY_STATS: QueueStats = {
  size: 0,
  oldestOccurredAt: null,
  nextAttemptAt: null,
  durable: true,
}

export type QueueStatsListener = (stats: QueueStats) => void

export interface EnqueueOutcome {
  readonly stored: boolean
  readonly durable: boolean
}

export interface ScanQueue {
  enqueue(scan: QueuedScan): Promise<EnqueueOutcome>
  /** Espejo en memoria. Sincrono: lo consume el indicador de pantalla. */
  stats(): QueueStats
  /**
   * Toma hasta `limit` elementos elegibles, ordenados por `occurred_at`, y los
   * arrienda para que un segundo drenaje no los envie a la vez.
   */
  claim(limit: number, options?: { readonly ignoreSchedule?: boolean }): Promise<QueuedScanRecord[]>
  /**
   * Borra. Es el UNICO camino que borra, y exige confirmacion del servidor.
   *
   * Devuelve `false` si el borrado NO llego a escribirse. Quien llama tiene que
   * mirarlo: un elemento confirmado por el servidor que sigue en disco vuelve a
   * ser elegible al instante, y reenviarlo sin espera es un bucle de peticiones.
   */
  confirm(scanIds: readonly string[]): Promise<boolean>
  /** Devuelve a la cola con un intento mas y su espera exponencial. */
  retryLater(scanIds: readonly string[], now?: Date): Promise<void>
  /** Suelta el arrendamiento sin tocar el contador de intentos. */
  release(scanIds: readonly string[]): void
  /** Vacia la cola. Solo para desvinculacion y pruebas; jamas en el camino normal. */
  clear(): Promise<void>
  subscribe(listener: QueueStatsListener): () => void
  refresh(): Promise<QueueStats>
  storage(): QueueStorage
}

export interface ScanQueueOptions {
  /** Fabrica de la persistencia real. Si lanza, se cae al respaldo en memoria. */
  readonly openStorage: () => QueueStorage
  readonly clock?: Clock
  readonly onStorageFailure?: (reason: string) => void
}

export function createScanQueue(options: ScanQueueOptions): ScanQueue {
  const clock = options.clock ?? systemClock
  const listeners = new Set<QueueStatsListener>()
  const leased = new Set<string>()

  let store: QueueStorage
  try {
    store = options.openStorage()
  } catch (error) {
    options.onStorageFailure?.(error instanceof Error ? error.name : 'unknown')
    store = createMemoryQueueStorage()
  }

  let mirror: QueueStats = { ...EMPTY_STATS, durable: store.durable }

  function publish(next: QueueStats): void {
    mirror = next
    for (const listener of listeners) listener(next)
  }

  /** Degradacion a memoria en marcha. Nunca se propaga el fallo al empleado. */
  function fallBackToMemory(reason: string): void {
    if (!store.durable) return
    options.onStorageFailure?.(reason)
    store.close()
    store = createMemoryQueueStorage()
  }

  async function recompute(): Promise<QueueStats> {
    try {
      const rows = await store.list(MAX_ROWS_PER_READ)
      const count = await store.count()
      const ordered = orderForSync(rows)
      const oldest = ordered[0]
      const schedules = ordered.map((row) => row.next_attempt_at)
      const next: QueueStats = {
        size: count,
        oldestOccurredAt: oldest?.occurred_at ?? null,
        nextAttemptAt: schedules.length === 0 ? null : Math.min(...schedules),
        durable: store.durable,
      }
      publish(next)
      return next
    } catch (error) {
      fallBackToMemory(error instanceof Error ? error.name : 'unknown')
      const next: QueueStats = { ...EMPTY_STATS, durable: store.durable }
      publish(next)
      return next
    }
  }

  return {
    async enqueue(scan) {
      const bookkeeping = {
        occurred_at: scan.occurred_at,
        intent: scan.intent,
        device_id: scan.device_id,
        attempts: 0,
        next_attempt_at: 0,
        enqueued_at: clock.now().getTime(),
      }
      const record: QueuedScanRecord =
        scan.kind === 'qr'
          ? { kind: 'qr', scan_id: scan.scan_id, qr_payload: scan.qr_payload, ...bookkeeping }
          : {
              kind: 'pin',
              scan_id: scan.scan_id,
              employee_code: scan.employee_code,
              pin_sealed: scan.pin_sealed,
              ...bookkeeping,
            }

      try {
        await store.add(record)
      } catch (error) {
        // Segunda oportunidad en memoria: el fichaje entra igual.
        fallBackToMemory(error instanceof Error ? error.name : 'unknown')
        try {
          await store.add(record)
        } catch {
          await recompute()
          return { stored: false, durable: false }
        }
      }

      await recompute()
      return { stored: true, durable: store.durable }
    },

    stats() {
      return mirror
    },

    async claim(limit, claimOptions = {}) {
      const nowMs = clock.now().getTime()
      let rows: QueuedScanRecord[]
      try {
        rows = await store.list(MAX_ROWS_PER_READ)
      } catch (error) {
        fallBackToMemory(error instanceof Error ? error.name : 'unknown')
        return []
      }

      const eligible = orderForSync(rows).filter(
        (row) =>
          !leased.has(row.scan_id) &&
          (claimOptions.ignoreSchedule === true || row.next_attempt_at <= nowMs),
      )
      const claimed = eligible.slice(0, limit)
      for (const row of claimed) leased.add(row.scan_id)
      return claimed
    },

    async confirm(scanIds) {
      if (scanIds.length === 0) return true
      let removed = true
      try {
        await store.remove(scanIds)
      } catch (error) {
        // No se ha podido borrar: se reintentara y el servidor devolvera la
        // respuesta original por idempotencia. Perder el borrado es recuperable;
        // perder el fichaje no lo seria.
        removed = false
        options.onStorageFailure?.(error instanceof Error ? error.name : 'unknown')
      }
      for (const scanId of scanIds) leased.delete(scanId)
      await recompute()
      return removed
    },

    async retryLater(scanIds, now) {
      if (scanIds.length === 0) return
      const nowMs = (now ?? clock.now()).getTime()

      let rows: QueuedScanRecord[] = []
      try {
        rows = await store.list(MAX_ROWS_PER_READ)
      } catch {
        rows = []
      }
      const byId = new Map(rows.map((row) => [row.scan_id, row]))

      const schedules: RetrySchedule[] = []
      for (const scanId of scanIds) {
        const current = byId.get(scanId)
        if (current === undefined) continue
        const attempts = current.attempts + 1
        schedules.push({
          scan_id: scanId,
          attempts,
          next_attempt_at: nextAttemptAt(attempts, nowMs),
        })
      }

      try {
        await store.reschedule(schedules)
      } catch (error) {
        options.onStorageFailure?.(error instanceof Error ? error.name : 'unknown')
      }
      for (const scanId of scanIds) leased.delete(scanId)
      await recompute()
    },

    release(scanIds) {
      for (const scanId of scanIds) leased.delete(scanId)
    },

    async clear() {
      try {
        await store.clear()
      } catch {
        // Nada que hacer: el recuento se recalcula igual.
      }
      leased.clear()
      await recompute()
    },

    subscribe(listener) {
      listeners.add(listener)
      listener(mirror)
      return () => {
        listeners.delete(listener)
      }
    },

    refresh: recompute,
    storage: () => store,
  }
}
