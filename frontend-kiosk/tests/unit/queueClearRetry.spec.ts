// @vitest-environment jsdom
// KT5, observacion 5: un `clear()` que no consigue vaciar el disco (filas con
// `qr_payload` en claro) se senala sin datos personales y se reintenta al arrancar,
// solo mientras la tablet siga sin emparejar.

import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { QueuedScan } from '@/features/scan/application/ports'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import {
  createOfflineQueueController,
  disposeOfflineQueue,
} from '@/features/offline/useOfflineQueue'
import {
  createDexieQueueStorage,
  openKioskDatabase,
} from '@/features/offline/infrastructure/dexieStorage'
import { readQueueClearPending, storeQueueClearPending } from '@/shared/device/deviceSettings'
import type { ApiClient } from '@/shared/api/client'
import type { ErrorReporter } from '@/shared/telemetry/errorReporter'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const ID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8e01'

function scan(): QueuedScan {
  return {
    kind: 'qr',
    scan_id: ID,
    qr_payload: PAYLOAD,
    occurred_at: '2026-08-14T06:00:00.000Z',
    intent: 'auto',
    device_id: 'kiosk-1',
  }
}

let counter = 0
function freshName(): string {
  counter += 1
  return `kronoqr-b17-clear-${counter}`
}

function offlineApi(): ApiClient {
  const offline = vi.fn(async () => ({ outcome: 'failed', cause: 'offline' }) as const)
  return {
    recordScan: offline,
    recordPinScan: offline,
    syncScanBatch: offline,
    fetchRoster: offline,
    sendHeartbeat: offline,
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    reportDiscardedScans: offline,
    fetchBranding: offline,
  } as unknown as ApiClient
}

function recordingReporter(): ErrorReporter & { codes: string[] } {
  const codes: string[] = []
  return {
    codes,
    report: (code) => {
      codes.push(code)
    },
    pending: () => [],
    acknowledge: () => undefined,
    size: () => 0,
  }
}

beforeEach(() => localStorage.clear())
afterEach(async () => {
  await disposeOfflineQueue()
  localStorage.clear()
})

describe('onClearOutcome', () => {
  it('true cuando el disco queda vacio', async () => {
    const name = freshName()
    const outcomes: boolean[] = []
    const queue = createScanQueue({
      openStorage: () => createDexieQueueStorage(openKioskDatabase(name)),
      sleep: async () => undefined,
      onClearOutcome: (complete) => outcomes.push(complete),
    })
    await queue.enqueue(scan())

    await queue.clear()

    expect(outcomes).toEqual([true])
    expect(await openKioskDatabase(name).scans.count()).toBe(0)
  })

  it('false cuando el disco no se deja vaciar: las filas siguen y no se publica cola vacia', async () => {
    const name = freshName()
    const outcomes: boolean[] = []
    let broken = false
    const queue = createScanQueue({
      openStorage: () => {
        const real = createDexieQueueStorage(openKioskDatabase(name))
        return {
          ...real,
          clear: async () => {
            if (broken) throw new DOMException('quota', 'QuotaExceededError')
            return real.clear()
          },
        }
      },
      sleep: async () => undefined,
      onClearOutcome: (complete) => outcomes.push(complete),
    })
    await queue.enqueue(scan())
    broken = true

    await queue.clear()

    expect(outcomes).toEqual([false])
    expect(await openKioskDatabase(name).scans.count()).toBe(1)
  })
})

describe('reintento al arrancar', () => {
  async function seededDisk(): Promise<string> {
    const name = freshName()
    const seed = createDexieQueueStorage(openKioskDatabase(name))
    await seed.add({
      kind: 'qr',
      scan_id: ID,
      qr_payload: PAYLOAD,
      occurred_at: '2026-08-14T06:00:00.000Z',
      intent: 'auto',
      device_id: 'kiosk-1',
      attempts: 0,
      next_attempt_at: Number.MAX_SAFE_INTEGER,
      enqueued_at: 1,
    })
    seed.close()
    return name
  }

  it('con el vaciado pendiente y la tablet sin emparejar, vacia el disco y retira el indicador', async () => {
    const name = await seededDisk()
    storeQueueClearPending(true)

    createOfflineQueueController({
      api: offlineApi(),
      reporter: recordingReporter(),
      databaseName: name,
    })

    await vi.waitFor(async () => expect(await openKioskDatabase(name).scans.count()).toBe(0))
    await vi.waitFor(() => expect(readQueueClearPending()).toBe(false))
  })

  it('con un token nuevo NUNCA toca la cola: son fichajes legitimos', async () => {
    const name = await seededDisk()
    storeQueueClearPending(true)
    localStorage.setItem('kronoqr.kiosk.device_token', 'token-nuevo')

    createOfflineQueueController({
      api: offlineApi(),
      reporter: recordingReporter(),
      databaseName: name,
    })

    await vi.waitFor(() => expect(readQueueClearPending()).toBe(false))
    expect(await openKioskDatabase(name).scans.count()).toBe(1)
  })

  it('sin indicador pendiente no vacia nada', async () => {
    const name = await seededDisk()

    createOfflineQueueController({
      api: offlineApi(),
      reporter: recordingReporter(),
      databaseName: name,
    })

    await vi.waitFor(async () => expect(await openKioskDatabase(name).scans.count()).toBe(1))
  })
})
