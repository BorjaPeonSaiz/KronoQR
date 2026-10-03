// El drenaje de la cola. Implementa el `alt` del diagrama del §6.
//
// DOS CAMINOS, Y LA DIFERENCIA NO ES COSMETICA
// --------------------------------------------
// «Hay conexion»  → `POST /api/v1/scan` (o `POST /api/v1/scan/pin`) con
//                   `Idempotency-Key: scan_id`. Se usa SOLO cuando el escaneo
//                   recien encolado es el UNICO pendiente. Su valor es la
//                   respuesta: trae el `action` y el acumulado real del dia, y
//                   con eso la pantalla pasa de «pendiente de validar» a
//                   «Entrada 06:02 · Hoy: 0 h 0 min».
// «Sin conexion»  → lotes ordenados por `occurred_at`. Para QR, `POST
//                   /api/v1/scan/batch` (respuesta 207 elemento a elemento). El
//                   PIN NO TIENE variante de lote (RF-AT-11, doc 02 §11): cada
//                   fichaje de esa via viaja en su propia llamada a `/scan/pin`,
//                   una detras de otra, en el mismo orden.
//
// POR QUE «SOLO SI ES EL UNICO PENDIENTE». Porque si hay algo encolado por
// delante, enviar el nuevo por su cuenta lo adelanta. Una entrada de las 08:00
// atrapada sin red y una salida de las 16:00 enviada al instante producen una
// salida sin turno abierto: la jornada queda del reves. En cuanto hay cola, TODO
// va por el lote ordenado. Es la garantia «Orden correcto» aplicada tambien al
// camino rapido.
//
// TRAMOS POR TIPO (tarea 1.12). Un drenaje puede tener QR y PIN entremezclados
// por `occurred_at` — una entrada por tarjeta y una salida por PIN, o al reves.
// `splitRuns()` los agrupa en tramos maximos de la misma via SIN reordenarlos,
// y el drenaje procesa un tramo entero (con su llamada de lote o su secuencia
// de llamadas individuales) antes de tocar el siguiente.
//
// UN TRAMO SOLO «PROGRESA» SI TODOS SUS ELEMENTOS TIENEN DESENLACE (RN-21,
// ADR-047). Si alguno se conserva para reintento —`503`, `ScanHeldBack`, ausente
// de la respuesta— los decididos se confirman, el resto se aplaza y TODO lo que
// vendria despues, tramo actual incluido, se aplaza con el mismo retroceso:
// dejar que un PIN mas tardio adelante a un QR varado seria romper exactamente
// la garantia que esto existe para mantener. Vale tambien contra un servidor
// anterior que procesara elementos posteriores al fallido: se confirman (estan
// registrados) y aun asi el drenaje se detiene ahi.
//
// QUE SACA UN ELEMENTO DE LA COLA DE ENVIO. Un desenlace del servidor para ESE
// `scan_id`, y NADA SE BORRA SIN EL (ADR-008, ADR-047):
// - `200`: registrado (o anti-rebote, que es un desenlace aceptado, ADR-031).
//   Se BORRA.
// - `422` estandar (`scan-rejected`): el servidor decidio rechazarlo. Reintentar
//   daria `422` para siempre. Se BORRA.
// - `400` (o un `422` que no es `scan-rejected`) con cuerpo JSON: la peticion no
//   vale y nunca valdra (RN-22). NO se borra: se MUEVE a la lista de
//   descartados, en la misma transaccion, y se AVISA al servidor
//   (`POST /scan/discarded`) para que una persona lo revise. Es terminal para el
//   orden: ya no bloquea la cola. Solo se olvida cuando el aviso es acusado.
//   Un lote `400` se reenvia de uno en uno para aislar al envenenado. Un `400`
//   sin cuerpo JSON (proxy) se reintenta.
// - `503` (`ScanNotProcessed`, `ScanHeldBack`): NO se decidio nada. Se conserva.
// - Fallo de transporte, 401, 403, 429, 5xx: no se toca nada. Se reintenta.
//
// BATERIA. Si el navegador dice que no hay red, no se hace la peticion: se
// espera al evento `online`. `navigator.onLine === false` es la unica senal
// fiable que da (en `true` miente). Con el techo de 5 minutos del retroceso,
// una tablet incomunicada hace doce intentos a la hora, no miles.

