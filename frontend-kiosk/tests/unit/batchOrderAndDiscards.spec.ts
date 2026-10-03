// RN-21 y RN-22 sobre el drenaje real (ADR-047, «Ningun fichaje sale de la cola
// sin desenlace del servidor»).
//
// Cada caso corre dos veces: sobre el respaldo en memoria y sobre Dexie/IndexedDB
// de verdad (`fake-indexeddb`). Si los dos no se comportan igual, el dia que el
// respaldo entre en juego el quiosco hara otra cosa sin que nadie se entere.

import 'fake-indexeddb/auto'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { QueuedPinScan, QueuedQrScan, QueuedScan } from '@/features/scan/application/ports'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import type { ScanQueue } from '@/features/offline/application/scanQueue'
import { createSyncRunner } from '@/features/offline/application/syncRunner'
import type { SyncDiagnostic } from '@/features/offline/application/syncRunner'
import {
  createDexieQueueStorage,
  openKioskDatabase,
} from '@/features/offline/infrastructure/dexieStorage'
import { createMemoryQueueStorage } from '@/features/offline/infrastructure/queueStorage'
import type { QueueStorage } from '@/features/offline/infrastructure/queueStorage'
import type { ApiClient, ApiResult } from '@/shared/api/client'
import type {
  DiscardedScanReceipt,
  DiscardedScanReportBatch,
  ScanBatchEntry,
  ScanBatchRequest,
  ScanBatchResponse,
  ScanOk,
} from '@/shared/api/types'
import type { Clock } from '@/shared/time/clock'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const SEALED_PIN = 'c2VhbGVkLXBpbi1lbnZlbG9wZS1wbGFjZWhvbGRlci1ub3QtdGhlLXJlYWwtcGlu'

const A = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b01'
const B = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b02'
const C = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b03'

function qr(scanId: string, occurredAt: string): QueuedQrScan {
  return {
    kind: 'qr',
    scan_id: scanId,
    qr_payload: PAYLOAD,
    occurred_at: occurredAt,
    intent: 'auto',
    device_id: 'kiosk-1',
  }
}

function pin(scanId: string, occurredAt: string): QueuedPinScan {
  return {
    kind: 'pin',
    scan_id: scanId,
    employee_code: 'E7QK2MXPR',
    pin_sealed: SEALED_PIN,
    occurred_at: occurredAt,
    intent: 'auto',
    device_id: 'kiosk-1',
  }
}

function accepted(scanId: string, occurredAt: string): ScanOk {
  return {
    scan_id: scanId,
    action: 'clock_in',
    employee_display_name: 'Lucia G.',
    work_date: occurredAt.slice(0, 10),
    occurred_at: occurredAt,
    recorded_at: '2026-08-14T09:30:00.000Z',
    worked_minutes: 0,
  }
}

const OK_200 = (scanId: string, occurredAt = '2026-08-14T08:00:00.000Z'): ApiResult<ScanOk> => ({
  outcome: 'ok',
  data: accepted(scanId, occurredAt),
})

const INVALID_400: ApiResult<ScanOk> = {
  outcome: 'failed',
  cause: 'invalid',
  httpStatus: 400,
  problemType: 'urn:kronoqr:problem:invalid-request',
}

function entry200(scanId: string, occurredAt: string): ScanBatchEntry {
  return { scan_id: scanId, status: 200, outcome: accepted(scanId, occurredAt) }
}

function entry503(scanId: string): ScanBatchEntry {
  return {
    scan_id: scanId,
    status: 503,
    outcome: {
      type: 'urn:kronoqr:problem:scan-not-processed',
      title: 'Escaneo no procesado',
      status: 503,
      detail: 'El escaneo no se ha podido procesar. Reintenta mas tarde.',
      scan_id: scanId,
    },
  }
}

function entryHeldBack(scanId: string): ScanBatchEntry {
  return {
    scan_id: scanId,
    status: 503,
    outcome: {
      type: 'urn:kronoqr:problem:scan-held-back',
      title: 'Escaneo aplazado',
      status: 503,
      detail:
        'El escaneo no se ha procesado porque uno anterior del lote sigue pendiente. Reintenta mas tarde.',
      scan_id: scanId,
    },
  }
}

/** Reloj que avanza solo cuando la prueba lo dice: el retroceso es medible. */
function steppingClock(startIso: string): Clock & { advance(ms: number): void } {
  let current = Date.parse(startIso)
  return {
    now: () => new Date(current),
    advance: (ms) => {
      current += ms
    },
  }
}

