// KT5 — la cola cuando FALLA una escritura que no es encolar: `reschedule`
// (aplazar tras un envio fallido) y `clear` (desvincular). RF-KI-03, RF-KI-04,
// ADR-047.
//
// Lo que se protege es lo mismo en los dos casos: un fallo de IndexedDB no saca
// un fichaje de la cola ni hace que la pantalla diga «0 pendientes» con filas en
// el disco. Se usa `fake-indexeddb` con la MISMA base abierta por dos
// manejadores: lo que hay en el disco se comprueba con un manejador nuevo, sin
// pasar por la cola que se esta probando.

import 'fake-indexeddb/auto'
import { describe, expect, it } from 'vitest'
import type { QueuedScan } from '@/features/scan/application/ports'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import {
  createDexieQueueStorage,
  openKioskDatabase,
} from '@/features/offline/infrastructure/dexieStorage'
import type { QueueStorage } from '@/features/offline/infrastructure/queueStorage'
import { fixedClock } from '@/shared/time/clock'

const WRITE_FAILURES_PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const WRITE_FAILURES_CLOCK = fixedClock(new Date('2026-08-14T09:30:00.000Z'))
const WRITE_FAILURES_ENTRY = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8c01'
const WRITE_FAILURES_EXIT = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8c02'

function scan(scanId: string, occurredAt: string): QueuedScan {
  return {
    kind: 'qr',
    scan_id: scanId,
    qr_payload: WRITE_FAILURES_PAYLOAD,
    occurred_at: occurredAt,
    intent: 'auto',
    device_id: 'kiosk-1',
  }
}

let databaseCounter = 0
function freshDatabaseName(): string {
  databaseCounter += 1
  return `kronoqr-kt5-write-${databaseCounter}`
}

type FailingOperation = 'reschedule' | 'clear'

/**
 * Manejadores reales sobre la base `name`. `failing` lanza en TODOS (tambien en
 * los que abre la cola al reabrir) mientras `brokenWrites` lo diga: el disco se
 * sigue leyendo, solo esa escritura falla.
 */
function storageFactory(
  name: string,
  failing: FailingOperation,
  brokenWrites: { remaining: number },
): () => QueueStorage {
  return () => {
    const real = createDexieQueueStorage(openKioskDatabase(name))
    const failingWrite = async (...args: never[]): Promise<void> => {
      if (brokenWrites.remaining > 0) {
        brokenWrites.remaining -= 1
        throw new DOMException('write failed', 'UnknownError')
      }
      const original = real[failing] as (...input: never[]) => Promise<void>
      await original(...args)
    }
    return { ...real, [failing]: failingWrite }
  }
}

/** Lo que hay en el disco, leido con un manejador que la cola no conoce. */
async function rowsOnDisk(name: string): Promise<{ scan_id: string; attempts: number }[]> {
  const witness = createDexieQueueStorage(openKioskDatabase(name))
  try {
    const rows = await witness.list(100)
    return rows.map((row) => ({ scan_id: row.scan_id, attempts: row.attempts }))
  } finally {
    witness.close()
  }
}

