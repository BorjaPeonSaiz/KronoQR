// La cola. Es lo mas parecido que tiene este producto a un libro de registro.
//
// CUATRO INVARIANTES, y ninguno es negociable:
//
//  1. **Nada sale de la cola sin desenlace del servidor** (§6, «No se pierde
//     nada»; RN-22, ADR-047). Solo salen por dos puertas: `confirm()`, que solo
//     llama quien ha leido un `200` o un `422` estandar para ESE `scan_id`, y
//     `discard()`, que NO borra: MUEVE el fichaje a la lista de descartados, en
//     la misma transaccion, donde espera el acuse de su aviso. Un fallo de red,
//     un `503` o un tiempo de espera agotado no sacan nada.
//  2. **Encolar no puede fallar de cara al empleado** (regla dura 19). Si
//     IndexedDB falla se REABRE (dos intentos) y, solo si la reapertura
//     tambien falla, se cae al respaldo en memoria y se avisa: el fichaje entra.
//  3. **Degradado no es «cola vacia»** (ADR-047). En memoria la cola sabe lo que
//     entro DESPUES, no lo que hay en disco: `stats().size` es `null`
//     («desconocido») y el latido lo dice con `queue_storage`. En cada drenaje se
//     intenta volver al disco, y al volver se migra lo de memoria.
//  4. **El contador que se ve en pantalla no espera a IndexedDB.** Se mantiene
//     un espejo en memoria que se actualiza tras cada mutacion y se publica a
//     los suscriptores. El indicador de RF-KI-04 se pinta de ahi.
//
// ARRENDAMIENTO EN MEMORIA. Los elementos que estan viajando se marcan en un
// `Set`, no en disco: ver la cabecera de `dexieStorage.ts`. Una fila arrendada
// CORTA el prefijo de `claim()` igual que una con espera futura (RN-21): lo que
// viene detras no puede adelantar a un envio que todavia no ha tenido desenlace.

import type { QueuedScan } from '@/features/scan/application/ports'
import type { Clock } from '@/shared/time/clock'
import { systemClock } from '@/shared/time/clock'
import { nextAttemptAt } from '../domain/backoff'
import { orderForSync } from '../domain/queueOrder'
import type {
  DiscardedScanRecord,
  QueuedScanRecord,
  QueueStorage,
  RetrySchedule,
} from '../infrastructure/queueStorage'
import { createMemoryQueueStorage } from '../infrastructure/queueStorage'

/**
 * Techo de filas que se leen de una vez. Con 50 por lote, 500 son diez envios
 * por delante: mas que suficiente para no quedarse corto y poco para que la
 * lectura no crezca sin limite en una tablet que lleva dias sin red. **No es un
 * techo de la cola**: la cola no descarta nada nunca.
 */
export const MAX_ROWS_PER_READ = 500

/** Esperas antes de cada reapertura de IndexedDB: inmediata y a los 250 ms. */
export const REOPEN_DELAYS_MS: readonly number[] = [0, 250]

/** Como mucho una vez por minuto se intenta volver al disco desde memoria. */
export const RECOVERY_INTERVAL_MS = 60_000

/** Donde esta guardando la tablet su cola ahora mismo (`queue_storage` del latido). */
export type QueueStorageKind = 'durable' | 'memory' | 'unavailable'

export interface QueueStats {
  /**
   * Fichajes pendientes, o `null` si NO se sabe (cola en memoria o sin
   * almacenamiento: lo que hay en disco no se ve). Jamas `0` por no saberlo.
   */
  readonly size: number | null
  /** Filas en el almacen ACTIVO. Es un hecho aunque `size` sea desconocido. */
  readonly inStore: number
  /** `occurred_at` del mas antiguo, para el latido. `null` si no hay o no se sabe. */
  readonly oldestOccurredAt: string | null
  /** Epoch ms del proximo intento elegible, o `null` si no hay nada que esperar. */
  readonly nextAttemptAt: number | null
  /** `false` si la cola esta corriendo sobre el respaldo en memoria. */
  readonly durable: boolean
  readonly storage: QueueStorageKind
  /** Descartados cuyo aviso aun no tiene acuse (`unreported_discards` del latido). */
  readonly unreportedDiscards: number
}

export const EMPTY_STATS: QueueStats = {
  size: 0,
  inStore: 0,
  oldestOccurredAt: null,
  nextAttemptAt: null,
  durable: true,
  storage: 'durable',
  unreportedDiscards: 0,
}

export type QueueStatsListener = (stats: QueueStats) => void

