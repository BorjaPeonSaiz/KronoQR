// Las SEIS GARANTIAS del protocolo del §6, una por una.
//
// Cada bloque de este fichero se llama como la garantia que comprueba. Si
// alguna deja de cumplirse, lo que falla dice exactamente cual.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { QueuedScan } from '@/features/scan/application/ports'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import type { ScanQueue } from '@/features/offline/application/scanQueue'
import { createSyncRunner } from '@/features/offline/application/syncRunner'
import type { SyncDiagnostic } from '@/features/offline/application/syncRunner'
import { createMemoryQueueStorage } from '@/features/offline/infrastructure/queueStorage'
import type { ApiClient, ApiResult } from '@/shared/api/client'
import type { ScanBatchRequest, ScanBatchResponse, ScanOk } from '@/shared/api/types'
import { fixedClock } from '@/shared/time/clock'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const CLOCK = fixedClock(new Date('2026-08-14T09:30:00.000Z'))

function scan(scanId: string, occurredAt: string): QueuedScan {
  return {
    scan_id: scanId,
    qr_payload: PAYLOAD,
    occurred_at: occurredAt,
    intent: 'auto',
    device_id: 'kiosk-1',
  }
}

function accepted(scanId: string, occurredAt: string, action: 'clock_in' | 'clock_out'): ScanOk {
  return {
    scan_id: scanId,
    action,
    employee_display_name: 'Lucia G.',
    work_date: occurredAt.slice(0, 10),
    occurred_at: occurredAt,
    // El servidor lo recibe una hora y media despues. Esa es la gracia.
    recorded_at: '2026-08-14T09:30:00.000Z',
    worked_minutes: action === 'clock_out' ? 480 : 0,
  }
}

interface Harness {
  readonly api: ApiClient
  readonly batches: ScanBatchRequest[]
  readonly batchKeys: string[]
  readonly singles: string[]
  readonly diagnostics: SyncDiagnostic[]
  readonly queue: ScanQueue
}

interface HarnessOptions {
  readonly onBatch?: (request: ScanBatchRequest) => ApiResult<ScanBatchResponse>
  readonly onSingle?: (scanId: string) => ApiResult<ScanOk>
}

function harness(options: HarnessOptions = {}): Harness {
  const batches: ScanBatchRequest[] = []
  const batchKeys: string[] = []
  const singles: string[] = []
  const diagnostics: SyncDiagnostic[] = []

  const api: ApiClient = {
    recordScan: vi.fn(async (request) => {
      singles.push(request.scan_id)
      return (
        options.onSingle?.(request.scan_id) ?? {
          outcome: 'ok' as const,
          data: accepted(request.scan_id, request.occurred_at, 'clock_in'),
        }
      )
    }),
    syncScanBatch: vi.fn(async (request: ScanBatchRequest, key: string) => {
      batches.push(request)
      batchKeys.push(key)
      return (
        options.onBatch?.(request) ?? {
          outcome: 'ok' as const,
          data: {
            results: request.scans.map((item) => ({
              scan_id: item.scan_id,
              status: 200 as const,
              outcome: accepted(item.scan_id, item.occurred_at, 'clock_in'),
            })),
          },
        }
      )
    }),
    fetchRoster: vi.fn(),
    sendHeartbeat: vi.fn(),
  }

  const queue = createScanQueue({ openStorage: createMemoryQueueStorage, clock: CLOCK })
  return { api, batches, batchKeys, singles, diagnostics, queue }
}

function runnerFor(
  bench: Harness,
  overrides: { readonly online?: boolean } = {},
): ReturnType<typeof createSyncRunner> {
  return createSyncRunner({
    api: bench.api,
    queue: bench.queue,
    clock: CLOCK,
    isOnline: () => overrides.online !== false,
    onDiagnostic: (code) => bench.diagnostics.push(code),
    // Sin temporizadores reales: cada prueba llama a `drain()` cuando quiere.
    setTimer: () => 0,
    clearTimer: () => undefined,
  })
}