import type { QueuedScan, ScanSubmissionResult } from '@/features/scan/application/ports'
import type { ApiClient, ApiResult } from '@/shared/api/client'
import type {
  DiscardedScanReport,
  PinScanRequest,
  ScanBatchEntry,
  ScanOk,
  ScanRequest,
} from '@/shared/api/types'
import { uuidV7 } from '@/shared/ids/uuidV7'
import type { Clock } from '@/shared/time/clock'
import { systemClock } from '@/shared/time/clock'
import { MAX_BATCH_SIZE, splitRuns } from '../domain/queueOrder'
import type {
  DiscardedScanRecord,
  QueuedPinScanRecord,
  QueuedQrScanRecord,
} from '../infrastructure/queueStorage'
import { isPinScanRecord } from '../infrastructure/queueStorage'
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

/** Avisos por peticion de `POST /scan/discarded` (contrato: maximo 10, RS-03). */
export const DISCARD_REPORT_CHUNK_SIZE = 10

/** Peticiones de aviso por drenaje: el mismo cinturon que `MAX_BATCHES_PER_DRAIN`. */
export const MAX_DISCARD_CHUNKS_PER_DRAIN = 10

export type SyncDiagnostic =
  | 'sync.transport_failed'
  | 'sync.unauthorized'
  | 'sync.throttled'
  | 'sync.malformed_response'
  /** SOLO «conservado para reintento» (`503`): no se decidio nada. */
  | 'sync.item_not_processed'
  | 'sync.confirm_not_persisted'
  /** Sacado de la cola a la lista de descartados, con aviso pendiente (RN-22). */
  | 'sync.item_discarded'
  /** El aviso de un descartado no salio o no fue acusado. */
  | 'sync.discard_report_failed'

export interface SyncRunnerOptions {
  readonly api: ApiClient
  readonly queue: ScanQueue
  readonly clock?: Clock
  readonly newBatchKey?: () => string
  /** Estado real de la red segun el ultimo intento. Alimenta el indicador. */
  readonly onReachability?: (reachable: boolean) => void
  readonly onSyncing?: (syncing: boolean) => void
  readonly onDiagnostic?: (code: SyncDiagnostic, context: Record<string, string | number>) => void
  /**
   * Alimenta `deviceRevocation.ts` (RF-PD-06, tarea 5.6): `true` en cada
   * respuesta `401`/`403`, `false` en cada envio que SI llega a decidirse
   * (`ok` o `rejected`, ambos autenticados). Nunca se llama por un fallo de
   * red, tiempo agotado o `429`: son ambiguos y no deben ni sumar ni resetear
   * el conteo de rechazos consecutivos.
   */
  readonly onAuthOutcome?: (unauthorized: boolean) => void
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

function toRequest(record: QueuedQrScanRecord): ScanRequest {
  return {
    scan_id: record.scan_id,
    occurred_at: record.occurred_at,
    qr_payload: record.qr_payload,
    intent: record.intent,
  }
}

function toPinRequest(record: QueuedPinScanRecord): PinScanRequest {
  return {
    scan_id: record.scan_id,
    occurred_at: record.occurred_at,
    employee_code: record.employee_code,
    pin_sealed: record.pin_sealed,
    intent: record.intent,
  }
}

/**
 * El aviso de un descartado, campo a campo: sin `pin_sealed` ni nada que no
 * este en el contrato. El `qr_payload` o el `employee_code` solo viajan a este
 * endpoint, que los usa para atribuir y no los guarda.
 */
function toDiscardReport(entry: DiscardedScanRecord): DiscardedScanReport {
  const common = {
    scan_id: entry.scan_id,
    occurred_at: entry.occurred_at,
    http_status: entry.http_status,
    problem_type: entry.problem_type,
    discarded_at: entry.discarded_at,
  }
  return entry.kind === 'qr'
    ? { ...common, kind: 'qr', qr_payload: entry.qr_payload }
    : { ...common, kind: 'pin', employee_code: entry.employee_code }
}

/** Lo que se sabe de un `400`/`422` no estandar para decidir el descarte. */
interface InvalidFailure {
  readonly httpStatus?: number
  readonly problemType?: string | null
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
  /** El despertador pedido mientras se drenaba puede traer «acaba de volver la red». */
  let rerunIgnoreSchedule = false
  /** El envio de avisos de descartados en curso (uno solo a la vez). */
  let reportingDiscards = false
  let reportDiscardsAgain = false
  let reportDiscardsIgnoreSchedule = false

