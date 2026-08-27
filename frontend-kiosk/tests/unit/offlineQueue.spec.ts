// La cola de fichajes: lo que la hace «sagrada».
//
// Se prueba dos veces la misma logica: sobre el respaldo en memoria y sobre
// Dexie/IndexedDB de verdad (con `fake-indexeddb`). No es duplicar por gusto:
// el respaldo existe para que una tablet con IndexedDB inutilizable siga
// fichando, y si los dos no se comportan igual, el dia que el respaldo entre en
// juego el quiosco hara otra cosa distinta sin que nadie se entere.

import 'fake-indexeddb/auto'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { QueuedScan } from '@/features/scan/application/ports'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import type { ScanQueue } from '@/features/offline/application/scanQueue'
import { MAX_RETRY_DELAY_MS, retryDelayMs } from '@/features/offline/domain/backoff'
import {
  batchesOf,
  compareByOccurredAt,
  MAX_BATCH_SIZE,
  orderForSync,
} from '@/features/offline/domain/queueOrder'
import {
  createDexieQueueStorage,
  openKioskDatabase,
} from '@/features/offline/infrastructure/dexieStorage'
import { createMemoryQueueStorage } from '@/features/offline/infrastructure/queueStorage'
import type { QueueStorage } from '@/features/offline/infrastructure/queueStorage'
import { fixedClock } from '@/shared/time/clock'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'

function scan(scanId: string, occurredAt: string): QueuedScan {
  return {
    scan_id: scanId,
    qr_payload: PAYLOAD,
    occurred_at: occurredAt,
    intent: 'auto',
    device_id: 'kiosk-1',
  }
}

let databaseCounter = 0

const backends: Array<{ name: string; open: () => QueueStorage }> = [
  { name: 'respaldo en memoria', open: () => createMemoryQueueStorage() },
  {
    name: 'Dexie sobre IndexedDB',
    open: () => {
      databaseCounter += 1
      return createDexieQueueStorage(openKioskDatabase(`kronoqr-test-${databaseCounter}`))
    },
  },
]