export interface EnqueueOutcome {
  readonly stored: boolean
  readonly durable: boolean
}

/** Lo que dijo el servidor al declarar invalida la peticion (RN-22). */
export interface DiscardReason {
  readonly http_status: number
  readonly problem_type: string | null
}

export interface ScanQueue {
  enqueue(scan: QueuedScan): Promise<EnqueueOutcome>
  /** Espejo en memoria. Sincrono: lo consume el indicador de pantalla. */
  stats(): QueueStats
  /**
   * Toma hasta `limit` elementos, ordenados por `occurred_at`, y los arrienda
   * para que un segundo drenaje no los envie a la vez. Es un PREFIJO de la cola
   * ordenada: se detiene en la primera fila cuyo reintento esta en el futuro
   * (salvo `ignoreSchedule`) **o que ya esta arrendada** (RN-21), para que nada
   * posterior adelante a una fila atascada (G1) ni a un envio en vuelo.
   */
  claim(limit: number, options?: { readonly ignoreSchedule?: boolean }): Promise<QueuedScanRecord[]>
  /**
   * Borra. Solo para un `200` o un `422` estandar del servidor.
   *
   * Devuelve `false` si el borrado NO llego a escribirse. Quien llama tiene que
   * mirarlo: un elemento confirmado por el servidor que sigue en disco vuelve a
   * ser elegible al instante, y reenviarlo sin espera es un bucle de peticiones.
   */
  confirm(scanIds: readonly string[]): Promise<boolean>
  /**
   * RN-22: el servidor declaro invalida la peticion. Mueve el fichaje de la cola
   * de envio a la lista de descartados en UNA transaccion; sin `pin_sealed`.
   * `false` si el movimiento no pudo escribirse (el fichaje sigue donde estaba).
   * `scan` puede no estar en la cola: el rescate de `submit()` sin donde encolar.
   */
  discard(scan: QueuedScan | QueuedScanRecord, reason: DiscardReason): Promise<boolean>
  /** Descartados pendientes de aviso. Sin `ignoreSchedule`, solo los que ya les toca. */
  discarded(options?: { readonly ignoreSchedule?: boolean }): Promise<DiscardedScanRecord[]>
  /** Solo con los `scan_id` que volvieron en `acknowledged`. */
  acknowledgeDiscarded(scanIds: readonly string[]): Promise<boolean>
  /** El aviso no salio (o no fue acusado): un intento mas y su espera exponencial. */
  retryDiscardedLater(scanIds: readonly string[], now?: Date): Promise<void>
  /** Devuelve a la cola con un intento mas y su espera exponencial. */
  retryLater(scanIds: readonly string[], now?: Date): Promise<void>
  /** Suelta el arrendamiento sin tocar el contador de intentos. */
  release(scanIds: readonly string[]): void
  /** `true` si la fila mas antigua esta viajando ahora mismo (nadie tiene que sondearla). */
  isHeadInFlight(): boolean
  /** `true` si `claim()` no entrega nada porque en un disco que no se ve puede haber filas anteriores (RN-21). */
  isClaimBlocked(): boolean
  /**
   * Cola en memoria: intenta volver al disco (como mucho cada 60 s) y, si abre,
   * migra a el lo que hubiera en memoria. `true` si volvio a `durable`.
   */
  tryReopen(): Promise<boolean>
  /**
   * Vacia la cola de envio **y la lista de descartados** (llevan el `qr_payload`
   * en claro). Solo para desvinculacion y pruebas; jamas en el camino normal.
   */
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
  /** Inyectable para pruebas: por defecto `setTimeout`. */
  readonly sleep?: (ms: number) => Promise<void>
  readonly reopenDelaysMs?: readonly number[]
  readonly recoveryIntervalMs?: number
}

type Operation<T> = (target: QueueStorage) => Promise<T>

function errorName(error: unknown): string {
  return error instanceof Error ? error.name : 'unknown'
}

