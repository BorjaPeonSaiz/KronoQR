// R2-KI-06 (a) y (b): el cinturon del drenaje y `stop()` con un drenaje en
// vuelo (RF-KI-03, RF-KI-04).
//
// El peor escenario del plan: tablet al 8 % de bateria, wifi intermitente y la
// cola llena. `MAX_BATCHES_PER_DRAIN` es lo que impide que un fallo que no deja
// encoger la cola convierta un drenaje en un bucle de peticiones; `stop()` es lo
// que se llama al desmontar o desvincular, y no puede dejar nada programado.

import { describe, expect, it, vi } from 'vitest'
import type { QueuedScan } from '@/features/scan/application/ports'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import { createSyncRunner, MAX_BATCHES_PER_DRAIN } from '@/features/offline/application/syncRunner'
import type { QueueStorage } from '@/features/offline/infrastructure/queueStorage'
import { createMemoryQueueStorage } from '@/features/offline/infrastructure/queueStorage'
import type { ApiClient, ApiResult } from '@/shared/api/client'
import type { ScanBatchRequest, ScanBatchResponse } from '@/shared/api/types'
import { fixedClock } from '@/shared/time/clock'

const DRAIN_LIMITS_PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const DRAIN_LIMITS_CLOCK = fixedClock(new Date('2026-08-14T09:30:00.000Z'))
const DRAIN_LIMITS_FIRST_MS = Date.parse('2026-08-14T00:00:00.000Z')

/** `count` fichajes, uno por segundo desde las 00:00, con `scan_id` distintos y ordenables. */
function scans(count: number): QueuedScan[] {
  return Array.from({ length: count }, (_, index) => ({
    kind: 'qr' as const,
    scan_id: `0199f0c2-1f4a-7c3e-9b21-${String(index).padStart(12, '0')}`,
    qr_payload: DRAIN_LIMITS_PAYLOAD,
    occurred_at: new Date(DRAIN_LIMITS_FIRST_MS + index * 1_000).toISOString(),
    intent: 'auto' as const,
    device_id: 'kiosk-1',
  }))
}

function allAccepted(request: ScanBatchRequest): ApiResult<ScanBatchResponse> {
  return {
    outcome: 'ok',
    data: {
      results: request.scans.map((item) => ({
        scan_id: item.scan_id,
        status: 200 as const,
        outcome: {
          scan_id: item.scan_id,
          action: 'clock_in' as const,
          employee_display_name: 'Lucia G.',
          work_date: item.occurred_at.slice(0, 10),
          occurred_at: item.occurred_at,
          recorded_at: '2026-08-14T09:30:00.000Z',
          worked_minutes: 0,
        },
      })),
    },
  }
}

function apiWith(syncScanBatch: ApiClient['syncScanBatch']): ApiClient {
  return {
    recordScan: vi.fn(),
    recordPinScan: vi.fn(),
    syncScanBatch,
    reportDiscardedScans: vi.fn(),
    fetchRoster: vi.fn(),
    sendHeartbeat: vi.fn(),
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    fetchBranding: vi.fn(),
  }
}

/** Un almacen cuyo `remove` no borra nada: la cola NO encoge aunque el servidor confirme. */
function storageThatNeverShrinks(): QueueStorage {
  const real = createMemoryQueueStorage()
  return { ...real, remove: async () => undefined }
}

/**
 * El PRIMER lote no contesta hasta que la prueba lo suelta; los siguientes
 * contestan en el acto con lo mismo (`answer`). Asi un relanzamiento indebido
 * se ve como una segunda llamada, sin quedarse colgado.
 */
function firstBatchHeld(answer: (request: ScanBatchRequest) => ApiResult<ScanBatchResponse>): {
  readonly syncScanBatch: ApiClient['syncScanBatch']
  readonly calls: ScanBatchRequest[]
  release(): void
} {
  const calls: ScanBatchRequest[] = []
  let releaseFirst: () => void = () => undefined
  const firstGate = new Promise<void>((resolve) => {
    releaseFirst = resolve
  })
  return {
    calls,
    syncScanBatch: async (request) => {
      calls.push(request)
      if (calls.length === 1) await firstGate
      return answer(request)
    },
    release: () => releaseFirst(),
  }
}