let databaseCounter = 0

interface Backend {
  readonly name: string
  /** Una fabrica NUEVA por prueba: en Dexie, una base distinta cada vez. */
  readonly factory: () => { open: () => QueueStorage; databaseName: string | null }
}

const BACKENDS: readonly Backend[] = [
  {
    name: 'respaldo en memoria',
    factory: () => {
      const store = createMemoryQueueStorage()
      return { open: () => store, databaseName: null }
    },
  },
  {
    name: 'Dexie sobre IndexedDB',
    factory: () => {
      databaseCounter += 1
      const databaseName = `kronoqr-b18-${databaseCounter}`
      return {
        open: () => createDexieQueueStorage(openKioskDatabase(databaseName)),
        databaseName,
      }
    },
  },
]

interface Bench {
  readonly api: ApiClient
  readonly queue: ScanQueue
  readonly runner: ReturnType<typeof createSyncRunner>
  readonly clock: ReturnType<typeof steppingClock>
  readonly batches: ScanBatchRequest[]
  readonly singles: string[]
  readonly pinCalls: string[]
  readonly reports: DiscardedScanReportBatch[]
  readonly diagnostics: Array<{
    readonly code: SyncDiagnostic
    readonly context: Record<string, string | number>
  }>
  readonly databaseName: string | null
}

interface BenchOptions {
  readonly onBatch?: (request: ScanBatchRequest) => ApiResult<ScanBatchResponse>
  readonly onSingle?: (scanId: string) => ApiResult<ScanOk> | Promise<ApiResult<ScanOk>>
  readonly onPin?: (scanId: string) => ApiResult<ScanOk>
  readonly onReport?: (body: DiscardedScanReportBatch) => ApiResult<DiscardedScanReceipt>
}

function bench(backend: Backend, options: BenchOptions = {}): Bench {
  const clock = steppingClock('2026-08-14T09:30:00.000Z')
  const batches: ScanBatchRequest[] = []
  const singles: string[] = []
  const pinCalls: string[] = []
  const reports: DiscardedScanReportBatch[] = []
  const diagnostics: Bench['diagnostics'] = []
  const { open, databaseName } = backend.factory()

  const api: ApiClient = {
    recordScan: vi.fn(async (request) => {
      singles.push(request.scan_id)
      return (await options.onSingle?.(request.scan_id)) ?? OK_200(request.scan_id)
    }),
    recordPinScan: vi.fn(async (request) => {
      pinCalls.push(request.scan_id)
      return options.onPin?.(request.scan_id) ?? OK_200(request.scan_id)
    }),
    syncScanBatch: vi.fn(async (request: ScanBatchRequest) => {
      batches.push(request)
      return (
        options.onBatch?.(request) ?? {
          outcome: 'ok' as const,
          data: { results: request.scans.map((s) => entry200(s.scan_id, s.occurred_at)) },
        }
      )
    }),
    reportDiscardedScans: vi.fn(async (body: DiscardedScanReportBatch) => {
      reports.push(body)
      return (
        options.onReport?.(body) ?? {
          outcome: 'ok' as const,
          data: { acknowledged: body.reports.map((report) => report.scan_id) },
        }
      )
    }),
    fetchRoster: vi.fn(),
    sendHeartbeat: vi.fn(),
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    fetchBranding: vi.fn(),
  }

  const queue = createScanQueue({ openStorage: open, clock, sleep: async () => undefined })
  const runner = createSyncRunner({
    api,
    queue,
    clock,
    isOnline: () => true,
    onDiagnostic: (code, context) => diagnostics.push({ code, context }),
    setTimer: () => 0,
    clearTimer: () => undefined,
  })
  return {
    api,
    queue,
    runner,
    clock,
    batches,
    singles,
    pinCalls,
    reports,
    diagnostics,
    databaseName,
  }
}

async function enqueueAll(queue: ScanQueue, scans: readonly QueuedScan[]): Promise<void> {
  for (const scan of scans) await queue.enqueue(scan)
}

