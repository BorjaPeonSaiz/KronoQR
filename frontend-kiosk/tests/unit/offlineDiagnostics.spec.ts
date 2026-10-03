// Los diagnosticos de la cola viajan en el latido (`client_errors`) y acaban en
// `error_events`, que va al fabricante dentro del paquete de diagnostico (regla
// dura 21, ADR-020): SIN `scan_id`, SIN payload, SIN codigo de empleado.
//
// RN-22 / ADR-047: `sync.item_discarded` y `sync.discard_report_failed` tienen
// codigo propio, y `kiosk.offline.item_not_processed` queda SOLO para «conservado
// para reintento» (503). El mapeo viejo `sync.item_invalid -> item_not_processed`
// desaparece: un fichaje sacado de la cola ya no se disfraza de «no procesado».

import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createOfflineQueueController,
  type OfflineQueueController,
} from '@/features/offline/useOfflineQueue'
import type { ApiClient } from '@/shared/api/client'
import type { DiscardedScanReportBatch, ScanBatchRequest } from '@/shared/api/types'
import { createErrorReporter } from '@/shared/telemetry/errorReporter'
import type { ErrorReporter } from '@/shared/telemetry/errorReporter'
import { resetKioskDatabase } from './support/resetKioskDatabase'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const SCAN_ID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b01'
const OTHER_ID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b02'

let controller: OfflineQueueController | null = null
let reporter: ErrorReporter

function api(overrides: Partial<ApiClient>): ApiClient {
  return {
    recordScan: vi.fn(),
    recordPinScan: vi.fn(),
    syncScanBatch: vi.fn(),
    reportDiscardedScans: vi.fn(),
    fetchRoster: vi.fn(async () => ({ outcome: 'failed' as const, cause: 'offline' as const })),
    sendHeartbeat: vi.fn(),
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    fetchBranding: vi.fn(),
    ...overrides,
  }
}

/**
 * El drenaje de arranque del controlador puede llevarse el escaneo por el lote
 * antes que el camino rapido: las dos vias dan el MISMO desenlace.
 */
const invalidBatch = vi.fn(async () => ({
  outcome: 'failed' as const,
  cause: 'invalid' as const,
  httpStatus: 400,
  problemType: null,
}))

beforeEach(async () => {
  await resetKioskDatabase()
  reporter = createErrorReporter({ appVersion: '2.2.0', deviceId: 'kiosk-1' })
})

afterEach(async () => {
  await controller?.dispose()
  controller = null
})