  function cancelTimer(): void {
    if (timer === null) return
    clearTimer(timer)
    timer = null
  }

  function scheduleNext(): void {
    cancelTimer()
    if (!running) return

    const stats = queue.stats()
    // Con la cola en memoria («desconocido») se sigue sondeando aunque este
    // vacia: cada drenaje intenta volver al disco (ADR-047).
    if (stats.inStore === 0 && stats.unreportedDiscards === 0 && stats.storage === 'durable') {
      return
    }

    const nowMs = clock.now().getTime()
    // G2: sin red, `drain()` suelta las filas sin tocarlas y su espera sigue
    // siendo 0: programar con esa espera giraba en vacio (miles de lecturas de
    // IndexedDB por segundo). El evento `online` despierta de verdad
    // (`wakeNow`); esto es solo la red de seguridad de cuando ese evento no llega.
    // Igual si la cabeza esta en vuelo (RN-21: `claim()` no puede tomar nada
    // hasta que termine, y quien la tiene vuelve a programar al acabar) o si
    // no hay nada con fecha que esperar.
    const next = stats.nextAttemptAt
    const delay =
      !isOnline() || next === null || queue.isHeadInFlight() || queue.isClaimBlocked()
        ? IDLE_POLL_MS
        : Math.max(0, Math.min(next - nowMs, IDLE_POLL_MS))
    timer = setTimer(() => {
      timer = null
      void drain()
    }, delay)
  }

  /** Aplica el resultado de un elemento del 207 sobre la cola. */
  function classify(entry: ScanBatchEntry): 'confirm' | 'retry' {
    if (entry.status === 200 || entry.status === 422) return 'confirm'
    // `ScanHeldBack` es el servidor aplazando lo que venia DETRAS de un `503`
    // (RN-21): no es un fallo propio, y reportarlo por cada elemento del lote
    // inundaria el canal de errores con 49 copias de la misma causa.
    const heldBack =
      'type' in entry.outcome && entry.outcome.type === 'urn:kronoqr:problem:scan-held-back'
    if (!heldBack) {
      options.onDiagnostic?.('sync.item_not_processed', {
        http_status: entry.status,
        message: 'item_not_processed',
      })
    }
    return 'retry'
  }

  /**
   * RN-22. Mueve el fichaje a la lista de descartados (UNA transaccion) y deja
   * constancia tecnica sin `scan_id` ni payload (regla dura 21). `false` si el
   * movimiento no llego a escribirse: el fichaje sigue en la cola y quien llama
   * lo aplaza. El aviso al servidor lo manda `flushDiscards`.
   */
  async function discardScan(
    scan: QueuedScan | QueuedQrScanRecord | QueuedPinScanRecord,
    failure: InvalidFailure,
  ): Promise<boolean> {
    const httpStatus = failure.httpStatus ?? 400
    const problemType = failure.problemType ?? null
    const moved = await queue.discard(scan, { http_status: httpStatus, problem_type: problemType })
    if (!moved) {
      options.onDiagnostic?.('sync.confirm_not_persisted', {
        items: 1,
        message: 'confirm_not_persisted',
      })
      return false
    }
    options.onDiagnostic?.('sync.item_discarded', {
      http_status: httpStatus,
      kind: scan.kind,
      problem_type: problemType ?? 'none',
      message: 'item_discarded',
    })
    return true
  }