describe('KT5 — `reschedule` que falla al aplazar un envio (RF-KI-03, RF-KI-04)', () => {
  it('un fallo puntual se arregla reabriendo: la fila queda aplazada con un intento mas', async () => {
    const name = freshDatabaseName()
    const brokenWrites = { remaining: 0 }
    const queue = createScanQueue({
      openStorage: storageFactory(name, 'reschedule', brokenWrites),
      clock: WRITE_FAILURES_CLOCK,
      sleep: async () => undefined,
    })
    await queue.enqueue(scan(WRITE_FAILURES_ENTRY, '2026-08-14T06:00:00.000Z'))
    await queue.claim(50, { ignoreSchedule: true })
    brokenWrites.remaining = 1

    await queue.retryLater([WRITE_FAILURES_ENTRY])

    expect(await rowsOnDisk(name)).toEqual([{ scan_id: WRITE_FAILURES_ENTRY, attempts: 1 }])
    expect(queue.stats().size).toBe(1)
    expect(queue.stats().storage).toBe('durable')
  })

  it('un fallo que no se arregla no saca la fila del disco ni la deja arrendada', async () => {
    const name = freshDatabaseName()
    const brokenWrites = { remaining: 0 }
    const queue = createScanQueue({
      openStorage: storageFactory(name, 'reschedule', brokenWrites),
      clock: WRITE_FAILURES_CLOCK,
      sleep: async () => undefined,
    })
    await queue.enqueue(scan(WRITE_FAILURES_ENTRY, '2026-08-14T06:00:00.000Z'))
    await queue.claim(50, { ignoreSchedule: true })
    brokenWrites.remaining = Number.MAX_SAFE_INTEGER

    await queue.retryLater([WRITE_FAILURES_ENTRY])

    expect(await rowsOnDisk(name)).toEqual([{ scan_id: WRITE_FAILURES_ENTRY, attempts: 0 }])
    const again = await queue.claim(50, { ignoreSchedule: true })
    expect(again.map((row) => row.scan_id)).toEqual([WRITE_FAILURES_ENTRY])
  })

  it('un fallo que no se arregla no pinta «0 pendientes» con la fila en el disco', async () => {
    const name = freshDatabaseName()
    const brokenWrites = { remaining: 0 }
    const queue = createScanQueue({
      openStorage: storageFactory(name, 'reschedule', brokenWrites),
      clock: WRITE_FAILURES_CLOCK,
      sleep: async () => undefined,
    })
    await queue.enqueue(scan(WRITE_FAILURES_ENTRY, '2026-08-14T06:00:00.000Z'))
    await queue.claim(50, { ignoreSchedule: true })
    brokenWrites.remaining = Number.MAX_SAFE_INTEGER

    await queue.retryLater([WRITE_FAILURES_ENTRY])

    expect(queue.stats().size).toBe(1)
  })
})

describe('KT5 — `clear` que falla al desvincular (RF-KI-04, RF-PD-06)', () => {
  it('no lanza: la desvinculacion sigue aunque el disco no se deje vaciar', async () => {
    const name = freshDatabaseName()
    const brokenWrites = { remaining: 0 }
    const queue = createScanQueue({
      openStorage: storageFactory(name, 'clear', brokenWrites),
      clock: WRITE_FAILURES_CLOCK,
      sleep: async () => undefined,
    })
    await queue.enqueue(scan(WRITE_FAILURES_ENTRY, '2026-08-14T06:00:00.000Z'))
    brokenWrites.remaining = Number.MAX_SAFE_INTEGER

    await expect(queue.clear()).resolves.toBeUndefined()
  })

  it('con las filas todavia en el disco, no publica «cola vacia»', async () => {
    const name = freshDatabaseName()
    const brokenWrites = { remaining: 0 }
    const queue = createScanQueue({
      openStorage: storageFactory(name, 'clear', brokenWrites),
      clock: WRITE_FAILURES_CLOCK,
      sleep: async () => undefined,
    })
    await queue.enqueue(scan(WRITE_FAILURES_ENTRY, '2026-08-14T06:00:00.000Z'))
    await queue.enqueue(scan(WRITE_FAILURES_EXIT, '2026-08-14T14:00:00.000Z'))
    brokenWrites.remaining = Number.MAX_SAFE_INTEGER

    await queue.clear()

    expect(await rowsOnDisk(name)).toHaveLength(2)
    expect(queue.stats().size).toBe(2)
  })

  it('un fallo puntual se arregla reabriendo: el disco queda vacio de verdad', async () => {
    const name = freshDatabaseName()
    const brokenWrites = { remaining: 0 }
    const queue = createScanQueue({
      openStorage: storageFactory(name, 'clear', brokenWrites),
      clock: WRITE_FAILURES_CLOCK,
      sleep: async () => undefined,
    })
    await queue.enqueue(scan(WRITE_FAILURES_ENTRY, '2026-08-14T06:00:00.000Z'))
    brokenWrites.remaining = 1

    await queue.clear()

    expect(await rowsOnDisk(name)).toEqual([])
    expect(queue.stats().size).toBe(0)
  })
})