describe.each(BACKENDS)(
  'RN-21 · RF-KI-04 · RF-AT-07 — un lote parcial no deja que nada lo adelante ($name)',
  (backend) => {
    it('lote con 503 en el 1.o: no se envia el tramo PIN posterior, y todo sigue en la cola', async () => {
      const bed = bench(backend, {
        onBatch: (request) => ({
          outcome: 'ok',
          data: { results: request.scans.map((s) => entry503(s.scan_id)) },
        }),
      })
      // Una entrada por tarjeta a las 08:00 y una salida por PIN a las 16:00.
      await enqueueAll(bed.queue, [
        qr(A, '2026-08-14T08:00:00.000Z'),
        pin(B, '2026-08-14T16:00:00.000Z'),
      ])

      await bed.runner.drain({ ignoreSchedule: true })

      expect(bed.batches).toHaveLength(1)
      // Lo que hace girar el turno del reves: la salida NO viaja antes que la entrada.
      expect(bed.pinCalls).toEqual([])
      expect(bed.queue.stats().size).toBe(2)
      expect(bed.queue.stats().oldestOccurredAt).toBe('2026-08-14T08:00:00.000Z')
    })

    it('207 de un servidor antiguo (503 y un 200 detras): confirma el 200 y se atasca ahi', async () => {
      const bed = bench(backend, {
        onBatch: (request) => ({
          outcome: 'ok',
          data: {
            results: request.scans.map((s, index) =>
              index === 0 ? entry503(s.scan_id) : entry200(s.scan_id, s.occurred_at),
            ),
          },
        }),
      })
      await enqueueAll(bed.queue, [
        qr(A, '2026-08-14T08:00:00.000Z'),
        qr(B, '2026-08-14T09:00:00.000Z'),
        pin(C, '2026-08-14T16:00:00.000Z'),
      ])

      await bed.runner.drain({ ignoreSchedule: true })

      // B esta registrado en el servidor: se confirma. A se conserva, y C ni se envia.
      const left = await bed.queue.claim(10, { ignoreSchedule: true })
      expect(left.map((record) => record.scan_id)).toEqual([A, C])
      expect(bed.pinCalls).toEqual([])
      expect(bed.diagnostics.map((item) => item.code)).toEqual(['sync.item_not_processed'])
    })

    it('207 con `ScanHeldBack`: nada se confirma, el drenaje se detiene y solo se reporta el que fallo', async () => {
      const bed = bench(backend, {
        onBatch: (request) => ({
          outcome: 'ok',
          data: {
            results: request.scans.map((s, index) =>
              index === 0 ? entry503(s.scan_id) : entryHeldBack(s.scan_id),
            ),
          },
        }),
      })
      await enqueueAll(bed.queue, [
        qr(A, '2026-08-14T08:00:00.000Z'),
        qr(B, '2026-08-14T09:00:00.000Z'),
        pin(C, '2026-08-14T16:00:00.000Z'),
      ])

      await bed.runner.drain({ ignoreSchedule: true })

      expect(bed.queue.stats().size).toBe(3)
      expect(bed.pinCalls).toEqual([])
      // Un solo diagnostico: los aplazados por el servidor no inundan el canal de errores.
      expect(bed.diagnostics.map((item) => item.code)).toEqual(['sync.item_not_processed'])
    })

    it('un elemento ausente de la respuesta detiene el drenaje igual que un 503', async () => {
      const bed = bench(backend, {
        onBatch: (request) => ({
          outcome: 'ok',
          data: {
            results: request.scans.slice(1).map((s) => entry200(s.scan_id, s.occurred_at)),
          },
        }),
      })
      await enqueueAll(bed.queue, [
        qr(A, '2026-08-14T08:00:00.000Z'),
        pin(B, '2026-08-14T16:00:00.000Z'),
      ])

      await bed.runner.drain({ ignoreSchedule: true })

      expect(bed.pinCalls).toEqual([])
      expect(bed.queue.stats().size).toBe(2)
    })

    it('un tramo de PIN que se atasca a medias tampoco deja pasar al tramo siguiente', async () => {
      const bed = bench(backend, {
        onPin: (scanId) =>
          scanId === B ? { outcome: 'failed', cause: 'server', httpStatus: 500 } : OK_200(scanId),
      })
      await enqueueAll(bed.queue, [
        pin(A, '2026-08-14T08:00:00.000Z'),
        pin(B, '2026-08-14T09:00:00.000Z'),
        qr(C, '2026-08-14T16:00:00.000Z'),
      ])

      await bed.runner.drain({ ignoreSchedule: true })

      // A salio, B fallo, y el QR de las 16:00 NO adelanta a B.
      expect(bed.pinCalls).toEqual([A, B])
      expect(bed.batches).toHaveLength(0)
      const left = await bed.queue.claim(10, { ignoreSchedule: true })
      expect(left.map((record) => record.scan_id)).toEqual([B, C])
    })

    it('`submit()` en vuelo + un drenaje: el drenaje NO adelanta a la fila que viaja', async () => {
      let release: (result: ApiResult<ScanOk>) => void = () => undefined
      const bed = bench(backend, {
        onSingle: () => new Promise<ApiResult<ScanOk>>((resolve) => (release = resolve)),
      })

      // A viaja solo, por el camino rapido, y su respuesta tarda.
      const submitted = bed.runner.submit(qr(A, '2026-08-14T08:00:00.000Z'))
      await vi.waitFor(() => expect(bed.api.recordScan).toHaveBeenCalledTimes(1))

      // Mientras, entra B (posterior) y un drenaje (el de «vuelve la red») intenta llevarsela.
      await bed.queue.enqueue(qr(B, '2026-08-14T16:00:00.000Z'))
      await bed.runner.drain({ ignoreSchedule: true })

      // B NO se ha enviado: A todavia no tiene desenlace y, si acabara en 503, B la habria adelantado.
      expect(bed.batches).toHaveLength(0)
      expect(bed.singles).toEqual([A])

      // A acaba en un fallo del servidor: se conserva, y B sigue detras de ella.
      release({ outcome: 'failed', cause: 'server', httpStatus: 500 })
      expect(await submitted).toEqual({ kind: 'deferred' })
      const left = await bed.queue.claim(10, { ignoreSchedule: true })
      expect(left.map((record) => record.scan_id)).toEqual([A, B])
    })

    it('`claim()` corta el prefijo en una fila arrendada: lo posterior no sale', async () => {
      const bed = bench(backend)
      await enqueueAll(bed.queue, [
        qr(A, '2026-08-14T08:00:00.000Z'),
        qr(B, '2026-08-14T09:00:00.000Z'),
      ])

      const first = await bed.queue.claim(1)
      expect(first.map((record) => record.scan_id)).toEqual([A])
      expect(bed.queue.isHeadInFlight()).toBe(true)

      // A esta arrendada: ni siquiera con `ignoreSchedule` sale B.
      expect(await bed.queue.claim(10, { ignoreSchedule: true })).toEqual([])

      bed.queue.release([A])
      expect(bed.queue.isHeadInFlight()).toBe(false)
      const both = await bed.queue.claim(10)
      expect(both.map((record) => record.scan_id)).toEqual([A, B])
    })

    it('`ignoreSchedule` sigue liberando TODA la cola, en orden', async () => {
      const bed = bench(backend)
      await enqueueAll(bed.queue, [
        qr(A, '2026-08-14T08:00:00.000Z'),
        qr(B, '2026-08-14T09:00:00.000Z'),
        qr(C, '2026-08-14T10:00:00.000Z'),
      ])
      await bed.queue.claim(3)
      await bed.queue.retryLater([A, B, C], bed.clock.now())

      // Con la espera vigente, ni A sale (y B y C, detras, tampoco).
      expect(await bed.queue.claim(10)).toEqual([])
      const all = await bed.queue.claim(10, { ignoreSchedule: true })
      expect(all.map((record) => record.scan_id)).toEqual([A, B, C])
    })
  },
)