describe('R2-KI-06 (b) — `MAX_BATCHES_PER_DRAIN`: un drenaje no envia sin descanso (RF-KI-04)', () => {
  it('el techo es de 10 lotes por drenaje', () => {
    expect(MAX_BATCHES_PER_DRAIN).toBe(10)
  })

  it('con una cola que no encoge, un drenaje manda 10 lotes y se para', async () => {
    const queue = createScanQueue({
      openStorage: storageThatNeverShrinks,
      clock: DRAIN_LIMITS_CLOCK,
    })
    for (const pending of scans(3)) await queue.enqueue(pending)
    const sent: ScanBatchRequest[] = []
    const runner = createSyncRunner({
      api: apiWith(async (request) => {
        sent.push(request)
        return allAccepted(request)
      }),
      queue,
      clock: DRAIN_LIMITS_CLOCK,
      isOnline: () => true,
      setTimer: () => 0,
      clearTimer: () => undefined,
    })

    await runner.drain({ ignoreSchedule: true })

    expect(sent).toHaveLength(10)
  })

  it('con 501 pendientes, un drenaje envia 500 en 10 lotes de 50 y deja el ultimo para el siguiente', async () => {
    const queue = createScanQueue({
      openStorage: createMemoryQueueStorage,
      clock: DRAIN_LIMITS_CLOCK,
    })
    for (const pending of scans(501)) await queue.enqueue(pending)
    const sizes: number[] = []
    const runner = createSyncRunner({
      api: apiWith(async (request) => {
        sizes.push(request.scans.length)
        return allAccepted(request)
      }),
      queue,
      clock: DRAIN_LIMITS_CLOCK,
      isOnline: () => true,
      setTimer: () => 0,
      clearTimer: () => undefined,
    })

    await runner.drain({ ignoreSchedule: true })

    expect(sizes).toEqual([50, 50, 50, 50, 50, 50, 50, 50, 50, 50])
    expect(queue.stats().size).toBe(1)
  })

  it('lo que queda tras el techo se programa en el acto, sin esperar al sondeo de 30 s', async () => {
    const queue = createScanQueue({
      openStorage: createMemoryQueueStorage,
      clock: DRAIN_LIMITS_CLOCK,
    })
    for (const pending of scans(501)) await queue.enqueue(pending)
    const delays: number[] = []
    const runner = createSyncRunner({
      api: apiWith(async (request) => allAccepted(request)),
      queue,
      clock: DRAIN_LIMITS_CLOCK,
      isOnline: () => true,
      setTimer: (_handler, delayMs) => {
        delays.push(delayMs)
        return delays.length
      },
      clearTimer: () => undefined,
    })
    runner.start()
    await vi.waitFor(() => expect(delays).toHaveLength(1))

    expect(delays).toEqual([0])
    runner.stop()
  })
})

describe('R2-KI-06 (a) — `stop()` con un drenaje en vuelo (RF-KI-03)', () => {
  it('el envio en vuelo termina y su desenlace se aplica: lo confirmado sale de la cola', async () => {
    const queue = createScanQueue({
      openStorage: createMemoryQueueStorage,
      clock: DRAIN_LIMITS_CLOCK,
    })
    for (const pending of scans(2)) await queue.enqueue(pending)
    const batch = firstBatchHeld(allAccepted)
    const runner = createSyncRunner({
      api: apiWith(batch.syncScanBatch),
      queue,
      clock: DRAIN_LIMITS_CLOCK,
      isOnline: () => true,
      setTimer: () => 0,
      clearTimer: () => undefined,
    })
    runner.start()
    await vi.waitFor(() => expect(batch.calls).toHaveLength(1))

    runner.stop()
    batch.release()
    await vi.waitFor(() => expect(queue.stats().size).toBe(0))

    expect(batch.calls).toHaveLength(1)
  })

  it('al terminar el drenaje en vuelo no queda ningun temporizador programado', async () => {
    const queue = createScanQueue({
      openStorage: createMemoryQueueStorage,
      clock: DRAIN_LIMITS_CLOCK,
    })
    for (const pending of scans(2)) await queue.enqueue(pending)
    const batch = firstBatchHeld(allAccepted)
    const scheduled: number[] = []
    const syncing: boolean[] = []
    const runner = createSyncRunner({
      api: apiWith(batch.syncScanBatch),
      queue,
      clock: DRAIN_LIMITS_CLOCK,
      isOnline: () => true,
      onSyncing: (value) => syncing.push(value),
      setTimer: (_handler, delayMs) => {
        scheduled.push(delayMs)
        return scheduled.length
      },
      clearTimer: () => undefined,
    })
    runner.start()
    await vi.waitFor(() => expect(batch.calls).toHaveLength(1))

    runner.stop()
    batch.release()
    await vi.waitFor(() => expect(syncing).toEqual([true, false]))

    expect(scheduled).toEqual([])
  })

  it('un relanzamiento pedido durante el drenaje no envia nada si entretanto se paro', async () => {
    const queue = createScanQueue({
      openStorage: createMemoryQueueStorage,
      clock: DRAIN_LIMITS_CLOCK,
    })
    for (const pending of scans(1)) await queue.enqueue(pending)
    const batch = firstBatchHeld(() => ({ outcome: 'failed', cause: 'network' }))
    const syncing: boolean[] = []
    const runner = createSyncRunner({
      api: apiWith(batch.syncScanBatch),
      queue,
      clock: DRAIN_LIMITS_CLOCK,
      isOnline: () => true,
      onSyncing: (value) => syncing.push(value),
      setTimer: () => 0,
      clearTimer: () => undefined,
    })
    runner.start()
    await vi.waitFor(() => expect(batch.calls).toHaveLength(1))
    runner.wakeNow()

    runner.stop()
    batch.release()
    await vi.waitFor(() => expect(syncing.at(-1)).toBe(false))
    await vi.waitFor(() => expect(queue.isHeadInFlight()).toBe(false))

    expect(batch.calls).toHaveLength(1)
  })
})