  /**
   * `true` solo si TODOS los elementos tienen desenlace terminal (RN-21): `200`,
   * `422` estandar, o descartado con aviso pendiente. Cualquier otra cosa
   * (`503`, `ScanHeldBack`, ausente) detiene el drenaje en este tramo.
   */
  async function sendBatch(records: readonly QueuedQrScanRecord[]): Promise<boolean> {
    const result = await options.api.syncScanBatch({ scans: records.map(toRequest) }, newBatchKey())

    if (result.outcome === 'failed' && result.cause === 'invalid') {
      // El servidor ha rechazado el LOTE entero por mal formado, y no dice cual
      // de los elementos lo envenena. Reintentar el lote daria lo mismo para
      // siempre y, al respetar el orden, pararia toda la cola (PIN-08). Se
      // reenvian de uno en uno, en orden y por el endpoint individual: el
      // envenenado se descarta y los demas se registran como siempre.
      return sendOneByOne(records, (record) => options.api.recordScan(toRequest(record)))
    }

    if (result.outcome !== 'ok') {
      options.onReachability?.(false)

      if (result.outcome === 'failed') {
        if (result.cause === 'unauthorized') {
          // El token del dispositivo esta caducado o revocado. La cola NO se
          // toca: cuando se vuelva a emparejar, los fichajes siguen ahi. Un
          // quiosco desautorizado no es motivo para perder una jornada.
          options.onDiagnostic?.('sync.unauthorized', {
            http_status: result.httpStatus ?? 0,
            message: 'unauthorized',
          })
          options.onAuthOutcome?.(true)
        } else if (result.cause === 'throttled') {
          options.onDiagnostic?.('sync.throttled', {
            http_status: result.httpStatus ?? 0,
            message: 'throttled',
          })
        } else if (result.cause !== 'offline') {
          options.onDiagnostic?.('sync.transport_failed', {
            cause: result.cause,
            message: result.cause,
          })
        }
      }

      await queue.retryLater(
        records.map((record) => record.scan_id),
        clock.now(),
      )
      return false
    }

    options.onReachability?.(true)
    options.onAuthOutcome?.(false)

    const byId = new Map(result.data.results.map((entry) => [entry.scan_id, entry]))
    const confirmed: string[] = []
    const retry: string[] = []

    for (const record of records) {
      const entry = byId.get(record.scan_id)
      if (entry === undefined) {
        // El servidor no ha dicho nada de este elemento. Se conserva: el
        // silencio no es una confirmacion.
        options.onDiagnostic?.('sync.malformed_response', {
          missing: 1,
          message: 'malformed_response',
        })
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
      options.onDiagnostic?.('sync.confirm_not_persisted', {
        items: confirmed.length,
        message: 'confirm_not_persisted',
      })
      await queue.retryLater(confirmed, clock.now())
      return false
    }

    // El tramo progresa solo si no queda NADA para reintento. Con un `503` (o un
    // elemento ausente) los decididos ya estan confirmados, el resto aplazado, y
    // el drenaje se detiene aqui: nada posterior se envia antes de que ese
    // elemento tenga desenlace.
    return retry.length === 0
  }

  /**
   * El tramo de PIN: sin variante de lote, cada elemento es su propia llamada a
   * `/scan/pin`, ESPERADA antes de mandar la siguiente. Es lo que impide que un
   * PIN mas tardio (dentro del mismo tramo) adelante a uno mas temprano que
   * todavia no ha tenido respuesta.
   *
   * Tambien es el plan B de un lote QR que el servidor rechaza entero por mal
   * formado (PIN-08): de uno en uno se ve cual es el envenenado.
   */
  async function sendOneByOne<TRecord extends QueuedQrScanRecord | QueuedPinScanRecord>(
    records: readonly TRecord[],
    send: (record: TRecord) => Promise<ApiResult<ScanOk>>,
  ): Promise<boolean> {
    for (let index = 0; index < records.length; index += 1) {
      const record = records[index]
      if (record === undefined) break

      const result = await send(record)

      if (result.outcome === 'failed' && result.cause === 'invalid') {
        // RN-22. El servidor ha decidido que ESTA peticion no vale (400, o un
        // 422 que no es el rechazo estandar): reenviarla daria lo mismo para
        // siempre y, como el orden se respeta, pararia todo lo que viene detras.
        // NO se borra: se mueve a la lista de descartados y se avisa (ADR-047).
        // El empleado ya fue confirmado en pantalla (regla 19).
        options.onReachability?.(true)
        options.onAuthOutcome?.(false)
        const moved = await discardScan(record, result)
        if (!moved) {
          await queue.retryLater(
            records.slice(index).map((item) => item.scan_id),
            clock.now(),
          )
          return false
        }
        continue
      }

      if (result.outcome === 'failed') {
        options.onReachability?.(false)
        if (result.cause === 'unauthorized') {
          options.onDiagnostic?.('sync.unauthorized', {
            http_status: result.httpStatus ?? 0,
            message: 'unauthorized',
          })
          options.onAuthOutcome?.(true)
        } else if (result.cause === 'throttled') {
          options.onDiagnostic?.('sync.throttled', {
            http_status: result.httpStatus ?? 0,
            message: 'throttled',
          })
        } else if (result.cause !== 'offline') {
          options.onDiagnostic?.('sync.transport_failed', {
            cause: result.cause,
            message: result.cause,
          })
        }
        // Este y los que quedan de ESTE tramo: ninguno se manda antes de saber
        // que paso con el que fallo, o el orden se rompe igual que si se
        // hubiera enviado de mas.
        await queue.retryLater(
          records.slice(index).map((item) => item.scan_id),
          clock.now(),
        )
        return false
      }

      options.onReachability?.(true)
      options.onAuthOutcome?.(false)
      // `ok` y `rejected` son ambos un desenlace: el servidor ya ha decidido
      // (regla dura 17, RS-03. La causa concreta no sale de `scan_events`).
      const removed = await queue.confirm([record.scan_id])
      if (!removed) {
        options.onDiagnostic?.('sync.confirm_not_persisted', {
          items: 1,
          message: 'confirm_not_persisted',
        })
        await queue.retryLater(
          records.slice(index).map((item) => item.scan_id),
          clock.now(),
        )
        return false
      }
    }

    // Todos los elementos del tramo tienen desenlace (RN-21).
    return true
  }

  /**
   * RN-22. Manda al servidor los avisos de los descartados, en trozos de 10
   * (RS-03), y SOLO borra de la lista los `scan_id` que vuelven en
   * `acknowledged`. Devuelve `false` si hay que dejar de intentarlo ahora (fallo
   * de transporte): lo que queda ya esta aplazado con el retroceso.
   */
  async function sendDiscardChunk(chunk: readonly DiscardedScanRecord[]): Promise<boolean> {
    const result = await options.api.reportDiscardedScans({ reports: chunk.map(toDiscardReport) })

    if (result.outcome === 'ok') {
      options.onReachability?.(true)
      options.onAuthOutcome?.(false)
      const acknowledged = new Set<string>(result.data.acknowledged)
      const done = chunk.filter((entry) => acknowledged.has(entry.scan_id))
      const pending = chunk.filter((entry) => !acknowledged.has(entry.scan_id))
      await queue.acknowledgeDiscarded(done.map((entry) => entry.scan_id))
      if (pending.length > 0) {
        // Acuse parcial: lo no acusado se queda en la lista y vuelve a avisarse.
        options.onDiagnostic?.('sync.discard_report_failed', {
          cause: 'partial_acknowledgement',
          items: pending.length,
          message: 'discard_report_not_acknowledged',
        })
        await queue.retryDiscardedLater(
          pending.map((entry) => entry.scan_id),
          clock.now(),
        )
      }
      return true
    }

    if (result.outcome === 'failed' && result.cause === 'invalid') {
      options.onReachability?.(true)
      options.onAuthOutcome?.(false)
      if (chunk.length > 1) {
        // El lote no vale y no dice cual lo envenena: avisos sueltos, en orden.
        for (const entry of chunk) {
          if (!(await sendDiscardChunk([entry]))) return false
        }
        return true
      }
      // Uno que no vale ni solo: se queda en la lista (cuenta en el latido,
      // `unreported_discards`) con retroceso, y se dice por que.
      options.onDiagnostic?.('sync.discard_report_failed', {
        cause: 'invalid',
        http_status: result.httpStatus ?? 400,
        items: 1,
        message: 'discard_report_invalid',
      })
      await queue.retryDiscardedLater(
        chunk.map((entry) => entry.scan_id),
        clock.now(),
      )
      return true
    }

    if (result.outcome === 'failed') {
      options.onReachability?.(false)
      if (result.cause === 'unauthorized') options.onAuthOutcome?.(true)
      if (result.cause !== 'offline') {
        options.onDiagnostic?.('sync.discard_report_failed', {
          cause: result.cause,
          http_status: result.httpStatus ?? 0,
          items: chunk.length,
          message: 'discard_report_failed',
        })
      }
    }
    await queue.retryDiscardedLater(
      chunk.map((entry) => entry.scan_id),
      clock.now(),
    )
    return false
  }

  /** Una pasada de avisos: los que ya les toca (o todos, con `ignoreSchedule`). */
  async function reportDiscardsOnce(ignoreSchedule: boolean): Promise<void> {
    // Sin red no se gasta ni una peticion; sin avisos pendientes, ni una lectura.
    if (!isOnline() || queue.stats().unreportedDiscards === 0) return

    const due = await queue.discarded({ ignoreSchedule })
    for (let chunkIndex = 0; chunkIndex < MAX_DISCARD_CHUNKS_PER_DRAIN; chunkIndex += 1) {
      const start = chunkIndex * DISCARD_REPORT_CHUNK_SIZE
      if (start >= due.length) return
      const chunk = due.slice(start, start + DISCARD_REPORT_CHUNK_SIZE)
      if (!(await sendDiscardChunk(chunk))) return
    }
  }

  /** Un solo envio de avisos a la vez; una peticion que llega mientras tanto se atiende al terminar. */
  async function flushDiscards(
    flushOptions: { readonly ignoreSchedule?: boolean } = {},
  ): Promise<void> {
    if (reportingDiscards) {
      reportDiscardsAgain = true
      reportDiscardsIgnoreSchedule ||= flushOptions.ignoreSchedule === true
      return
    }
    reportingDiscards = true
    try {
      let ignoreSchedule = flushOptions.ignoreSchedule === true
      do {
        reportDiscardsAgain = false
        await reportDiscardsOnce(ignoreSchedule)
        ignoreSchedule = reportDiscardsIgnoreSchedule
        reportDiscardsIgnoreSchedule = false
      } while (reportDiscardsAgain)
    } finally {
      reportingDiscards = false
    }
  }

  /** Los fichajes pendientes, en orden y por tramos. Ver la cabecera. */
  async function drainScans(initialIgnoreSchedule: boolean): Promise<void> {
    let ignoreSchedule = initialIgnoreSchedule

    for (let pass = 0; pass < MAX_BATCHES_PER_DRAIN; pass += 1) {
      const claimed = await queue.claim(MAX_BATCH_SIZE, { ignoreSchedule })
      if (claimed.length === 0) return

      if (!isOnline()) {
        // Ni se intenta: se ahorra la radio y el evento `online` despertara.
        queue.release(claimed.map((record) => record.scan_id))
        options.onReachability?.(false)
        return
      }

      // Tramos de la misma via, EN EL ORDEN en que `claim()` ya los entrego
      // (por `occurred_at`, mezclando QR y PIN si hace falta). Ver cabecera.
      const runs = splitRuns(claimed)
      let stalledAt = runs.length

      for (let index = 0; index < runs.length; index += 1) {
        const run = runs[index]
        const first = run?.[0]
        if (run === undefined || first === undefined) continue

        // `run` ya viene con como maximo `MAX_BATCH_SIZE` elementos (es un
        // subconjunto de `claimed`) y ya ordenado: una unica llamada de lote
        // basta para la parte QR, sin volver a trocear.
        const complete = isPinScanRecord(first)
          ? await sendOneByOne(run as QueuedPinScanRecord[], (record) =>
              options.api.recordPinScan(toPinRequest(record)),
            )
          : await sendBatch(run as QueuedQrScanRecord[])

        // RN-21: solo se sigue si TODO el tramo tiene desenlace.
        if (!complete) {
          stalledAt = index
          break
        }
      }

      if (stalledAt < runs.length) {
        // El tramo que se atasco ya ha aplazado lo suyo. Lo que viene
        // DESPUES en este drenaje todavia no se ha tocado: si no se aplaza
        // tambien, un PIN o un QR mas tardio se reclamaria en la siguiente
        // pasada y adelantaria al que sigue varado.
        const untouched = runs.slice(stalledAt + 1).flat()
        if (untouched.length > 0) {
          await queue.retryLater(
            untouched.map((record) => record.scan_id),
            clock.now(),
          )
        }
        return
      }

      // Los siguientes lotes ya no se saltan la espera: solo el primero
      // hereda el «acabo de volver la red».
      ignoreSchedule = false
    }
  }

  async function drain(drainOptions: { readonly ignoreSchedule?: boolean } = {}): Promise<void> {
    if (draining) {
      rerun = true
      // G1: el relanzamiento hereda `ignoreSchedule`. Sin esto, el `online`
      // que llega mientras otro drenaje esta en curso se degrada a un drenaje
      // normal y la fila atascada espera su retroceso con la red ya de vuelta.
      rerunIgnoreSchedule ||= drainOptions.ignoreSchedule === true
      return
    }
    draining = true
    options.onSyncing?.(true)

    try {
      // ADR-047: con la cola en memoria, cada drenaje (como mucho cada 60 s)
      // intenta volver al disco y, si abre, migra lo de memoria.
      await queue.tryReopen()
      await drainScans(drainOptions.ignoreSchedule === true)
      // Los avisos de descartados van DESPUES de los fichajes y no dependen de
      // ellos: una cola atascada por un `503` no debe callar un descarte.
      await flushDiscards(drainOptions)
    } finally {
      draining = false
      options.onSyncing?.(false)
      scheduleNext()
      if (rerun) {
        const inherited = rerunIgnoreSchedule
        rerun = false
        rerunIgnoreSchedule = false
        void drain({ ignoreSchedule: inherited })
      }
    }
  }

  async function submit(scan: QueuedScan): Promise<ScanSubmissionResult> {
    const outcome = await queue.enqueue(scan)
    if (!outcome.stored) {
      // Ni IndexedDB ni memoria. No se puede prometer reintento, asi que se
      // intenta AHORA aunque sea lo unico que quede.
      const rescue =
        scan.kind === 'qr'
          ? await options.api.recordScan({
              scan_id: scan.scan_id,
              occurred_at: scan.occurred_at,
              qr_payload: scan.qr_payload,
              intent: scan.intent,
            })
          : await options.api.recordPinScan({
              scan_id: scan.scan_id,
              occurred_at: scan.occurred_at,
              employee_code: scan.employee_code,
              pin_sealed: scan.pin_sealed,
              intent: scan.intent,
            })
      if (rescue.outcome === 'ok') {
        options.onReachability?.(true)
        options.onAuthOutcome?.(false)
        return rescue.data.action === 'debounced'
          ? { kind: 'debounced', response: rescue.data }
          : { kind: 'accepted', response: rescue.data }
      }
      if (rescue.outcome === 'rejected') {
        options.onAuthOutcome?.(false)
        return { kind: 'rejected' }
      }
      if (rescue.outcome === 'failed' && rescue.cause === 'invalid') {
        // RN-22: decidido por el servidor; no hay nada que reintentar. Se avisa
        // igual (si hay donde guardar el aviso): es el unico rastro que queda.
        options.onAuthOutcome?.(false)
        const moved = await discardScan(scan, rescue)
        if (moved) void flushDiscards({ ignoreSchedule: true })
        return { kind: 'rejected' }
      }
      options.onReachability?.(false)
      if (rescue.cause === 'unauthorized') options.onAuthOutcome?.(true)
      return { kind: 'deferred' }
    }

    const stats = queue.stats()
    // Con la cola en memoria el disco no se ve: no se puede saber si hay algo
    // por delante, y el orden manda. Se trata como «hay cola».
    const alone = stats.size !== null && stats.size <= 1

    if (!alone || !isOnline()) {
      // Hay cola por delante (o no hay red): el orden manda. Se drena por lote.
      drainRespectingSchedule()
      return { kind: 'deferred' }
    }

    const claimed = await queue.claim(1, { ignoreSchedule: true })
    const mine = claimed.find((record) => record.scan_id === scan.scan_id)
    if (mine === undefined) {
      // Otro drenaje se lo ha llevado. Que lo termine el.
      queue.release(claimed.map((record) => record.scan_id))
      drainRespectingSchedule()
      return { kind: 'deferred' }
    }
    queue.release(
      claimed.filter((record) => record.scan_id !== scan.scan_id).map((record) => record.scan_id),
    )

    const result = isPinScanRecord(mine)
      ? await options.api.recordPinScan(toPinRequest(mine))
      : await options.api.recordScan(toRequest(mine))

    if (result.outcome === 'ok') {
      options.onReachability?.(true)
      options.onAuthOutcome?.(false)
      await queue.confirm([scan.scan_id])
      // Lo que se encolo mientras viajaba esta fila esperaba a que terminara
      // (RN-21): ahora si puede salir.
      scheduleNext()
      return result.data.action === 'debounced'
        ? { kind: 'debounced', response: result.data }
        : { kind: 'accepted', response: result.data }
    }

    if (result.outcome === 'rejected') {
      options.onReachability?.(true)
      options.onAuthOutcome?.(false)
      // Decidido por el servidor: reintentarlo daria `422` para siempre.
      await queue.confirm([scan.scan_id])
      scheduleNext()
      return { kind: 'rejected' }
    }

    if (result.outcome === 'failed' && result.cause === 'invalid') {
      // RN-22: 400, o 422 no estandar. Terminal para el orden: se MUEVE a la
      // lista de descartados (no se borra) y se avisa al servidor.
      options.onReachability?.(true)
      options.onAuthOutcome?.(false)
      const moved = await discardScan(mine, result)
      if (!moved) await queue.retryLater([scan.scan_id], clock.now())
      scheduleNext()
      if (moved) void flushDiscards({ ignoreSchedule: true })
      return { kind: 'rejected' }
    }

    options.onReachability?.(false)
    if (result.cause === 'unauthorized') options.onAuthOutcome?.(true)
    if (result.cause !== 'offline') {
      options.onDiagnostic?.('sync.transport_failed', {
        cause: result.cause,
        message: result.cause,
      })
    }
    await queue.retryLater([scan.scan_id], clock.now())
    scheduleNext()
    return { kind: 'deferred' }
  }

  /**
   * Un escaneo nuevo drena RESPETANDO el retroceso: solo `online`, `visibilitychange`
   * y el arranque (`wakeNow`) lo saltan. Con un servidor en 503 continuado, cada
   * fichaje reenviando el lote entero agotaria la bateria; si la cabeza esta
   * aplazada, el nuevo espera detras (RN-21).
   */
  function drainRespectingSchedule(): void {
    if (!running) return
    void drain()
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
