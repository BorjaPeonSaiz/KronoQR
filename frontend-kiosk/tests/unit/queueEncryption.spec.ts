// @vitest-environment jsdom
// A1: la cola y la lista de descartados se guardan cifradas (AES-GCM, clave no
// extraible). Migracion de las filas en claro, y regla dura 19: si el cifrado falla,
// el fichaje entra igual en claro y se avisa sin datos personales.

import 'fake-indexeddb/auto'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createDexieQueueStorage,
  openKioskDatabase,
} from '@/features/offline/infrastructure/dexieStorage'
import {
  createDexieQueueKeyStore,
  createQueueCipher,
} from '@/features/offline/infrastructure/queueCipher'
import type { QueueKeyStore } from '@/features/offline/infrastructure/queueCipher'
import type {
  DiscardedScanRecord,
  QueuedScanRecord,
} from '@/features/offline/infrastructure/queueStorage'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const ID_QR = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8f01'
const ID_PIN = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8f02'
const SEALED_PIN = 'c2VhbGVkLXBpbi1lbnZlbG9wZS1wbGFjZWhvbGRlcg=='

const QR: QueuedScanRecord = {
  kind: 'qr',
  scan_id: ID_QR,
  qr_payload: PAYLOAD,
  occurred_at: '2026-08-14T06:00:00.000Z',
  intent: 'auto',
  device_id: 'kiosk-1',
  attempts: 0,
  next_attempt_at: 0,
  enqueued_at: 1,
}

const PIN: QueuedScanRecord = {
  kind: 'pin',
  scan_id: ID_PIN,
  employee_code: 'E7QK2MXPR',
  pin_sealed: SEALED_PIN,
  occurred_at: '2026-08-14T07:00:00.000Z',
  intent: 'auto',
  device_id: 'kiosk-1',
  attempts: 0,
  next_attempt_at: 0,
  enqueued_at: 2,
}

const DISCARDED: DiscardedScanRecord = {
  kind: 'qr',
  scan_id: ID_QR,
  qr_payload: PAYLOAD,
  occurred_at: '2026-08-14T06:00:00.000Z',
  http_status: 400,
  problem_type: null,
  discarded_at: '2026-08-14T09:30:00.000Z',
  attempts: 0,
  next_attempt_at: 0,
}

let counter = 0
function names(): { db: string; keys: string } {
  counter += 1
  return { db: `kronoqr-b17-enc-${counter}`, keys: `kronoqr-b17-enc-key-${counter}` }
}

function cipherFor(keys: string, reasons: string[] = []) {
  return createQueueCipher({
    keyStore: createDexieQueueKeyStore(keys),
    onUnavailable: (reason) => reasons.push(reason),
  })
}

async function rawRows(db: string): Promise<string> {
  const handle = openKioskDatabase(db)
  const rows = JSON.stringify([
    ...(await handle.scans.toArray()),
    ...(await handle.discarded.toArray()),
  ])
  handle.close()
  return rows
}

afterEach(() => vi.restoreAllMocks())

describe('las filas se guardan cifradas', () => {
  it('ningun texto de la tarjeta, del codigo ni del PIN queda en disco, y se leen de vuelta', async () => {
    const n = names()
    const reasons: string[] = []
    const storage = createDexieQueueStorage(openKioskDatabase(n.db), {
      cipher: cipherFor(n.keys, reasons),
    })

    await storage.add(QR)
    await storage.add(PIN)
    await storage.putDiscarded(DISCARDED)

    const raw = await rawRows(n.db)
    expect(raw).not.toContain('FH1')
    expect(raw).not.toContain(PAYLOAD)
    expect(raw).not.toContain('E7QK2MXPR')
    expect(raw).not.toContain(SEALED_PIN)
    // Lo que gobierna el orden y el reintento sigue en claro.
    expect(raw).toContain(ID_QR)
    expect(raw).toContain('2026-08-14T06:00:00.000Z')

    expect(await storage.list(10)).toEqual([QR, PIN])
    expect(await storage.listDiscarded(10)).toEqual([DISCARDED])
    expect(reasons).toEqual([])
    storage.close()
  })

  it('reprogramar el reintento conserva el cifrado y el contenido', async () => {
    const n = names()
    const storage = createDexieQueueStorage(openKioskDatabase(n.db), {
      cipher: cipherFor(n.keys),
    })
    await storage.add(QR)

    await storage.reschedule([{ scan_id: ID_QR, attempts: 2, next_attempt_at: 99 }])

    expect(await rawRows(n.db)).not.toContain(PAYLOAD)
    expect((await storage.list(10))[0]).toMatchObject({
      qr_payload: PAYLOAD,
      attempts: 2,
      next_attempt_at: 99,
    })
    storage.close()
  })

  it('mover a descartados cifra la entrada y retira la fila de la cola', async () => {
    const n = names()
    const storage = createDexieQueueStorage(openKioskDatabase(n.db), {
      cipher: cipherFor(n.keys),
    })
    await storage.add(QR)

    await storage.discard(ID_QR, DISCARDED)

    expect(await rawRows(n.db)).not.toContain(PAYLOAD)
    expect(await storage.list(10)).toEqual([])
    expect(await storage.listDiscarded(10)).toEqual([DISCARDED])
    storage.close()
  })

  it('una fila cifrada no se puede pegar en otro scan_id (AAD)', async () => {
    const n = names()
    const storage = createDexieQueueStorage(openKioskDatabase(n.db), {
      cipher: cipherFor(n.keys),
    })
    await storage.add(QR)
    const handle = openKioskDatabase(n.db)
    const stored = await handle.scans.get(ID_QR)
    if (stored === undefined) throw new Error('fila ausente')
    await handle.scans.put({ ...stored, scan_id: ID_PIN })
    handle.close()

    const reasons: string[] = []
    const reader = createDexieQueueStorage(openKioskDatabase(n.db), {
      cipher: cipherFor(n.keys, reasons),
    })
    const rows = await reader.list(10)

    expect(rows.map((row) => row.scan_id)).toEqual([ID_QR])
    expect(reasons).toEqual(['decrypt_failed'])
    storage.close()
    reader.close()
  })
})

