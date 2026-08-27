// El drenaje de la cola. Implementa el `alt` del diagrama del §6.
//
// DOS CAMINOS, Y LA DIFERENCIA NO ES COSMETICA
// --------------------------------------------
// «Hay conexion»  → `POST /api/v1/scan` con `Idempotency-Key: scan_id`. Se usa
//                   SOLO cuando el escaneo recien encolado es el UNICO pendiente.
//                   Su valor es la respuesta: trae el `action` y el acumulado
//                   real del dia, y con eso la pantalla pasa de «pendiente de
//                   validar» a «Entrada 06:02 · Hoy: 0 h 0 min».
// «Sin conexion»  → `POST /api/v1/scan/batch`, lotes de 50 ordenados por
//                   `occurred_at`, respuesta 207 elemento a elemento.
//
// POR QUE «SOLO SI ES EL UNICO PENDIENTE». Porque si hay algo encolado por
// delante, enviar el nuevo por su cuenta lo adelanta. Una entrada de las 08:00
// atrapada sin red y una salida de las 16:00 enviada al instante producen una
// salida sin turno abierto: la jornada queda del reves. En cuanto hay cola, TODO
// va por el lote ordenado. Es la garantia «Orden correcto» aplicada tambien al
// camino rapido.
//
// QUE SACA UN ELEMENTO DE LA COLA. Solo `200` o `422` para ESE `scan_id`.
// - `200`: registrado (o anti-rebote, que es un desenlace aceptado, ADR-031).
// - `422`: el servidor decidio rechazarlo. Reintentar daria `422` para siempre.
// - `503` (`ScanNotProcessed`): NO se decidio nada. Se conserva y se reintenta.
// - Fallo de transporte, 401, 403, 429, 5xx: no se toca nada. Se reintenta.
//
// BATERIA. Si el navegador dice que no hay red, no se hace la peticion: se
// espera al evento `online`. `navigator.onLine === false` es la unica senal
// fiable que da (en `true` miente). Con el techo de 5 minutos del retroceso,
// una tablet incomunicada hace doce intentos a la hora, no miles.

import type { QueuedScan, ScanSubmissionResult } from '@/features/scan/application/ports'
import type { ApiClient } from '@/shared/api/client'
import type { ScanBatchEntry, ScanRequest } from '@/shared/api/types'
import { uuidV7 } from '@/shared/ids/uuidV7'
import type { Clock } from '@/shared/time/clock'
import { systemClock } from '@/shared/time/clock'
import { batchesOf, MAX_BATCH_SIZE } from '../domain/queueOrder'
import type { QueuedScanRecord } from '../infrastructure/queueStorage'
import type { ScanQueue } from './scanQueue'

/** Cuando no hay nada elegible, se vuelve a mirar de vez en cuando por si acaso. */
export const IDLE_POLL_MS = 30_000

/**
 * Techo de lotes por pasada de drenaje. Con 50 por lote son 500 fichajes de una
 * sentada: mas de lo que acumula una tablet en un dia sin red. No es un limite
 * funcional —lo que quede se drena en la pasada siguiente, que se programa
 * sola—, es un cinturon: cualquier fallo que impida a la cola encoger dejaria el
 * bucle enviando sin descanso, y eso en una tablet al 8 % de bateria es peor que
 * sincronizar un poco mas tarde.
 */
export const MAX_BATCHES_PER_DRAIN = 10

export type SyncDiagnostic =
  | 'sync.transport_failed'
  | 'sync.unauthorized'
  | 'sync.throttled'
  | 'sync.malformed_response'
  | 'sync.item_not_processed'
  | 'sync.confirm_not_persisted'

export interface SyncRunnerOptions {
  readonly api: ApiClient
  readonly queue: ScanQueue
  readonly clock?: Clock
  readonly newBatchKey?: () => string
  /** Estado real de la red segun el ultimo intento. Alimenta el indicador. */
  readonly onReachability?: (reachable: boolean) => void
  readonly onSyncing?: (syncing: boolean) => void
  readonly onDiagnostic?: (code: SyncDiagnostic, context: Record<string, string | number>) => void
  /** Inyectable para pruebas: por defecto, `navigator.onLine`. */
  readonly isOnline?: () => boolean
  readonly setTimer?: (handler: () => void, delayMs: number) => number
  readonly clearTimer?: (handle: number) => void
}