describe('garantia 1 — exactamente una vez', () => {
  it('el `scan_id` del encolado es el que viaja, y viaja tal cual', async () => {
    const bench = harness()
    const runner = runnerFor(bench)
    // Sin `start()`: cada prueba decide cuando drena, para que no haya carreras.

    await runner.submit(scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'))

    expect(bench.singles).toEqual(['0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'])
  })

  it('reintentar tras un fallo reenvia el MISMO `scan_id`, no uno nuevo', async () => {
    let attempt = 0
    const bench = harness({
      onBatch: () => {
        attempt += 1
        return attempt === 1
          ? { outcome: 'failed', cause: 'network' }
          : {
              outcome: 'ok',
              data: {
                results: [
                  {
                    scan_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
                    status: 200,
                    outcome: accepted(
                      '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
                      '2026-08-14T08:00:00.000Z',
                      'clock_in',
                    ),
                  },
                ],
              },
            }
      },
    })
    const runner = runnerFor(bench)

    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    // Dos escaneos en cola fuerzan el camino de lote.
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T08:05:00.000Z'),
    )

    await runner.drain({ ignoreSchedule: true })
    await runner.drain({ ignoreSchedule: true })

    const sent = bench.batches.map((batch) => batch.scans.map((item) => item.scan_id))
    expect(sent[0]?.[0]).toBe('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
    expect(sent[1]?.[0]).toBe('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
  })

  it('la clave de idempotencia del lote NO es un `scan_id`', async () => {
    const bench = harness()
    const runner = runnerFor(bench)

    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T08:05:00.000Z'),
    )
    await runner.drain({ ignoreSchedule: true })

    const key = bench.batchKeys[0] ?? ''
    expect(key).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    expect(key).not.toBe('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
    expect(key).not.toBe('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81')
  })
})

describe('garantia 2 — hora real preservada', () => {
  it('el `occurred_at` que viaja es el del escaneo, no el del envio', async () => {
    const bench = harness()
    const runner = runnerFor(bench)

    // Fichado a las 08:00 sin red; se sincroniza a las 09:30 (reloj del arnes).
    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T08:05:00.000Z'),
    )
    await runner.drain({ ignoreSchedule: true })

    expect(bench.batches[0]?.scans[0]?.occurred_at).toBe('2026-08-14T08:00:00.000Z')
  })
})

describe('garantia 3 — orden correcto', () => {
  it('un lote desordenado sale ordenado por `occurred_at`', async () => {
    const bench = harness()
    const runner = runnerFor(bench)

    // La salida se encola primero; la entrada, despues.
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T14:03:12.000Z'),
    )
    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31.000Z'),
    )

    await runner.drain({ ignoreSchedule: true })

    expect(bench.batches[0]?.scans.map((item) => item.occurred_at)).toEqual([
      '2026-08-14T05:58:31.000Z',
      '2026-08-14T14:03:12.000Z',
    ])
  })

  it('un escaneo nuevo NO adelanta a lo que ya estaba encolado', async () => {
    const bench = harness()
    const runner = runnerFor(bench)
    // Sin `start()`: cada prueba decide cuando drena, para que no haya carreras.

    // Una entrada atrapada de las 08:00.
    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    // Vuelve la red y alguien ficha la salida: si se enviara sola por
    // `POST /scan`, el servidor veria una salida sin turno abierto.
    const result = await runner.submit(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T16:00:00.000Z'),
    )

    expect(result.kind).toBe('deferred')
    expect(bench.singles).toHaveLength(0)
  })

  it('con la cola vacia si usa el envio individual, que trae el total del dia', async () => {
    const bench = harness()
    const runner = runnerFor(bench)
    // Sin `start()`: cada prueba decide cuando drena, para que no haya carreras.

    const result = await runner.submit(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )

    expect(result.kind).toBe('accepted')
    expect(bench.batches).toHaveLength(0)
    expect(bench.queue.stats().size).toBe(0)
  })
})

