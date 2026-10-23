// V2-KI-02: con la cuota agotada (IndexedDB se lee pero no se escribe), las filas
// que ya estan en disco se envian y se borran SIN recargar, y lo nuevo entra en
// memoria sin adelantar a nada (RN-21). Regla dura 19: el fichaje entra siempre.

import 'fake-indexeddb/auto'
import { describe, expect, it, vi } from 'vitest'
import type { QueuedScan } from '@/features/scan/application/ports'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import { createSyncRunner } from '@/features/offline/application/syncRunner'
import {
  createDexieQueueStorage,
  openKioskDatabase,
} from '@/features/offline/infrastructure/dexieStorage'
import type { QueueStorage } from '@/features/offline/infrastructure/queueStorage'
import type { ApiClient } from '@/shared/api/client'
import type { Clock } from '@/shared/time/clock'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const IDS = [
  '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8c01',
  '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8c02',
  '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8c03',
  '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8c04',
] as const

interface DiskState {
  full: boolean
  removeFails: boolean
}

function scan(scanId: string, occurredAt: string): QueuedScan {
  return {
    kind: 'qr',
    scan_id: scanId,
    qr_payload: PAYLOAD,
    occurred_at: occurredAt,
    intent: 'auto',
    device_id: 'kiosk-1',
  }
}

let counter = 0

function steppingClock(): Clock & { advance(ms: number): void } {
  let current = Date.parse('2026-08-14T09:30:00.000Z')
  return {
    now: () => new Date(current),
    advance: (ms) => {
      current += ms
    },
  }
}

/** IndexedDB real (fake-indexeddb) cuyas escrituras de fichajes fallan mientras `state.full`. */
function quotaDisk(state: DiskState, name: string): () => QueueStorage {
  return () => {
    const real = createDexieQueueStorage(openKioskDatabase(name))
    return {
      ...real,
      add: async (record) => {
        if (state.full) throw new DOMException('quota', 'QuotaExceededError')
        return real.add(record)
      },
      remove: async (ids) => {
        if (state.removeFails) throw new DOMException('quota', 'QuotaExceededError')
        return real.remove(ids)
      },
    }
  }
}

async function fullQueue(): Promise<{
  queue: ReturnType<typeof createScanQueue>
  state: DiskState
  clock: ReturnType<typeof steppingClock>
  name: string
}> {
  counter += 1
  const name = `kronoqr-b17-quota-${counter}`
  const state: DiskState = { full: false, removeFails: false }
  const clock = steppingClock()
  const queue = createScanQueue({
    openStorage: quotaDisk(state, name),
    clock,
    sleep: async () => undefined,
  })
  await queue.enqueue(scan(IDS[0], '2026-08-14T05:00:00.000Z'))
  await queue.enqueue(scan(IDS[1], '2026-08-14T06:00:00.000Z'))
  state.full = true
  return { queue, state, clock, name }
}

