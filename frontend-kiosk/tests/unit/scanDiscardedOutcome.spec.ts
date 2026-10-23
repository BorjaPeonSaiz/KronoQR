// ADR-061 (17.9, lado quiosco) y P7: un `422 scan-discarded` saca el elemento de la
// cola SIN aviso propio (el servidor ya abrio la incidencia), y un `422` del lote que
// no es `scan-rejected` ni `scan-discarded` va por la ruta RN-22 con su aviso.

import { describe, expect, it, vi } from 'vitest'
import type { QueuedScan } from '@/features/scan/application/ports'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import { createSyncRunner } from '@/features/offline/application/syncRunner'
import { createMemoryQueueStorage } from '@/features/offline/infrastructure/queueStorage'
import { createApiClient } from '@/shared/api/client'
import type { ApiClient } from '@/shared/api/client'
import type { ScanBatchRequest } from '@/shared/api/types'
import { fixedClock } from '@/shared/time/clock'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const CLOCK = fixedClock(new Date('2026-08-14T09:30:00.000Z'))
const A = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8d01'
const B = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8d02'

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

const DISCARDED = (scanId: string) =>
  ({
    type: 'urn:kronoqr:problem:scan-discarded',
    title: 'Escaneo descartado',
    status: 422,
    detail: 'El escaneo se ha descartado.',
    scan_id: scanId,
  }) as const

function bench(entry: (request: ScanBatchRequest) => unknown) {
  const reports: unknown[] = []
  const diagnostics: string[] = []
  const api = {
    syncScanBatch: vi.fn(async (request: ScanBatchRequest) => ({
      outcome: 'ok' as const,
      data: { results: entry(request) },
    })),
    reportDiscardedScans: vi.fn(async (body: { reports: Array<{ scan_id: string }> }) => {
      reports.push(body)
      return { outcome: 'ok' as const, data: { acknowledged: body.reports.map((r) => r.scan_id) } }
    }),
    recordScan: vi.fn(),
    recordPinScan: vi.fn(),
  } as unknown as ApiClient
  const queue = createScanQueue({ openStorage: createMemoryQueueStorage, clock: CLOCK })
  const runner = createSyncRunner({
    api,
    queue,
    clock: CLOCK,
    isOnline: () => true,
    onDiagnostic: (code) => diagnostics.push(code),
    setTimer: () => 0,
    clearTimer: () => undefined,
  })
  return { api, queue, runner, reports, diagnostics }
}

describe('ADR-061 — `scan-discarded` en el lote', () => {
  it('saca el elemento de la cola, sin aviso ni descartado propio, y el resto del lote sigue', async () => {
    const b = bench((request) =>
      request.scans.map((item) =>
        item.scan_id === A
          ? { scan_id: item.scan_id, status: 422, outcome: DISCARDED(item.scan_id) }
          : {
              scan_id: item.scan_id,
              status: 200,
              outcome: {
                scan_id: item.scan_id,
                action: 'clock_in',
                employee_display_name: 'Lucia G.',
                work_date: '2026-08-14',
                occurred_at: item.occurred_at,
                recorded_at: '2026-08-14T09:30:00.000Z',
                worked_minutes: 0,
              },
            },
      ),
    )
    await b.queue.enqueue(scan(A, '2026-08-14T08:00:00.000Z'))
    await b.queue.enqueue(scan(B, '2026-08-14T08:05:00.000Z'))

    await b.runner.drain({ ignoreSchedule: true })

    expect(b.queue.stats().size).toBe(0)
    expect(b.queue.stats().unreportedDiscards).toBe(0)
    expect(b.reports).toEqual([])
    expect(b.diagnostics).not.toContain('sync.item_discarded')
  })
})

describe('P7 — un `422` del lote que no es estandar va por RN-22', () => {
  it('se mueve a descartados, se avisa al servidor y la cola sigue', async () => {
    const b = bench((request) =>
      request.scans.map((item) => ({
        scan_id: item.scan_id,
        status: 422,
        outcome: {
          type: 'urn:kronoqr:problem:algo-nuevo',
          title: 'x',
          status: 422,
          detail: 'x',
          scan_id: item.scan_id,
        },
      })),
    )
    await b.queue.enqueue(scan(A, '2026-08-14T08:00:00.000Z'))

    await b.runner.drain({ ignoreSchedule: true })

    expect(b.queue.stats().size).toBe(0)
    expect(b.diagnostics).toContain('sync.item_discarded')
    expect(b.reports).toHaveLength(1)
    expect(b.reports[0]).toMatchObject({
      reports: [
        {
          scan_id: A,
          http_status: 422,
          problem_type: 'urn:kronoqr:problem:algo-nuevo',
        },
      ],
    })
    // Acusado el aviso, no queda nada.
    expect(b.queue.stats().unreportedDiscards).toBe(0)
  })

  it('`scan-rejected` sigue saliendo sin aviso', async () => {
    const b = bench((request) =>
      request.scans.map((item) => ({
        scan_id: item.scan_id,
        status: 422,
        outcome: {
          type: 'urn:kronoqr:problem:scan-rejected',
          title: 'x',
          status: 422,
          detail: 'x',
          scan_id: item.scan_id,
        },
      })),
    )
    await b.queue.enqueue(scan(A, '2026-08-14T08:00:00.000Z'))

    await b.runner.drain({ ignoreSchedule: true })

    expect(b.queue.stats().size).toBe(0)
    expect(b.reports).toEqual([])
  })
})

describe('ADR-061 — `scan-discarded` en /scan y /scan/pin', () => {
  function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/problem+json' },
    })
  }

  it('el cliente lo trata como desenlace terminal (`rejected`), en las dos rutas', async () => {
    const client = createApiClient({
      fetchImpl: (async () => jsonResponse(422, DISCARDED(A))) as unknown as typeof fetch,
    })
    const qr = await client.recordScan({
      scan_id: A,
      occurred_at: '2026-08-14T08:00:00.000Z',
      qr_payload: PAYLOAD,
      intent: 'auto',
    })
    const pinResult = await client.recordPinScan({
      scan_id: A,
      occurred_at: '2026-08-14T08:00:00.000Z',
      employee_code: 'E7QK2MXPR',
      pin_sealed: 'c2VhbGVk',
      intent: 'auto',
    })

    expect(qr.outcome).toBe('rejected')
    expect(pinResult.outcome).toBe('rejected')
  })

  it('el drenaje de un fichaje por PIN lo saca de la cola sin avisar', async () => {
    const b = bench(() => [])
    vi.mocked(b.api.recordPinScan).mockResolvedValue({
      outcome: 'rejected',
      problem: DISCARDED(A),
    })
    await b.queue.enqueue({
      kind: 'pin',
      scan_id: A,
      employee_code: 'E7QK2MXPR',
      pin_sealed: 'c2VhbGVk',
      occurred_at: '2026-08-14T08:00:00.000Z',
      intent: 'auto',
      device_id: 'kiosk-1',
    })

    await b.runner.drain({ ignoreSchedule: true })

    expect(b.queue.stats().size).toBe(0)
    expect(b.reports).toEqual([])
    expect(b.diagnostics).not.toContain('sync.item_discarded')
  })
})