describe('garantia 4 — desfase controlado', () => {
  it('un fichaje con horas de retraso se acepta y NO se descarta', async () => {
    const bench = harness()
    const runner = runnerFor(bench)

    // Tres dias sin red. El registro legal sigue siendo el `occurred_at`.
    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-11T06:00:00.000Z'),
    )
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-11T14:00:00.000Z'),
    )
    await runner.drain({ ignoreSchedule: true })

    expect(bench.batches[0]?.scans[0]?.occurred_at).toBe('2026-08-11T06:00:00.000Z')
    expect(bench.queue.stats().size).toBe(0)
  })
})

describe('garantia 5 — no se pierde nada', () => {
  it('un `503` elemento a elemento conserva ESE fichaje y confirma los demas', async () => {
    const bench = harness({
      onBatch: (request) => ({
        outcome: 'ok',
        data: {
          results: request.scans.map((item, index) =>
            index === 0
              ? {
                  scan_id: item.scan_id,
                  status: 503 as const,
                  outcome: {
                    type: 'urn:kronoqr:problem:scan-not-processed' as const,
                    title: 'Escaneo no procesado' as const,
                    status: 503 as const,
                    detail: 'El escaneo no se ha podido procesar. Reintenta mas tarde.' as const,
                    scan_id: item.scan_id,
                  },
                }
              : {
                  scan_id: item.scan_id,
                  status: 200 as const,
                  outcome: accepted(item.scan_id, item.occurred_at, 'clock_in'),
                },
          ),
        },
      }),
    })
    const runner = runnerFor(bench)

    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T16:00:00.000Z'),
    )

    await runner.drain({ ignoreSchedule: true })

    expect(bench.queue.stats().size).toBe(1)
    expect(bench.queue.stats().oldestOccurredAt).toBe('2026-08-14T08:00:00.000Z')
    expect(bench.diagnostics).toContain('sync.item_not_processed')
  })

  it('un `422` saca el elemento: el servidor ya ha decidido', async () => {
    const bench = harness({
      onBatch: (request) => ({
        outcome: 'ok',
        data: {
          results: request.scans.map((item) => ({
            scan_id: item.scan_id,
            status: 422 as const,
            outcome: {
              type: 'urn:kronoqr:problem:scan-rejected' as const,
              title: 'Escaneo no valido' as const,
              status: 422 as const,
              detail: 'El escaneo no se ha podido registrar.' as const,
              scan_id: item.scan_id,
            },
          })),
        },
      }),
    })
    const runner = runnerFor(bench)

    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T16:00:00.000Z'),
    )
    await runner.drain({ ignoreSchedule: true })

    expect(bench.queue.stats().size).toBe(0)
  })

  it('un fallo de transporte no borra NADA', async () => {
    const bench = harness({ onBatch: () => ({ outcome: 'failed', cause: 'network' }) })
    const runner = runnerFor(bench)

    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T16:00:00.000Z'),
    )
    await runner.drain({ ignoreSchedule: true })

    expect(bench.queue.stats().size).toBe(2)
  })

  it('un elemento que el servidor no menciona se conserva', async () => {
    const bench = harness({
      onBatch: (request) => ({
        outcome: 'ok',
        data: {
          results: [
            {
              scan_id: request.scans[1]?.scan_id ?? '',
              status: 200,
              outcome: accepted(
                request.scans[1]?.scan_id ?? '',
                request.scans[1]?.occurred_at ?? '',
                'clock_out',
              ),
            },
          ],
        },
      }),
    })
    const runner = runnerFor(bench)

    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T16:00:00.000Z'),
    )
    await runner.drain({ ignoreSchedule: true })

    expect(bench.queue.stats().size).toBe(1)
    expect(bench.diagnostics).toContain('sync.malformed_response')
  })

  it('un token de dispositivo revocado NO vacia la cola', async () => {
    // Autorizacion negativa: el quiosco pierde el permiso a media jornada. Los
    // fichajes tienen que seguir ahi cuando se vuelva a emparejar.
    const bench = harness({
      onBatch: () => ({ outcome: 'failed', cause: 'unauthorized', httpStatus: 403 }),
    })
    const runner = runnerFor(bench)

    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T16:00:00.000Z'),
    )
    await runner.drain({ ignoreSchedule: true })

    expect(bench.queue.stats().size).toBe(2)
    expect(bench.diagnostics).toContain('sync.unauthorized')
  })

  it('sin red no se gasta ni una peticion: se espera al evento `online`', async () => {
    const bench = harness()
    const runner = runnerFor(bench, { online: false })

    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    await runner.drain({ ignoreSchedule: true })

    expect(bench.batches).toHaveLength(0)
    expect(bench.singles).toHaveLength(0)
    expect(bench.queue.stats().size).toBe(1)
  })

  it('drena en lotes de 50 hasta vaciar', async () => {
    const bench = harness()
    const runner = runnerFor(bench)

    for (let index = 0; index < 120; index += 1) {
      await bench.queue.enqueue(
        scan(
          `0199f0c2-1f4a-7c3e-9b21-4d5e6f7a${String(index).padStart(4, '0')}`,
          new Date(Date.UTC(2026, 7, 14, 0, index)).toISOString(),
        ),
      )
    }

    await runner.drain({ ignoreSchedule: true })

    expect(bench.batches.map((batch) => batch.scans.length)).toEqual([50, 50, 20])
    expect(bench.queue.stats().size).toBe(0)
  })

  it('si el borrado no llega a escribirse, se aplaza en vez de reenviar sin pausa', async () => {
    // IndexedDB lleno o corrupto: el servidor confirma, pero la fila no se
    // puede borrar y vuelve a ser elegible al instante. Sin retroceso, el
    // drenaje la reclamaria y la reenviaria en bucle: una tablet al 8 % de
    // bateria haciendo peticiones sin descanso hasta que alguien la apaga.
    const bench = harness()
    const store = createMemoryQueueStorage()
    const brokenQueue = createScanQueue({
      openStorage: () => ({
        ...store,
        remove: () => Promise.reject(new Error('QuotaExceededError')),
      }),
      clock: CLOCK,
    })
    const runner = createSyncRunner({
      api: bench.api,
      queue: brokenQueue,
      clock: CLOCK,
      isOnline: () => true,
      onDiagnostic: (code) => bench.diagnostics.push(code),
      setTimer: () => 0,
      clearTimer: () => undefined,
    })

    await brokenQueue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    await runner.drain({ ignoreSchedule: true })

    // Una peticion, no un bucle. Y el fichaje sigue en la cola: reenviarlo es
    // seguro, el servidor lo deduplica por `scan_id` (regla dura 8).
    expect(bench.batches).toHaveLength(1)
    expect(brokenQueue.stats().size).toBe(1)
    expect(bench.diagnostics).toContain('sync.confirm_not_persisted')

    // Y no vuelve a salir hasta que pase su espera exponencial.
    expect(brokenQueue.stats().nextAttemptAt).toBeGreaterThan(CLOCK.now().getTime())
    await runner.drain()
    expect(bench.batches).toHaveLength(1)
  })
})

describe('garantia 6 — degradacion honesta', () => {
  let bench: Harness

  beforeEach(() => {
    bench = harness()
  })

  it('sin red, el escaneo se encola y se responde «pendiente», nunca «rechazado»', async () => {
    const runner = runnerFor(bench, { online: false })
    // Sin `start()`: cada prueba decide cuando drena, para que no haya carreras.

    const result = await runner.submit(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )

    expect(result.kind).toBe('deferred')
    expect(bench.queue.stats().size).toBe(1)
  })

  it('si el servidor rechaza el envio individual, se dice y se saca de la cola', async () => {
    const rejecting = harness({
      onSingle: (scanId) => ({
        outcome: 'rejected',
        problem: {
          type: 'urn:kronoqr:problem:scan-rejected',
          title: 'Escaneo no valido',
          status: 422,
          detail: 'El escaneo no se ha podido registrar.',
          scan_id: scanId,
        },
      }),
    })
    const runner = runnerFor(rejecting)
    // Sin `start()`: cada prueba decide cuando drena, para que no haya carreras.

    const result = await runner.submit(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )

    expect(result.kind).toBe('rejected')
    expect(rejecting.queue.stats().size).toBe(0)
  })
})