describe('V2-KI-02 — cuota agotada: lo que esta en disco se envia sin recargar', () => {
  it('la fila nueva entra en memoria, el recuento se SABE y claim() entrega disco + memoria en orden', async () => {
    const { queue } = await fullQueue()

    const outcome = await queue.enqueue(scan(IDS[2], '2026-08-14T07:00:00.000Z'))

    expect(outcome).toEqual({ stored: true, durable: false })
    const stats = queue.stats()
    expect(stats.storage).toBe('memory')
    // El disco SE VE: no es un «desconocido».
    expect(stats.size).toBe(3)
    expect(queue.isClaimBlocked()).toBe(false)
    const claimed = await queue.claim(10, { ignoreSchedule: true })
    expect(claimed.map((row) => row.scan_id)).toEqual([IDS[0], IDS[1], IDS[2]])
  })

  it('enviadas y confirmadas las tres sin recargar, y liberado el sitio se vuelve a disco', async () => {
    const { queue, state, clock, name } = await fullQueue()
    await queue.enqueue(scan(IDS[2], '2026-08-14T07:00:00.000Z'))

    const api = {
      syncScanBatch: vi.fn(async (body: { scans: Array<{ scan_id: string }> }) => ({
        outcome: 'ok' as const,
        data: {
          results: body.scans.map((entry) => ({
            scan_id: entry.scan_id,
            status: 200 as const,
            outcome: {},
          })),
        },
      })),
      reportDiscardedScans: vi.fn(),
    } as unknown as ApiClient
    const runner = createSyncRunner({
      api,
      queue,
      clock,
      isOnline: () => true,
      setTimer: () => 0,
      clearTimer: () => undefined,
    })

    await runner.drain({ ignoreSchedule: true })

    // Salieron las tres, en un lote y en orden, sin recargar la pagina.
    const sent = vi.mocked(api.syncScanBatch).mock.calls.flatMap((call) => call[0].scans)
    expect(sent.map((entry) => entry.scan_id)).toEqual([IDS[0], IDS[1], IDS[2]])
    expect(queue.stats().size).toBe(0)

    // Liberado el sitio, la siguiente escritura vuelve a ser duradera.
    state.full = false
    clock.advance(61_000)
    expect(await queue.tryReopen()).toBe(true)
    expect(queue.stats().storage).toBe('durable')
    const outcome = await queue.enqueue(scan(IDS[3], '2026-08-14T08:00:00.000Z'))
    expect(outcome).toEqual({ stored: true, durable: true })
    expect(await openKioskDatabase(name).scans.count()).toBe(1)
  })

  it('vuelve a disco y migra lo de memoria cuando el disco admite escrituras otra vez', async () => {
    const { queue, state, clock, name } = await fullQueue()
    await queue.enqueue(scan(IDS[2], '2026-08-14T07:00:00.000Z'))
    expect(queue.stats().storage).toBe('memory')

    // Sigue lleno: no se puede migrar, y no se pierde nada.
    clock.advance(61_000)
    expect(await queue.tryReopen()).toBe(false)
    expect(queue.stats().size).toBe(3)

    state.full = false
    clock.advance(61_000)
    expect(await queue.tryReopen()).toBe(true)
    expect(queue.stats().storage).toBe('durable')
    expect(queue.stats().size).toBe(3)
    const raw = await openKioskDatabase(name).scans.orderBy('occurred_at').toArray()
    expect(raw.map((row) => row.scan_id)).toEqual([IDS[0], IDS[1], IDS[2]])
  })

  it('un borrado que el disco rechaza se declara (confirm = false) y no estropea la memoria', async () => {
    const { queue, state } = await fullQueue()
    await queue.enqueue(scan(IDS[2], '2026-08-14T07:00:00.000Z'))
    state.removeFails = true

    const removed = await queue.confirm([IDS[0]])

    expect(removed).toBe(false)
    // La memoria funciona: el estado NO pasa a `unavailable`.
    expect(queue.stats().storage).toBe('memory')
    expect(queue.stats().size).toBe(3)
    const outcome = await queue.enqueue(scan(IDS[3], '2026-08-14T08:00:00.000Z'))
    expect(outcome.stored).toBe(true)
  })

  it('si el disco deja de LEERSE tambien, vuelve la memoria pura con el disco «desconocido»', async () => {
    counter += 1
    const name = `kronoqr-b17-quota-${counter}`
    const state: DiskState = { full: false, removeFails: false }
    let readsBroken = false
    const queue = createScanQueue({
      openStorage: () => {
        const base = quotaDisk(state, name)()
        return {
          ...base,
          list: async (limit) => {
            if (readsBroken) throw new Error('InvalidStateError')
            return base.list(limit)
          },
        }
      },
      sleep: async () => undefined,
    })
    await queue.enqueue(scan(IDS[0], '2026-08-14T05:00:00.000Z'))
    state.full = true
    await queue.enqueue(scan(IDS[1], '2026-08-14T06:00:00.000Z'))
    expect(queue.stats().size).toBe(2)

    readsBroken = true
    await queue.refresh()

    // Lo que hay en el disco ya no se ve: `null`, nunca 0, y nada de memoria sale antes.
    expect(queue.stats().size).toBeNull()
    expect(queue.isClaimBlocked()).toBe(true)
  })
})
