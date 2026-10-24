// @vitest-environment jsdom
// A1, coste y bloqueo: la cola decide con metadatos (en claro) y solo descifra las filas
// que reclama; las filas cifradas que no se abren se saltan y no bloquean a las demas.

import 'fake-indexeddb/auto'
import { describe, expect, it, vi } from 'vitest'
import type { QueuedScan } from '@/features/scan/application/ports'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import {
  createDexieQueueStorage,
  openKioskDatabase,
} from '@/features/offline/infrastructure/dexieStorage'
import {
  createDexieQueueKeyStore,
  createQueueCipher,
} from '@/features/offline/infrastructure/queueCipher'
import type { QueueCipher } from '@/features/offline/infrastructure/queueCipher'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'

let counter = 0
function names(): { db: string; keys: string } {
  counter += 1
  return { db: `kronoqr-b17-reads-${counter}`, keys: `kronoqr-b17-reads-key-${counter}` }
}

function id(index: number): string {
  return `0199f0c2-1f4a-7c3e-9b21-${String(index).padStart(12, '0')}`
}

function scan(index: number): QueuedScan {
  return {
    kind: 'qr',
    scan_id: id(index),
    qr_payload: PAYLOAD,
    occurred_at: `2026-08-14T${String(Math.floor(index / 60) + 5).padStart(2, '0')}:${String(index % 60).padStart(2, '0')}:00.000Z`,
    intent: 'auto',
    device_id: 'kiosk-1',
  }
}

function counting(cipher: QueueCipher): QueueCipher & { opened: () => number; reset: () => void } {
  let opened = 0
  return {
    seal: (scanId, plaintext) => cipher.seal(scanId, plaintext),
    open: (scanId, blob) => {
      opened += 1
      return cipher.open(scanId, blob)
    },
    opened: () => opened,
    reset: () => {
      opened = 0
    },
  }
}

describe('coste: los metadatos no descifran', () => {
  it('encolar (y su recuento) no descifra ninguna fila; reclamar descifra solo las reclamadas', async () => {
    const n = names()
    const cipher = counting(createQueueCipher({ keyStore: createDexieQueueKeyStore(n.keys) }))
    const queue = createScanQueue({
      openStorage: () => createDexieQueueStorage(openKioskDatabase(n.db), { cipher }),
      sleep: async () => undefined,
    })
    for (let index = 1; index <= 30; index += 1) await queue.enqueue(scan(index))
    expect(cipher.opened()).toBe(0)

    const claimed = await queue.claim(5, { ignoreSchedule: true })

    expect(claimed.map((row) => row.scan_id)).toEqual([1, 2, 3, 4, 5].map(id))
    expect(claimed[0]).toMatchObject({ qr_payload: PAYLOAD })
    expect(cipher.opened()).toBe(5)

    cipher.reset()
    await queue.retryLater([id(1)])
    await queue.refresh()
    expect(cipher.opened()).toBe(0)
  })
})

describe('bloqueo: las filas ilegibles no impiden salir a las legibles', () => {
  it('500 ilegibles delante de una legible: la legible sale, se avisa UNA vez y las ilegibles se conservan', async () => {
    const n = names()
    // 500 filas cifradas que ya no se pueden abrir (bytes que no son un sobre valido).
    const handle = openKioskDatabase(n.db)
    await handle.scans.bulkPut(
      Array.from({ length: 500 }, (_unused, index) => ({
        kind: 'qr' as const,
        scan_id: id(index + 1),
        occurred_at: `2026-08-14T04:${String(Math.floor(index / 60)).padStart(2, '0')}:${String(index % 60).padStart(2, '0')}.000Z`,
        intent: 'auto' as const,
        device_id: 'kiosk-1',
        attempts: 0,
        next_attempt_at: 0,
        enqueued_at: 1,
        enc: { v: 1 as const, iv: 'AAAAAAAAAAAAAAAA', ct: 'AAAAAAAAAAAAAAAAAAAAAA==' },
      })),
    )
    handle.close()

    const cipher = createQueueCipher({ keyStore: createDexieQueueKeyStore(n.keys) })
    const onUnreadable = vi.fn()
    const queue = createScanQueue({
      openStorage: () => createDexieQueueStorage(openKioskDatabase(n.db), { cipher }),
      sleep: async () => undefined,
      onUnreadable,
    })
    await queue.enqueue(scan(900))

    const claimed = await queue.claim(50, { ignoreSchedule: true })

    expect(claimed.map((row) => row.scan_id)).toEqual([id(900)])
    expect(onUnreadable).toHaveBeenCalledTimes(1)
    // Se conservan en disco y cuentan: nada se pierde.
    expect(await createDexieQueueStorage(openKioskDatabase(n.db)).count()).toBe(501)
    expect(queue.stats().size).toBe(501)
  })

  it('con TODAS ilegibles el drenaje no gira en vacio: la cabeza publicada no es una ilegible', async () => {
    const n = names()
    const handle = openKioskDatabase(n.db)
    await handle.scans.bulkPut(
      [1, 2, 3].map((index) => ({
        kind: 'qr' as const,
        scan_id: id(index),
        occurred_at: `2026-08-14T04:00:0${index}.000Z`,
        intent: 'auto' as const,
        device_id: 'kiosk-1',
        attempts: 0,
        next_attempt_at: 0,
        enqueued_at: 1,
        enc: { v: 1 as const, iv: 'AAAAAAAAAAAAAAAA', ct: 'AAAAAAAAAAAAAAAAAAAAAA==' },
      })),
    )
    handle.close()
    const queue = createScanQueue({
      openStorage: () =>
        createDexieQueueStorage(openKioskDatabase(n.db), {
          cipher: createQueueCipher({ keyStore: createDexieQueueKeyStore(n.keys) }),
        }),
      sleep: async () => undefined,
    })

    expect(await queue.claim(50, { ignoreSchedule: true })).toEqual([])

    // Sin cabeza elegible no hay fecha que esperar (el sondeo lento, no un bucle a 0 ms).
    expect(queue.stats().nextAttemptAt).toBeNull()
    expect(queue.stats().oldestOccurredAt).toBeNull()
    expect(queue.stats().inStore).toBe(3)
  })
})
