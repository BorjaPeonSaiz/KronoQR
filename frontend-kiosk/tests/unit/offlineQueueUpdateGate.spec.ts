// Cableado de la puerta de actualizacion sobre la cola offline (RF-KI-07,
// tarea 3.12): cada escaneo que pasa por `submission.submit` dice «hubo
// alguien delante de la camara» (`storeLastScanAt`), y `canUpdateNow` lee esa
// senal junto con la ventana y los minutos de silencio cacheados del ultimo
// latido. La puerta en si (limites exactos) se prueba en `updateWindow.spec.ts`;
// esto solo comprueba que el CONTROLADOR la alimenta y la consulta bien.

import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ApiClient } from '@/shared/api/client'
import {
  disposeOfflineQueue,
  createOfflineQueueController,
} from '@/features/offline/useOfflineQueue'
import {
  readLastScanAt,
  storeUpdateQuietMinutes,
  storeUpdateWindow,
} from '@/shared/telemetry/deviceIdentity'
import type { ErrorReporter } from '@/shared/telemetry/errorReporter'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'

/** Todo falla por "offline": nada de esto es lo que se prueba aqui. */
function offlineApi(): ApiClient {
  return {
    recordScan: vi.fn(async () => ({ outcome: 'failed', cause: 'offline' }) as const),
    recordPinScan: vi.fn(async () => ({ outcome: 'failed', cause: 'offline' }) as const),
    syncScanBatch: vi.fn(async () => ({ outcome: 'failed', cause: 'offline' }) as const),
    fetchRoster: vi.fn(async () => ({ outcome: 'failed', cause: 'offline' }) as const),
    sendHeartbeat: vi.fn(async () => ({ outcome: 'failed', cause: 'offline' }) as const),
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    fetchBranding: vi.fn(async () => ({ outcome: 'failed', cause: 'offline' }) as const),
  }
}

function silentReporter(): ErrorReporter {
  return { report: vi.fn(), pending: vi.fn(() => []), acknowledge: vi.fn(), size: vi.fn(() => 0) }
}

const KEYS = [
  'kronoqr.kiosk.last_scan_at',
  'kronoqr.kiosk.update_window',
  'kronoqr.kiosk.update_quiet_minutes',
]

beforeEach(async () => {
  await disposeOfflineQueue()
  for (const key of KEYS) localStorage.removeItem(key)
})

afterEach(async () => {
  await disposeOfflineQueue()
  for (const key of KEYS) localStorage.removeItem(key)
})

describe('la cola alimenta la puerta de actualizacion (RF-KI-07, tarea 3.12)', () => {
  it('cada escaneo que pasa por `submission.submit` anota su `occurred_at` como ultimo escaneo', async () => {
    const controller = createOfflineQueueController({
      api: offlineApi(),
      reporter: silentReporter(),
      databaseName: 'kronoqr-update-gate-1',
    })

    expect(readLastScanAt()).toBeNull()

    await controller.submission.submit({
      kind: 'qr',
      scan_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
      qr_payload: PAYLOAD,
      occurred_at: '2026-08-14T05:58:31.000Z',
      intent: 'auto',
      device_id: 'kiosk-1',
    })

    expect(readLastScanAt()).toBe('2026-08-14T05:58:31.000Z')
  })

  it('`canUpdateNow` no se aplica con la cola pendiente, aunque la ventana este abierta', async () => {
    storeUpdateWindow({ start: '00:00', end: '23:59' })
    storeUpdateQuietMinutes(0)

    const controller = createOfflineQueueController({
      api: offlineApi(),
      reporter: silentReporter(),
      databaseName: 'kronoqr-update-gate-2',
    })

    await controller.submission.submit({
      kind: 'qr',
      scan_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91',
      qr_payload: PAYLOAD,
      occurred_at: '2026-08-14T05:58:31.000Z',
      intent: 'auto',
      device_id: 'kiosk-1',
    })

    // Sin red (`offlineApi`), el escaneo se queda encolado: la cola no esta vacia.
    await vi.waitFor(() => expect(controller.stats().size).toBe(1))
    expect(controller.canUpdateNow(new Date('2026-08-14T12:00:00.000Z'))).toBe(false)
  })
})