export function createScanQueue(options: ScanQueueOptions): ScanQueue {
  const clock = options.clock ?? systemClock
  const sleep =
    options.sleep ?? ((ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms)))
  const reopenDelays = options.reopenDelaysMs ?? REOPEN_DELAYS_MS
  const recoveryIntervalMs = options.recoveryIntervalMs ?? RECOVERY_INTERVAL_MS
  const listeners = new Set<QueueStatsListener>()
  const leased = new Set<string>()

  let store: QueueStorage
  /** `true` si el disco existe pero no se ve: lo que haya en el es desconocido. */
  let diskUnknown = false
  /** `true` si la ultima operacion sobre el respaldo en memoria fallo: ni eso acepta. */
  let memoryBroken = false
  let headId: string | null = null
  /**
   * Ultimo recuento CORRECTO del disco mientras estaba sano. Si se degrada con
   * el disco a 0, lo de memoria es todo lo que hay y puede salir; con otro valor
   * (o sin saberlo) puede haber fichajes ANTERIORES sin enviar en el disco, y
   * nada de memoria sale antes de reabrir (RN-21, ADR-047).
   */
  let lastDiskCount: number | null = null
  let lastReopenAt: number | null = null
  /** Mientras se migra de memoria a disco, ninguna otra operacion toca el almacen. */
  let gate: Promise<void> | null = null
  /** Cola de turnos de reapertura: nunca dos reaperturas a la vez sobre el mismo almacen. */
  let reopenTail: Promise<unknown> = Promise.resolve()

  try {
    store = options.openStorage()
  } catch (error) {
    options.onStorageFailure?.(errorName(error))
    store = createMemoryQueueStorage()
    diskUnknown = true
  }

  function storageKind(): QueueStorageKind {
    if (store.durable) return 'durable'
    return memoryBroken ? 'unavailable' : 'memory'
  }

  let mirror: QueueStats = {
    ...EMPTY_STATS,
    size: diskUnknown ? null : 0,
    durable: store.durable,
    storage: storageKind(),
  }

  function publish(next: QueueStats): void {
    mirror = next
    for (const listener of listeners) listener(next)
  }

  function closeQuietly(target: QueueStorage): void {
    try {
      target.close()
    } catch {
      // Ya estaba cerrado, o no hay nada que cerrar.
    }
  }

  /** Degradacion a memoria en marcha. Nunca se propaga el fallo al empleado. */
  function fallBackToMemory(reason: unknown, current: QueueStorage): void {
    options.onStorageFailure?.(errorName(reason))
    closeQuietly(current)
    store = createMemoryQueueStorage()
    diskUnknown = true
    memoryBroken = false
  }

  /**
   * Reabre IndexedDB y repite la operacion (ADR-047): cierra el manejador roto,
   * abre otro y lo intenta de nuevo, a los 0 y a los 250 ms. Solo si la
   * reapertura tambien falla se cae a memoria. Un fallo puntual (`list()` que
   * lanza una vez) deja el disco intacto y a la cola en `durable`.
   */
  async function reopenAndRepeat<T>(
    failed: QueueStorage,
    operation: Operation<T>,
    firstError: unknown,
  ): Promise<T> {
    const turn = reopenTail.then(async (): Promise<T> => {
      // Otra operacion ya la reabrio (o cayo a memoria) mientras esperaba turno.
      if (store !== failed) {
        const current = store
        try {
          const value = await operation(current)
          if (!current.durable) memoryBroken = false
          return value
        } catch (error) {
          if (!current.durable) memoryBroken = true
          throw error
        }
      }

      let last: unknown = firstError
      let current = failed
      for (const delay of reopenDelays) {
        if (delay > 0) await sleep(delay)
        closeQuietly(current)
        let candidate: QueueStorage
        try {
          candidate = options.openStorage()
        } catch (error) {
          last = error
          continue
        }
        store = candidate
        current = candidate
        try {
          return await operation(candidate)
        } catch (error) {
          last = error
        }
      }

      fallBackToMemory(last, current)
      try {
        const value = await operation(store)
        memoryBroken = false
        return value
      } catch (error) {
        memoryBroken = true
        throw error
      }
    })
    reopenTail = turn.then(
      () => undefined,
      () => undefined,
    )
    return turn
  }

  /** Toda operacion sobre el almacen pasa por aqui: reabre antes de degradar. */
  async function run<T>(operation: Operation<T>): Promise<T> {
    while (gate !== null) await gate
    const target = store
    try {
      const value = await operation(target)
      if (!target.durable && target === store) memoryBroken = false
      return value
    } catch (error) {
      if (!target.durable) {
        memoryBroken = true
        throw error
      }
      return reopenAndRepeat(target, operation, error)
    }
  }

  async function recompute(): Promise<QueueStats> {
    try {
      const rows = await run((target) => target.list(MAX_ROWS_PER_READ))
      const count = await run((target) => target.count())
      const discarded = await run((target) => target.listDiscarded(MAX_ROWS_PER_READ))
      const ordered = orderForSync(rows)
      const oldest = ordered[0]
      headId = oldest?.scan_id ?? null

      const kind = storageKind()
      if (kind === 'durable') lastDiskCount = count
      const known = kind === 'durable' || (kind === 'memory' && !diskUnknown)

      // G1: como `claim()` para en la primera fila con espera, el momento en
      // que la cola puede avanzar es el de la CABEZA, no el minimo de todas:
      // con el minimo, una fila nueva (espera 0) detras de una atascada
      // despertaria el drenaje a cada tic sin que `claim()` pudiera tomar nada.
      // Los avisos de descartados tienen su propia espera: cuenta la menor.
      const headNext = oldest?.next_attempt_at ?? null
      const discardNext =
        discarded.length === 0 ? null : Math.min(...discarded.map((entry) => entry.next_attempt_at))
      const next: QueueStats = {
        size: known ? count : null,
        inStore: count,
        // Con el disco sin ver, «el mas antiguo» seria el de memoria: una
        // afirmacion falsa. El contrato pide omitirlo si no se conoce.
        oldestOccurredAt: known ? (oldest?.occurred_at ?? null) : null,
        nextAttemptAt:
          headNext === null
            ? discardNext
            : discardNext === null
              ? headNext
              : Math.min(headNext, discardNext),
        durable: store.durable,
        storage: kind,
        unreportedDiscards: discarded.length,
      }
      publish(next)
      return next
    } catch {
      // Ni el disco (tras reabrirlo) ni la memoria responden. `unavailable`:
      // lo que se sabe es que no se sabe, y NUNCA «cola vacia».
      headId = null
      const next: QueueStats = {
        size: null,
        inStore: 0,
        oldestOccurredAt: null,
        nextAttemptAt: null,
        durable: false,
        storage: 'unavailable',
        unreportedDiscards: mirror.unreportedDiscards,
      }
      publish(next)
      return next
    }
  }

  function toDiscarded(
    scan: QueuedScan | QueuedScanRecord,
    reason: DiscardReason,
  ): DiscardedScanRecord {
    const bookkeeping = {
      scan_id: scan.scan_id,
      occurred_at: scan.occurred_at,
      http_status: reason.http_status,
      problem_type: reason.problem_type,
      discarded_at: clock.now().toISOString(),
      attempts: 0,
      next_attempt_at: 0,
    }
    // Campo a campo, a proposito: `pin_sealed` no se copia JAMAS (RN-22).
    return scan.kind === 'qr'
      ? { kind: 'qr', qr_payload: scan.qr_payload, ...bookkeeping }
      : { kind: 'pin', employee_code: scan.employee_code, ...bookkeeping }
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
        // `run` ya reabre IndexedDB y, si no hay manera, cae a memoria y repite:
        // el fichaje entra igual. Solo falla si ni la memoria lo acepta.
        await run((target) => target.add(record))
      } catch {
        await recompute()
        return { stored: false, durable: false }
      }

      await recompute()
      return { stored: true, durable: store.durable }
    },

    stats() {
      return mirror
    },

    async claim(limit, claimOptions = {}) {
      // RN-21 con la cola degradada: lo de memoria no puede adelantar a lo que
      // siga en un disco que no se ve. Sale tras `tryReopen()`, mezclado y en orden.
      if (diskUnknown && !store.durable && lastDiskCount !== 0) return []
      const nowMs = clock.now().getTime()
      let rows: QueuedScanRecord[]
      try {
        rows = await run((target) => target.list(MAX_ROWS_PER_READ))
      } catch {
        await recompute()
        return []
      }

      // G1: el PREFIJO de la cola ordenada, no un filtro. Una fila que espera su
      // reintento (`next_attempt_at` futuro) detiene la lectura: lo que viene
      // detras —un escaneo nuevo, con `occurred_at` posterior— no puede
      // adelantarla, porque una salida de las 16:00 que llega antes que la
      // entrada de las 08:00 invierte el turno. Solo `ignoreSchedule` («vuelve
      // la red», «arranque») salta la espera, y entonces sale TODO en orden.
      // RN-21: una fila arrendada (la tiene un envio en vuelo) corta el prefijo
      // igual. Saltarla dejaba que un drenaje enviara B mientras A viajaba, y si
      // A acababa en `503`, B ya la habia adelantado.
      const claimed: QueuedScanRecord[] = []
      for (const row of orderForSync(rows)) {
        if (claimed.length >= limit) break
        if (leased.has(row.scan_id)) break
        if (claimOptions.ignoreSchedule !== true && row.next_attempt_at > nowMs) break
        claimed.push(row)
      }
      for (const row of claimed) leased.add(row.scan_id)
      return claimed
    },

    async confirm(scanIds) {
      if (scanIds.length === 0) return true
      let removed = true
      try {
        await run((target) => target.remove(scanIds))
      } catch (error) {
        // No se ha podido borrar: se reintentara y el servidor devolvera la
        // respuesta original por idempotencia. Perder el borrado es recuperable;
        // perder el fichaje no lo seria.
        removed = false
        options.onStorageFailure?.(errorName(error))
      }
      for (const scanId of scanIds) leased.delete(scanId)
      await recompute()
      return removed
    },

    async discard(scan, reason) {
      const entry = toDiscarded(scan, reason)
      let moved = true
      try {
        await run((target) => target.discard(scan.scan_id, entry))
      } catch (error) {
        moved = false
        options.onStorageFailure?.(errorName(error))
      }
      if (moved) leased.delete(scan.scan_id)
      await recompute()
      return moved
    },

    async discarded(discardedOptions = {}) {
      const nowMs = clock.now().getTime()
      let rows: DiscardedScanRecord[]
      try {
        rows = await run((target) => target.listDiscarded(MAX_ROWS_PER_READ))
      } catch {
        return []
      }
      return discardedOptions.ignoreSchedule === true
        ? rows
        : rows.filter((row) => row.next_attempt_at <= nowMs)
    },

    async acknowledgeDiscarded(scanIds) {
      if (scanIds.length === 0) return true
      let removed = true
      try {
        await run((target) => target.removeDiscarded(scanIds))
      } catch (error) {
        removed = false
        options.onStorageFailure?.(errorName(error))
      }
      await recompute()
      return removed
    },

    async retryDiscardedLater(scanIds, now) {
      if (scanIds.length === 0) return
      const nowMs = (now ?? clock.now()).getTime()

      let rows: DiscardedScanRecord[] = []
      try {
        rows = await run((target) => target.listDiscarded(MAX_ROWS_PER_READ))
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
        await run((target) => target.rescheduleDiscarded(schedules))
      } catch (error) {
        options.onStorageFailure?.(errorName(error))
      }
      await recompute()
    },

    async retryLater(scanIds, now) {
      if (scanIds.length === 0) return
      const nowMs = (now ?? clock.now()).getTime()

      let rows: QueuedScanRecord[] = []
      try {
        rows = await run((target) => target.list(MAX_ROWS_PER_READ))
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
        await run((target) => target.reschedule(schedules))
      } catch (error) {
        options.onStorageFailure?.(errorName(error))
      }
      for (const scanId of scanIds) leased.delete(scanId)
      await recompute()
    },

    release(scanIds) {
      for (const scanId of scanIds) leased.delete(scanId)
    },

    isClaimBlocked() {
      return diskUnknown && !store.durable && lastDiskCount !== 0
    },

    isHeadInFlight() {
      return headId !== null && leased.has(headId)
    },

    async tryReopen() {
      // Solo tiene sentido si hubo un disco que se perdio: una cola que nacio
      // sobre un almacen no duradero (pruebas) no tiene a donde volver.
      if (store.durable || !diskUnknown) return false
      const nowMs = clock.now().getTime()
      if (lastReopenAt !== null && nowMs - lastReopenAt < recoveryIntervalMs) return false
      lastReopenAt = nowMs

      let release: () => void = () => undefined
      gate = new Promise<void>((resolve) => {
        release = () => {
          gate = null
          resolve()
        }
      })

      let candidate: QueueStorage | null = null
      let recovered = false
      try {
        candidate = options.openStorage()
        const memory = store
        const rows = await memory.list(Number.MAX_SAFE_INTEGER)
        const discarded = await memory.listDiscarded(Number.MAX_SAFE_INTEGER)
        // Sondeo explicito: abrir es perezoso y un disco que sigue roto solo se
        // nota al tocarlo.
        await candidate.count()
        // Clave primaria `scan_id`: `add` de una fila que ya esta no duplica, asi
        // que repetir la migracion tras un fallo a medias es seguro.
        for (const row of rows) await candidate.add(row)
        for (const entry of discarded) await candidate.putDiscarded(entry)
        store = candidate
        diskUnknown = false
        memoryBroken = false
        closeQuietly(memory)
        recovered = true
      } catch {
        if (candidate !== null) closeQuietly(candidate)
      } finally {
        release()
      }

      if (recovered) await recompute()
      return recovered
    },

    async clear() {
      try {
        await run((target) => target.clear())
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