describe.each(backends)('cola de fichajes ($name)', (backend) => {
  let queue: ScanQueue

  beforeEach(() => {
    queue = createScanQueue({
      openStorage: backend.open,
      clock: fixedClock(new Date('2026-08-14T06:00:00.000Z')),
    })
  })

  it('encola y lo publica en el contador sin que nadie pregunte', async () => {
    const seen: number[] = []
    queue.subscribe((stats) => seen.push(stats.size))

    await queue.enqueue(scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31.000Z'))

    expect(queue.stats().size).toBe(1)
    expect(queue.stats().oldestOccurredAt).toBe('2026-08-14T05:58:31.000Z')
    expect(seen).toEqual([0, 1])
  })

  it('persiste `intent` desde la v1 del esquema (ADR-024)', async () => {
    await queue.enqueue(scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31.000Z'))

    const [row] = await queue.claim(1)
    expect(row?.intent).toBe('auto')
  })

  it('encolar dos veces el mismo escaneo no crea dos filas', async () => {
    const same = scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31.000Z')

    await queue.enqueue(same)
    await queue.enqueue(same)

    expect(queue.stats().size).toBe(1)
  })

  it('entrega ordenado por `occurred_at`, no por orden de encolado', async () => {
    // La salida se encola ANTES que la entrada: es lo que pasa cuando se
    // reintenta una cola desordenada.
    await queue.enqueue(scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T14:03:12.000Z'))
    await queue.enqueue(scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31.000Z'))

    const claimed = await queue.claim(10)

    expect(claimed.map((record) => record.occurred_at)).toEqual([
      '2026-08-14T05:58:31.000Z',
      '2026-08-14T14:03:12.000Z',
    ])
  })

  it('NO borra sin confirmacion explicita del servidor', async () => {
    await queue.enqueue(scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31.000Z'))

    // Se toma, se intenta y falla: sigue en la cola.
    const claimed = await queue.claim(10)
    await queue.retryLater(claimed.map((record) => record.scan_id))

    expect(queue.stats().size).toBe(1)

    await queue.confirm(['0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'])
    expect(queue.stats().size).toBe(0)
  })

  it('no entrega dos veces lo que ya esta viajando', async () => {
    await queue.enqueue(scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31.000Z'))

    const first = await queue.claim(10)
    const second = await queue.claim(10)

    expect(first).toHaveLength(1)
    expect(second).toHaveLength(0)
  })

  it('un fallo aplaza el reintento con la escalera exponencial', async () => {
    const nowMs = new Date('2026-08-14T06:00:00.000Z').getTime()
    await queue.enqueue(scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31.000Z'))

    await queue.retryLater(['0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'])
    expect(queue.stats().nextAttemptAt).toBe(nowMs + 1_000)

    await queue.retryLater(['0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'])
    expect(queue.stats().nextAttemptAt).toBe(nowMs + 2_000)

    // Y hasta que llegue el momento, no se entrega.
    expect(await queue.claim(10)).toHaveLength(0)
    // Salvo que vuelva la red: eso es noticia y se atiende ya.
    expect(await queue.claim(10, { ignoreSchedule: true })).toHaveLength(1)
  })

  it('el mas antiguo alimenta `oldest_pending_at` del latido', async () => {
    await queue.enqueue(scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T14:03:12.000Z'))
    await queue.enqueue(scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31.000Z'))

    expect(queue.stats().oldestOccurredAt).toBe('2026-08-14T05:58:31.000Z')
  })

  it('aguanta el peor escenario del plan: 40 encolados', async () => {
    for (let index = 0; index < 40; index += 1) {
      const minute = String(index).padStart(2, '0')
      await queue.enqueue(
        scan(`0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b${minute}`, `2026-08-14T05:${minute}:00.000Z`),
      )
    }

    expect(queue.stats().size).toBe(40)
    const claimed = await queue.claim(MAX_BATCH_SIZE)
    expect(claimed).toHaveLength(40)
    expect(claimed[0]?.occurred_at).toBe('2026-08-14T05:00:00.000Z')
    expect(claimed[39]?.occurred_at).toBe('2026-08-14T05:39:00.000Z')
  })
})

describe('cola sin almacenamiento utilizable', () => {
  it('sigue aceptando fichajes y avisa de que no es duradero', async () => {
    const failures: string[] = []
    const queue = createScanQueue({
      openStorage: () => {
        throw new Error('QuotaExceededError')
      },
      onStorageFailure: (reason) => failures.push(reason),
    })

    const outcome = await queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31.000Z'),
    )

    // Regla dura 19: el empleado ficha igual.
    expect(outcome.stored).toBe(true)
    expect(outcome.durable).toBe(false)
    expect(queue.stats().size).toBe(1)
    expect(failures).toHaveLength(1)
  })

  it('cae a memoria si IndexedDB se rompe en marcha, sin perder el fichaje', async () => {
    const broken: QueueStorage = {
      ...createMemoryQueueStorage(),
      durable: true,
      add: vi.fn(async () => {
        throw new Error('InvalidStateError')
      }),
    }
    const queue = createScanQueue({ openStorage: () => broken })

    const outcome = await queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31.000Z'),
    )

    expect(outcome.stored).toBe(true)
    expect(queue.stats().size).toBe(1)
    expect(queue.stats().durable).toBe(false)
  })
})

describe('orden y troceado del lote', () => {
  it('ordena por `occurred_at` ascendente', () => {
    const ordered = orderForSync([
      { scan_id: 'b', occurred_at: '2026-08-14T14:03:12.000Z' },
      { scan_id: 'a', occurred_at: '2026-08-14T05:58:31.000Z' },
    ])

    expect(ordered.map((item) => item.scan_id)).toEqual(['a', 'b'])
  })

  it('desempata por `scan_id`, que en UUID v7 es orden temporal', () => {
    const first = { scan_id: '0199f0c2-1f4a-7c3e-9b21-000000000001', occurred_at: 'x' }
    const second = { scan_id: '0199f0c2-1f4a-7c3e-9b21-000000000002', occurred_at: 'x' }

    expect(compareByOccurredAt(first, second)).toBeLessThan(0)
    expect(compareByOccurredAt(second, first)).toBeGreaterThan(0)
    expect(compareByOccurredAt(first, first)).toBe(0)
  })

  it('una marca ilegible viaja al final, pero viaja', () => {
    const ordered = orderForSync([
      { scan_id: 'roto', occurred_at: 'no-es-una-fecha' },
      { scan_id: 'bueno', occurred_at: '2026-08-14T05:58:31.000Z' },
    ])

    expect(ordered.map((item) => item.scan_id)).toEqual(['bueno', 'roto'])
  })

  it('trocea en lotes de 50 con los mas antiguos delante', () => {
    const records = Array.from({ length: 120 }, (_, index) => ({
      scan_id: String(index).padStart(3, '0'),
      occurred_at: new Date(Date.UTC(2026, 7, 14, 0, index)).toISOString(),
    }))

    const batches = batchesOf([...records].reverse())

    expect(batches.map((batch) => batch.length)).toEqual([50, 50, 20])
    expect(batches[0]?.[0]?.scan_id).toBe('000')
    expect(batches[2]?.[19]?.scan_id).toBe('119')
  })
})

describe('retroceso exponencial (§6)', () => {
  it('dobla desde 1 s y se detiene en 5 min', () => {
    expect(retryDelayMs(0)).toBe(0)
    expect(retryDelayMs(1)).toBe(1_000)
    expect(retryDelayMs(2)).toBe(2_000)
    expect(retryDelayMs(3)).toBe(4_000)
    expect(retryDelayMs(9)).toBe(256_000)
    expect(retryDelayMs(10)).toBe(MAX_RETRY_DELAY_MS)
    expect(retryDelayMs(500)).toBe(MAX_RETRY_DELAY_MS)
  })
})
