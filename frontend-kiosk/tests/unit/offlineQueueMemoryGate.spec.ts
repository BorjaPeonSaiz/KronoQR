// Puerta de actualizacion con la cola en MEMORIA (ADR-047, RF-KI-07).
//
// Con IndexedDB roto la cola cae a memoria y lo que haya en disco no se ve. El
// modo urgente no puede abrirse a cualquier hora en ese estado, pero tampoco se
// queda sin salida: se evalua como NO urgente, con su ventana, para que la
// tablet pueda recibir dentro de la franja la version que arregle el problema.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ApiClient } from '@/shared/api/client'
import { createOfflineQueueController } from '@/features/offline/useOfflineQueue'
import { storeUpdateQuietMinutes } from '@/shared/telemetry/deviceIdentity'
import type { ErrorReporter } from '@/shared/telemetry/errorReporter'

vi.mock('@/features/offline/infrastructure/dexieStorage', () => ({
  openKioskDatabase: () => ({}),
  createDexieQueueStorage: () => {
    throw new Error('IndexedDB no disponible')
  },
}))

function offlineApi(): ApiClient {
  const failed = async () => ({ outcome: 'failed', cause: 'offline' }) as const
  return {
    recordScan: vi.fn(failed),
    recordPinScan: vi.fn(failed),
    syncScanBatch: vi.fn(failed),
    fetchRoster: vi.fn(failed),
    sendHeartbeat: vi.fn(failed),
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    reportDiscardedScans: vi.fn(),
    fetchBranding: vi.fn(failed),
  }
}

const reporter: ErrorReporter = {
  report: vi.fn(),
  pending: vi.fn(() => []),
  acknowledge: vi.fn(),
  size: vi.fn(() => 0),
}

const KEYS = ['kronoqr.kiosk.last_scan_at', 'kronoqr.kiosk.update_window']

beforeEach(() => {
  for (const key of KEYS) localStorage.removeItem(key)
})

afterEach(() => {
  for (const key of KEYS) localStorage.removeItem(key)
})

describe('urgente con la cola en memoria (R6)', () => {
  it('se evalua como no urgente: con ventana', async () => {
    storeUpdateQuietMinutes(0)
    const controller = createOfflineQueueController({
      api: offlineApi(),
      reporter,
      databaseName: 'kronoqr-memory-gate',
    })
    await vi.waitFor(() => expect(controller.queueKnown()).toBe(true))
    expect(controller.stats().durable).toBe(false)

    // Fuera de la ventana de serie (12:00): ni la urgencia la abre.
    expect(controller.canUpdateNow(new Date(2026, 7, 14, 12, 0, 0), true)).toBe(false)
    // Dentro (04:00) y con la cola vacia: puede recibir la version que lo arregle.
    expect(controller.canUpdateNow(new Date(2026, 7, 14, 4, 0, 0), true)).toBe(true)

    await controller.dispose()
  })
})