describe('migracion de las filas en claro', () => {
  it('se cifran al arrancar, se sustituye la version en claro y no se pierde ni una', async () => {
    const n = names()
    const legacy = createDexieQueueStorage(openKioskDatabase(n.db))
    await legacy.add(QR)
    await legacy.add(PIN)
    await legacy.putDiscarded(DISCARDED)
    legacy.close()
    expect(await rawRows(n.db)).toContain(PAYLOAD)

    const storage = createDexieQueueStorage(openKioskDatabase(n.db), {
      cipher: cipherFor(n.keys),
    })
    const rows = await storage.list(10)

    expect(rows).toEqual([QR, PIN])
    expect(await storage.listDiscarded(10)).toEqual([DISCARDED])
    const raw = await rawRows(n.db)
    expect(raw).not.toContain('FH1')
    expect(raw).not.toContain('E7QK2MXPR')
    expect(await storage.count()).toBe(2)
    storage.close()
  })

  it('una fila ya cifrada no se vuelve a cifrar', async () => {
    const n = names()
    const first = createDexieQueueStorage(openKioskDatabase(n.db), { cipher: cipherFor(n.keys) })
    await first.add(QR)
    const before = await rawRows(n.db)
    first.close()

    const second = createDexieQueueStorage(openKioskDatabase(n.db), { cipher: cipherFor(n.keys) })
    await second.list(10)

    expect(await rawRows(n.db)).toBe(before)
    second.close()
  })
})

describe('regla dura 19: el cifrado no puede perder un fichaje', () => {
  const failingStore: QueueKeyStore = {
    load: async () => null,
    saveIfAbsent: async () => {
      throw new DOMException('quota', 'QuotaExceededError')
    },
  }

  it('si la clave no se puede crear o guardar, la fila entra en claro y se avisa una vez, sin datos', async () => {
    const n = names()
    const reasons: string[] = []
    const storage = createDexieQueueStorage(openKioskDatabase(n.db), {
      cipher: createQueueCipher({
        keyStore: failingStore,
        onUnavailable: (reason) => reasons.push(reason),
      }),
    })

    expect(await storage.add(QR)).toBe(true)
    expect(await storage.add(PIN)).toBe(true)

    expect(await storage.list(10)).toEqual([QR, PIN])
    expect(await rawRows(n.db)).toContain(PAYLOAD)
    expect(reasons).toEqual(['key_unavailable'])
    storage.close()
  })

  it('sin WebCrypto, tambien en claro y avisando', async () => {
    const n = names()
    const reasons: string[] = []
    const storage = createDexieQueueStorage(openKioskDatabase(n.db), {
      cipher: createQueueCipher({
        subtle: null,
        keyStore: createDexieQueueKeyStore(n.keys),
        onUnavailable: (reason) => reasons.push(reason),
      }),
    })

    expect(await storage.add(QR)).toBe(true)

    expect(await storage.list(10)).toEqual([QR])
    expect(reasons).toEqual(['no_webcrypto'])
    storage.close()
  })

  it('con la clave perdida las filas cifradas NO se borran: se conservan, se cuentan y se avisa', async () => {
    const n = names()
    const first = createDexieQueueStorage(openKioskDatabase(n.db), { cipher: cipherFor(n.keys) })
    await first.add(QR)
    first.close()

    // La base de la clave desaparece: se genera otra distinta.
    const other = names()
    const unreadable: number[] = []
    const reasons: string[] = []
    const storage = createDexieQueueStorage(openKioskDatabase(n.db), {
      cipher: cipherFor(other.keys, reasons),
      onUnreadable: (count) => unreadable.push(count),
    })

    expect(await storage.list(10)).toEqual([])
    expect(await storage.count()).toBe(1)
    expect(unreadable).toEqual([1])
    expect(reasons).toEqual(['decrypt_failed'])
    storage.close()
  })

  it('las filas en claro siguen enviandose mientras el cifrado no esta disponible', async () => {
    const n = names()
    const legacy = createDexieQueueStorage(openKioskDatabase(n.db))
    await legacy.add(QR)
    legacy.close()

    const storage = createDexieQueueStorage(openKioskDatabase(n.db), {
      cipher: createQueueCipher({ keyStore: failingStore }),
    })

    expect(await storage.list(10)).toEqual([QR])
    storage.close()
  })
})