describe.each(BACKENDS)(
  'RN-22 · RF-KI-04 · RF-AT-07 — los descartes se mueven y se avisan ($name)',
  (backend) => {
    it('un 400 deja la fila en `discarded` y fuera de la cola, y avisa SIN el PIN sellado', async () => {
      // Que el aviso NO salga todavia: se mira el estado intermedio.
      const noReport = bench(backend, {
        onPin: () => INVALID_400,
        onReport: () => ({ outcome: 'failed', cause: 'network' }),
      })
      await noReport.queue.enqueue(pin(A, '2026-08-14T08:00:00.000Z'))

      await noReport.runner.drain({ ignoreSchedule: true })

      expect(noReport.queue.stats().size).toBe(0)
      expect(noReport.queue.stats().unreportedDiscards).toBe(1)
      const [row] = await noReport.queue.discarded({ ignoreSchedule: true })
      expect(row).toMatchObject({
        scan_id: A,
        kind: 'pin',
        employee_code: 'E7QK2MXPR',
        occurred_at: '2026-08-14T08:00:00.000Z',
        http_status: 400,
        problem_type: 'urn:kronoqr:problem:invalid-request',
        discarded_at: '2026-08-14T09:30:00.000Z',
      })
      // Nunca el PIN, ni siquiera sellado: en la fila de la lista no esta.
      expect(JSON.stringify(row)).not.toContain('pin_sealed')
      expect(JSON.stringify(row)).not.toContain(SEALED_PIN)
      if (noReport.databaseName !== null) {
        const raw = await openKioskDatabase(noReport.databaseName).discarded.toArray()
        expect(JSON.stringify(raw)).not.toContain(SEALED_PIN)
        expect(JSON.stringify(raw)).not.toContain('pin_sealed')
      }
    })

    it('un descarte QR lleva el payload leido y se avisa en cuanto el drenaje termina', async () => {
      const bed = bench(backend, {
        onBatch: () => INVALID_400 as unknown as ApiResult<ScanBatchResponse>,
        onSingle: () => INVALID_400,
      })
      await bed.queue.enqueue(qr(A, '2026-08-14T08:00:00.000Z'))

      await bed.runner.drain({ ignoreSchedule: true })

      expect(bed.reports).toHaveLength(1)
      expect(bed.reports[0]?.reports[0]).toEqual({
        scan_id: A,
        kind: 'qr',
        qr_payload: PAYLOAD,
        occurred_at: '2026-08-14T08:00:00.000Z',
        http_status: 400,
        problem_type: 'urn:kronoqr:problem:invalid-request',
        discarded_at: '2026-08-14T09:30:00.000Z',
      })
      expect(bed.queue.stats().unreportedDiscards).toBe(0)
    })

    it('el aviso con acuse parcial: solo se borran los acusados, el resto vuelve a avisarse', async () => {
      const bed = bench(backend, {
        onPin: () => INVALID_400,
        // El servidor solo acusa el primero.
        onReport: (body) => ({
          outcome: 'ok',
          data: { acknowledged: body.reports.slice(0, 1).map((report) => report.scan_id) },
        }),
      })
      await enqueueAll(bed.queue, [
        pin(A, '2026-08-14T08:00:00.000Z'),
        pin(B, '2026-08-14T09:00:00.000Z'),
        pin(C, '2026-08-14T10:00:00.000Z'),
      ])

      await bed.runner.drain({ ignoreSchedule: true })

      const left = await bed.queue.discarded({ ignoreSchedule: true })
      expect(left.map((row) => row.scan_id)).toEqual([B, C])
      expect(bed.queue.stats().unreportedDiscards).toBe(2)
      expect(bed.diagnostics.map((item) => item.code)).toContain('sync.discard_report_failed')
      // Con retroceso: no vuelven a salir hasta que les toca...
      expect(await bed.queue.discarded()).toEqual([])
      // ... y cuando les toca, salen otra vez.
      bed.clock.advance(5 * 60_000)
      expect((await bed.queue.discarded()).map((row) => row.scan_id)).toEqual([B, C])
    })

    it('40 descartes: la cola queda vacia, la lista llega a 40, salen 4 avisos de 10 y los 40 se acusan', async () => {
      const ids = Array.from(
        { length: 40 },
        (_, index) => `0199f0c2-1f4a-7c3e-9b21-4d5e6f7a${String(index).padStart(4, '0')}`,
      )
      // Fase 1: el servidor no acepta ningun aviso, para ver la lista llena.
      let accepting = false
      const bed = bench(backend, {
        onBatch: () => INVALID_400 as unknown as ApiResult<ScanBatchResponse>,
        onSingle: () => INVALID_400,
        onReport: (body) =>
          accepting
            ? {
                outcome: 'ok',
                data: { acknowledged: body.reports.map((report) => report.scan_id) },
              }
            : { outcome: 'failed', cause: 'network' },
      })
      for (const [index, id] of ids.entries()) {
        await bed.queue.enqueue(qr(id, `2026-08-14T06:${String(index).padStart(2, '0')}:00.000Z`))
      }

      await bed.runner.drain({ ignoreSchedule: true })

      expect(bed.queue.stats().size).toBe(0)
      expect(await bed.queue.discarded({ ignoreSchedule: true })).toHaveLength(40)
      expect(bed.queue.stats().unreportedDiscards).toBe(40)

      // Fase 2: vuelve la red del servidor. 4 avisos de 10.
      accepting = true
      bed.reports.length = 0
      await bed.runner.drain({ ignoreSchedule: true })

      expect(bed.reports.map((body) => body.reports.length)).toEqual([10, 10, 10, 10])
      expect(bed.reports.flatMap((body) => body.reports.map((report) => report.scan_id))).toEqual(
        ids,
      )
      expect(bed.queue.stats().unreportedDiscards).toBe(0)
      expect(await bed.queue.discarded({ ignoreSchedule: true })).toEqual([])
    })

    it('un aviso que da 400 se reparte en avisos sueltos; el que no vale ni solo se queda y cuenta', async () => {
      const bed = bench(backend, {
        onPin: () => INVALID_400,
        onReport: (body) => {
          // El lote entero «no vale» por culpa de B; solo B no vale ni solo.
          const poisoned = body.reports.some((report) => report.scan_id === B)
          return poisoned
            ? { outcome: 'failed', cause: 'invalid', httpStatus: 400 }
            : {
                outcome: 'ok',
                data: { acknowledged: body.reports.map((report) => report.scan_id) },
              }
        },
      })
      await enqueueAll(bed.queue, [
        pin(A, '2026-08-14T08:00:00.000Z'),
        pin(B, '2026-08-14T09:00:00.000Z'),
        pin(C, '2026-08-14T10:00:00.000Z'),
      ])

      await bed.runner.drain({ ignoreSchedule: true })

      // Un lote de 3 (400) y luego tres sueltos, en orden.
      expect(bed.reports.map((body) => body.reports.map((report) => report.scan_id))).toEqual([
        [A, B, C],
        [A],
        [B],
        [C],
      ])
      const left = await bed.queue.discarded({ ignoreSchedule: true })
      expect(left.map((row) => row.scan_id)).toEqual([B])
      expect(bed.queue.stats().unreportedDiscards).toBe(1)
      expect(bed.diagnostics.map((item) => item.code)).toContain('sync.discard_report_failed')
    })

    it('un fallo de red al avisar no pierde el descarte: se reintenta con retroceso', async () => {
      let failing = true
      const bed = bench(backend, {
        onPin: () => INVALID_400,
        onReport: (body) =>
          failing
            ? { outcome: 'failed', cause: 'network' }
            : {
                outcome: 'ok',
                data: { acknowledged: body.reports.map((report) => report.scan_id) },
              },
      })
      await bed.queue.enqueue(pin(A, '2026-08-14T08:00:00.000Z'))

      await bed.runner.drain({ ignoreSchedule: true })
      expect(bed.queue.stats().unreportedDiscards).toBe(1)
      expect(bed.diagnostics.map((item) => item.code)).toContain('sync.discard_report_failed')

      failing = false
      // Aun no le toca (retroceso): un drenaje normal no lo manda.
      await bed.runner.drain()
      expect(bed.queue.stats().unreportedDiscards).toBe(1)
      // Pasado el retroceso, si.
      bed.clock.advance(60_000)
      await bed.runner.drain()
      expect(bed.queue.stats().unreportedDiscards).toBe(0)
    })

    it('el aviso de un 422 que no es `scan-rejected` conserva `problem_type` nulo', async () => {
      const bed = bench(backend, {
        onPin: () => ({ outcome: 'failed', cause: 'invalid', httpStatus: 422, problemType: null }),
      })
      await bed.queue.enqueue(pin(A, '2026-08-14T08:00:00.000Z'))

      await bed.runner.drain({ ignoreSchedule: true })

      expect(bed.reports[0]?.reports[0]).toMatchObject({ http_status: 422, problem_type: null })
    })

    it('sin red no se gasta ni una peticion de aviso', async () => {
      const bed = bench(backend, { onPin: () => INVALID_400 })
      const offline = createSyncRunner({
        api: bed.api,
        queue: bed.queue,
        clock: bed.clock,
        isOnline: () => false,
        setTimer: () => 0,
        clearTimer: () => undefined,
      })
      await bed.queue.discard(pin(A, '2026-08-14T08:00:00.000Z'), {
        http_status: 400,
        problem_type: null,
      })

      await offline.drain({ ignoreSchedule: true })

      expect(bed.reports).toHaveLength(0)
      expect(bed.queue.stats().unreportedDiscards).toBe(1)
    })

    it('F10: `clear()` (desvinculacion) vacia tambien la lista de descartados, que guarda el payload en claro', async () => {
      const bed = bench(backend, {
        onBatch: () => INVALID_400 as unknown as ApiResult<ScanBatchResponse>,
        onSingle: () => INVALID_400,
        onReport: () => ({ outcome: 'failed', cause: 'network' }),
      })
      await bed.queue.enqueue(qr(A, '2026-08-14T08:00:00.000Z'))
      await bed.queue.enqueue(qr(B, '2026-08-14T09:00:00.000Z'))
      await bed.runner.drain({ ignoreSchedule: true })
      expect(bed.queue.stats().unreportedDiscards).toBe(2)

      await bed.queue.clear()

      expect(bed.queue.stats().size).toBe(0)
      expect(bed.queue.stats().unreportedDiscards).toBe(0)
      expect(await bed.queue.discarded({ ignoreSchedule: true })).toEqual([])
      if (bed.databaseName !== null) {
        expect(await openKioskDatabase(bed.databaseName).discarded.count()).toBe(0)
      }
    })
  },
)

