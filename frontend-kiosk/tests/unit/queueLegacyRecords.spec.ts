// KT1 (RF-KI-03, RQ-05): una fila de una version anterior sin `kind` es un QR.
//
// `normalizeRecord()` (`dexieStorage.ts`) es lo unico que hace que un fichaje
// encolado ANTES de que existiera la via del PIN siga reconociendose tras una
// actualizacion de la tablet. Si se rompe, esos fichajes dejan de enviarse (el
// drenaje no sabria de que via son) y se pierde una jornada sin ningun aviso:
// regla dura 19. La prueba escribe la fila a mano en IndexedDB, como la dejo la
// version vieja, y comprueba que se lee, se reclama y SE ENVIA como QR.

import 'fake-indexeddb/auto'
import { describe, expect, it, vi } from 'vitest'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import { createSyncRunner } from '@/features/offline/application/syncRunner'
import {
  createDexieQueueStorage,
  openKioskDatabase,
} from '@/features/offline/infrastructure/dexieStorage'
import type { ApiClient } from '@/shared/api/client'
import type { ScanBatchRequest } from '@/shared/api/types'
import { fixedClock } from '@/shared/time/clock'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const CLOCK = fixedClock(new Date('2026-08-14T09:30:00.000Z'))
const LEGACY_ID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'
const PIN_ID = '0199f13a-7c22-7b41-9e88-0c4d5e6f7a81'

let counter = 0

/** Abre una base nueva y escribe en ella, tal cual, filas con la forma vieja. */
async function databaseWithLegacyRow() {
  counter += 1
  const db = openKioskDatabase(`kronoqr-legacy-${counter}`)
  // La forma de la v1 de la cola: sin `kind`, sin `employee_code`, sin `pin_sealed`.
  await db.scans.put({
    scan_id: LEGACY_ID,
    qr_payload: PAYLOAD,
    occurred_at: '2026-08-14T05:58:31.000Z',
    intent: 'auto',
    device_id: 'kiosk-1',
    attempts: 0,
    next_attempt_at: 0,
    enqueued_at: 1,
  } as never)
  return db
}

describe('migracion de filas sin `kind` (KT1, RF-KI-03, RQ-05)', () => {
  it('una fila anterior a la via del PIN se lee como QR', async () => {
    const storage = createDexieQueueStorage(await databaseWithLegacyRow())

    const [row] = await storage.list(10)

    expect(row?.kind).toBe('qr')
    expect(row).toMatchObject({ scan_id: LEGACY_ID, qr_payload: PAYLOAD, intent: 'auto' })
  })

  it('no toca lo que ya trae `kind`: una fila de PIN sigue siendo de PIN', async () => {
    const db = await databaseWithLegacyRow()
    await db.scans.put({
      kind: 'pin',
      scan_id: PIN_ID,
      employee_code: 'E7QK2MXPR',
      pin_sealed: 'c2VhbGVk',
      occurred_at: '2026-08-14T06:10:00.000Z',
      intent: 'auto',
      device_id: 'kiosk-1',
      attempts: 0,
      next_attempt_at: 0,
      enqueued_at: 2,
    })
    const storage = createDexieQueueStorage(db)

    const rows = await storage.list(10)

    expect(rows.map((row) => row.kind)).toEqual(['qr', 'pin'])
  })

  it('el drenaje ENVIA la fila vieja por la via de tarjeta y la saca al confirmarse', async () => {
    const db = await databaseWithLegacyRow()
    const queue = createScanQueue({
      openStorage: () => createDexieQueueStorage(db),
      clock: CLOCK,
    })
    const batches: ScanBatchRequest[] = []
    const pinCalls: string[] = []
    const api = {
      syncScanBatch: vi.fn(async (request: ScanBatchRequest) => {
        batches.push(request)
        return {
          outcome: 'ok' as const,
          data: {
            results: request.scans.map((item) => ({
              scan_id: item.scan_id,
              status: 200 as const,
              outcome: {
                scan_id: item.scan_id,
                action: 'clock_in' as const,
                employee_display_name: 'Lucia G.',
                work_date: '2026-08-14',
                occurred_at: item.occurred_at,
                recorded_at: '2026-08-14T09:30:00.000Z',
                worked_minutes: 0,
              },
            })),
          },
        }
      }),
      recordPinScan: vi.fn(async (request: { scan_id: string }) => {
        pinCalls.push(request.scan_id)
        return { outcome: 'failed' as const, cause: 'network' as const }
      }),
      recordScan: vi.fn(),
    } as unknown as ApiClient
    const runner = createSyncRunner({
      api,
      queue,
      clock: CLOCK,
      isOnline: () => true,
      setTimer: () => 0,
      clearTimer: () => undefined,
    })

    await queue.refresh()
    await runner.drain({ ignoreSchedule: true })

    expect(pinCalls).toEqual([])
    expect(batches).toHaveLength(1)
    expect(batches[0]?.scans[0]).toMatchObject({
      scan_id: LEGACY_ID,
      qr_payload: PAYLOAD,
      occurred_at: '2026-08-14T05:58:31.000Z',
    })
    expect(queue.stats().size).toBe(0)
  })
})