export interface SyncRunner {
  start(): void
  stop(): void
  /** Drena ahora, saltandose la espera pendiente. Se usa al recuperar la red. */
  wakeNow(): void
  /** Encola y, si procede, envia de inmediato. Es lo que usa el puerto de escaneo. */
  submit(scan: QueuedScan): Promise<ScanSubmissionResult>
  drain(options?: { readonly ignoreSchedule?: boolean }): Promise<void>
}

function toRequest(record: QueuedScanRecord): ScanRequest {
  return {
    scan_id: record.scan_id,
    occurred_at: record.occurred_at,
    qr_payload: record.qr_payload,
    intent: record.intent,
  }
}

function browserIsOnline(): boolean {
  if (typeof navigator === 'undefined') return true
  return navigator.onLine !== false
}

export function createSyncRunner(options: SyncRunnerOptions): SyncRunner {
  const clock = options.clock ?? systemClock
  const queue = options.queue
  const isOnline = options.isOnline ?? browserIsOnline
  const newBatchKey = options.newBatchKey ?? (() => uuidV7(clock.now().getTime()))
  const setTimer =
    options.setTimer ?? ((handler, delayMs) => setTimeout(handler, delayMs) as unknown as number)
  const clearTimer = options.clearTimer ?? ((handle) => clearTimeout(handle))

  let timer: number | null = null
  let running = false
  let draining = false
  /** Un despertador pedido mientras se drenaba: se atiende al terminar. */
  let rerun = false

  function cancelTimer(): void {
    if (timer === null) return
    clearTimer(timer)
    timer = null
  }

  function scheduleNext(): void {
    cancelTimer()
    if (!running) return

    const stats = queue.stats()
    if (stats.size === 0) return

    const nowMs = clock.now().getTime()
    const due = stats.nextAttemptAt ?? nowMs
    const delay = Math.max(0, Math.min(due - nowMs, IDLE_POLL_MS))
    timer = setTimer(() => {
      timer = null
      void drain()
    }, delay)
  }

  /** Aplica el resultado de un elemento del 207 sobre la cola. */
  function classify(entry: ScanBatchEntry): 'confirm' | 'retry' {
    if (entry.status === 200 || entry.status === 422) return 'confirm'
    options.onDiagnostic?.('sync.item_not_processed', { http_status: entry.status })
    return 'retry'
  }

  async function sendBatch(records: readonly QueuedScanRecord[]): Promise<boolean> {
    const result = await options.api.syncScanBatch({ scans: records.map(toRequest) }, newBatchKey())

    if (result.outcome !== 'ok') {
      options.onReachability?.(false)

      if (result.outcome === 'failed') {
        if (result.cause === 'unauthorized') {
          // El token del dispositivo esta caducado o revocado. La cola NO se
          // toca: cuando se vuelva a emparejar, los fichajes siguen ahi. Un
          // quiosco desautorizado no es motivo para perder una jornada.
          options.onDiagnostic?.('sync.unauthorized', { http_status: result.httpStatus ?? 0 })
        } else if (result.cause === 'throttled') {
          options.onDiagnostic?.('sync.throttled', { http_status: result.httpStatus ?? 0 })
        } else if (result.cause !== 'offline') {
          options.onDiagnostic?.('sync.transport_failed', { cause: result.cause })
        }
      }

      await queue.retryLater(
        records.map((record) => record.scan_id),
        clock.now(),
      )
      return false
    }

    options.onReachability?.(true)

    const byId = new Map(result.data.results.map((entry) => [entry.scan_id, entry]))
    const confirmed: string[] = []
    const retry: string[] = []

    for (const record of records) {
      const entry = byId.get(record.scan_id)
      if (entry === undefined) {
        // El servidor no ha dicho nada de este elemento. Se conserva: el
        // silencio no es una confirmacion.
        options.onDiagnostic?.('sync.malformed_response', { missing: 1 })
        retry.push(record.scan_id)
        continue
      }
      if (classify(entry) === 'confirm') confirmed.push(record.scan_id)
      else retry.push(record.scan_id)
    }

    const removed = await queue.confirm(confirmed)
    await queue.retryLater(retry, clock.now())

    if (!removed) {
      // El servidor confirmo, pero el borrado no llego a escribirse (IndexedDB
      // lleno o corrupto). Esas filas siguen elegibles AHORA MISMO: sin esto se
      // reclamarian y reenviarian sin pausa, un bucle de peticiones en una
      // tablet que probablemente ya este mal. Se aplazan con retroceso; reenviar
      // es seguro porque el `scan_id` es la clave de idempotencia (regla dura 8).
      options.onDiagnostic?.('sync.confirm_not_persisted', { items: confirmed.length })
      await queue.retryLater(confirmed, clock.now())
      return false
    }

    return confirmed.length > 0
  }

  async function drain(drainOptions: { readonly ignoreSchedule?: boolean } = {}): Promise<void> {
    if (draining) {
      rerun = true
      return
    }
    draining = true
    options.onSyncing?.(true)

    try {
      let ignoreSchedule = drainOptions.ignoreSchedule === true

      for (let pass = 0; pass < MAX_BATCHES_PER_DRAIN; pass += 1) {
        const claimed = await queue.claim(MAX_BATCH_SIZE, { ignoreSchedule })
        if (claimed.length === 0) return

        if (!isOnline()) {
          // Ni se intenta: se ahorra la radio y el evento `online` despertara.
          queue.release(claimed.map((record) => record.scan_id))
          options.onReachability?.(false)
          return
        }

        const batches = batchesOf(claimed, MAX_BATCH_SIZE)
        const batch = batches[0]
        if (batch === undefined) return

        const progressed = await sendBatch(batch)
        if (!progressed) return

        // Los siguientes lotes ya no se saltan la espera: solo el primero
        // hereda el «acabo de volver la red».
        ignoreSchedule = false
      }
    } finally {
      draining = false
      options.onSyncing?.(false)
      scheduleNext()
      if (rerun) {
        rerun = false
        void drain()
      }
    }
  }

  async function submit(scan: QueuedScan): Promise<ScanSubmissionResult> {
    const outcome = await queue.enqueue(scan)
    if (!outcome.stored) {
      // Ni IndexedDB ni memoria. No se puede prometer reintento, asi que se
      // intenta AHORA aunque sea lo unico que quede.
      const rescue = await options.api.recordScan({
        scan_id: scan.scan_id,
        occurred_at: scan.occurred_at,
        qr_payload: scan.qr_payload,
        intent: scan.intent,
      })
      if (rescue.outcome === 'ok') {
        options.onReachability?.(true)
        return rescue.data.action === 'debounced'
          ? { kind: 'debounced', response: rescue.data }
          : { kind: 'accepted', response: rescue.data }
      }
      if (rescue.outcome === 'rejected') return { kind: 'rejected' }
      options.onReachability?.(false)
      return { kind: 'deferred' }
    }

    const stats = queue.stats()
    const alone = stats.size <= 1

    if (!alone || !isOnline()) {
      // Hay cola por delante (o no hay red): el orden manda. Se drena por lote.
      wakeNow()
      return { kind: 'deferred' }
    }

    const claimed = await queue.claim(1, { ignoreSchedule: true })
    const mine = claimed.find((record) => record.scan_id === scan.scan_id)
    if (mine === undefined) {
      // Otro drenaje se lo ha llevado. Que lo termine el.
      queue.release(claimed.map((record) => record.scan_id))
      wakeNow()
      return { kind: 'deferred' }
    }
    queue.release(
      claimed.filter((record) => record.scan_id !== scan.scan_id).map((record) => record.scan_id),
    )

    const result = await options.api.recordScan(toRequest(mine))

    if (result.outcome === 'ok') {
      options.onReachability?.(true)
      await queue.confirm([scan.scan_id])
      return result.data.action === 'debounced'
        ? { kind: 'debounced', response: result.data }
        : { kind: 'accepted', response: result.data }
    }

    if (result.outcome === 'rejected') {
      options.onReachability?.(true)
      // Decidido por el servidor: reintentarlo daria `422` para siempre.
      await queue.confirm([scan.scan_id])
      return { kind: 'rejected' }
    }

    options.onReachability?.(false)
    if (result.cause !== 'offline') {
      options.onDiagnostic?.('sync.transport_failed', { cause: result.cause })
    }
    await queue.retryLater([scan.scan_id], clock.now())
    scheduleNext()
    return { kind: 'deferred' }
  }

  function wakeNow(): void {
    cancelTimer()
    if (!running) return
    void drain({ ignoreSchedule: true })
  }

  return {
    start() {
      if (running) return
      running = true
      void queue.refresh().then(() => {
        void drain({ ignoreSchedule: true })
      })
    },

    stop() {
      running = false
      cancelTimer()
    },

    wakeNow,
    submit,
    drain,
  }
}