describe('diagnosticos de la cola (RN-22, RN-21, regla dura 21)', () => {
  it('un 400 se reporta como `item_discarded` con http_status, kind y problem_type, sin scan_id', async () => {
    const reports: DiscardedScanReportBatch[] = []
    controller = createOfflineQueueController({
      reporter,
      api: api({
        syncScanBatch: invalidBatch,
        recordScan: vi.fn(async () => ({
          outcome: 'failed' as const,
          cause: 'invalid' as const,
          httpStatus: 400,
          problemType: 'urn:kronoqr:problem:invalid-request',
        })),
        reportDiscardedScans: vi.fn(async (body: DiscardedScanReportBatch) => {
          reports.push(body)
          return { outcome: 'ok' as const, data: { acknowledged: [SCAN_ID] } }
        }),
      }),
    })

    await controller.submission.submit({
      kind: 'qr',
      scan_id: SCAN_ID,
      occurred_at: '2026-08-14T08:00:00.000Z',
      intent: 'auto',
      device_id: 'kiosk-1',
      qr_payload: PAYLOAD,
    })

    await vi.waitFor(() =>
      expect(reporter.pending().map((event) => event.code)).toContain(
        'kiosk.offline.item_discarded',
      ),
    )
    const events = reporter.pending()
    const discarded = events.filter((event) => event.code === 'kiosk.offline.item_discarded')
    expect(discarded).toHaveLength(1)
    expect(discarded[0]?.context).toMatchObject({
      http_status: 400,
      kind: 'qr',
      problem_type: 'urn:kronoqr:problem:invalid-request',
    })
    // El mapeo viejo desaparecio: un descarte NO es «no procesado».
    expect(events.map((event) => event.code)).not.toContain('kiosk.offline.item_not_processed')
    // Regla dura 21: ni el scan_id ni el payload viajan en el diagnostico.
    const serialized = JSON.stringify(events)
    expect(serialized).not.toContain(SCAN_ID)
    expect(serialized).not.toContain(PAYLOAD)
    // El aviso al servidor SI lleva ambos: es lo que permite revisarlo.
    await vi.waitFor(() => expect(reports).toHaveLength(1))
    expect(reports[0]?.reports[0]).toMatchObject({ scan_id: SCAN_ID, qr_payload: PAYLOAD })
  })

  it('un aviso que no sale se reporta como `discard_report_failed` y el latido lo cuenta en `unreported_discards`', async () => {
    controller = createOfflineQueueController({
      reporter,
      api: api({
        syncScanBatch: invalidBatch,
        recordScan: vi.fn(async () => ({
          outcome: 'failed' as const,
          cause: 'invalid' as const,
          httpStatus: 400,
          problemType: null,
        })),
        reportDiscardedScans: vi.fn(async () => ({
          outcome: 'failed' as const,
          cause: 'server' as const,
          httpStatus: 503,
        })),
      }),
    })

    await controller.submission.submit({
      kind: 'qr',
      scan_id: SCAN_ID,
      occurred_at: '2026-08-14T08:00:00.000Z',
      intent: 'auto',
      device_id: 'kiosk-1',
      qr_payload: PAYLOAD,
    })

    await vi.waitFor(() =>
      expect(reporter.pending().map((event) => event.code)).toContain(
        'kiosk.offline.discard_report_failed',
      ),
    )
    await vi.waitFor(() => expect(controller?.telemetry('2.2.0').unreportedDiscards).toBe(1))
    const telemetry = controller.telemetry('2.2.0')
    expect(telemetry.unreportedDiscards).toBe(1)
    expect(telemetry.queueStorage).toBe('durable')
    expect(telemetry.pendingQueueSize).toBe(0)
    expect(JSON.stringify(reporter.pending())).not.toContain(SCAN_ID)
  })

  it('un 503 del lote (conservado para reintento) SI es `item_not_processed`', async () => {
    controller = createOfflineQueueController({
      reporter,
      api: api({
        // Hay otro delante: el camino es el del lote.
        syncScanBatch: vi.fn(async (request: ScanBatchRequest) => ({
          outcome: 'ok' as const,
          data: {
            results: request.scans.map((item) => ({
              scan_id: item.scan_id,
              status: 503 as const,
              outcome: {
                type: 'urn:kronoqr:problem:scan-not-processed' as const,
                title: 'Escaneo no procesado' as const,
                status: 503 as const,
                detail: 'El escaneo no se ha podido procesar. Reintenta mas tarde.' as const,
                scan_id: item.scan_id,
              },
            })),
          },
        })),
      }),
    })

    for (const id of [SCAN_ID, OTHER_ID]) {
      await controller.submission.submit({
        kind: 'qr',
        scan_id: id,
        occurred_at: id === SCAN_ID ? '2026-08-14T08:00:00.000Z' : '2026-08-14T09:00:00.000Z',
        intent: 'auto',
        device_id: 'kiosk-1',
        qr_payload: PAYLOAD,
      })
    }

    await vi.waitFor(() =>
      expect(reporter.pending().map((event) => event.code)).toContain(
        'kiosk.offline.item_not_processed',
      ),
    )
    expect(reporter.pending().map((event) => event.code)).not.toContain(
      'kiosk.offline.item_discarded',
    )
  })
})