describe('RN-22 — mover es UNA transaccion (Dexie)', () => {
  let name: string

  beforeEach(() => {
    databaseCounter += 1
    name = `kronoqr-b18-tx-${databaseCounter}`
  })

  it('si el alta en `discarded` falla, el fichaje SIGUE en la cola: nunca queda en ninguna de las dos', async () => {
    const storage = createDexieQueueStorage(openKioskDatabase(name))
    await storage.add({
      kind: 'qr',
      scan_id: A,
      qr_payload: PAYLOAD,
      occurred_at: '2026-08-14T08:00:00.000Z',
      intent: 'auto',
      device_id: 'kiosk-1',
      attempts: 0,
      next_attempt_at: 0,
      enqueued_at: 1,
    })

    // Una entrada sin clave primaria no se puede escribir: la transaccion aborta.
    await expect(
      storage.discard(A, {
        kind: 'qr',
        qr_payload: PAYLOAD,
        occurred_at: '2026-08-14T08:00:00.000Z',
        http_status: 400,
        problem_type: null,
        discarded_at: '2026-08-14T09:30:00.000Z',
        attempts: 0,
        next_attempt_at: 0,
      } as never),
    ).rejects.toBeDefined()

    expect(await storage.count()).toBe(1)
    expect(await storage.countDiscarded()).toBe(0)
  })

  it('al terminar bien, el fichaje esta en `discarded` y no en `scans`', async () => {
    const storage = createDexieQueueStorage(openKioskDatabase(name))
    await storage.add({
      kind: 'qr',
      scan_id: A,
      qr_payload: PAYLOAD,
      occurred_at: '2026-08-14T08:00:00.000Z',
      intent: 'auto',
      device_id: 'kiosk-1',
      attempts: 0,
      next_attempt_at: 0,
      enqueued_at: 1,
    })

    await storage.discard(A, {
      kind: 'qr',
      scan_id: A,
      qr_payload: PAYLOAD,
      occurred_at: '2026-08-14T08:00:00.000Z',
      http_status: 400,
      problem_type: null,
      discarded_at: '2026-08-14T09:30:00.000Z',
      attempts: 0,
      next_attempt_at: 0,
    })

    expect(await storage.count()).toBe(0)
    expect(await storage.countDiscarded()).toBe(1)
  })
})

describe('RN-22 — la v2 de Dexie anade `discarded` sin tocar la cola cargada', () => {
  it('una base v1 con fichajes pendientes se abre con la v2 y conserva todas sus filas', async () => {
    databaseCounter += 1
    const dbName = `kronoqr-b18-v1-${databaseCounter}`

    // Tal como la dejo la version anterior de la PWA: solo `scans` y `roster`.
    const { default: Dexie } = await import('dexie')
    const legacy = new Dexie(dbName)
    legacy.version(1).stores({ scans: '&scan_id, occurred_at, next_attempt_at', roster: '&id' })
    await legacy.table('scans').put({
      kind: 'qr',
      scan_id: A,
      qr_payload: PAYLOAD,
      occurred_at: '2026-08-14T08:00:00.000Z',
      intent: 'auto',
      device_id: 'kiosk-1',
      attempts: 3,
      next_attempt_at: 0,
      enqueued_at: 1,
    })
    legacy.close()

    const storage = createDexieQueueStorage(openKioskDatabase(dbName))

    const rows = await storage.list(10)
    expect(rows.map((row) => row.scan_id)).toEqual([A])
    expect(rows[0]?.attempts).toBe(3)
    expect(await storage.countDiscarded()).toBe(0)
  })
})
