// Las SEIS GARANTIAS del protocolo del §6, una por una.
//
// Cada bloque de este fichero se llama como la garantia que comprueba. Si
// alguna deja de cumplirse, lo que falla dice exactamente cual.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { QueuedPinScan, QueuedScan } from '@/features/scan/application/ports'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import type { ScanQueue } from '@/features/offline/application/scanQueue'
import { createSyncRunner } from '@/features/offline/application/syncRunner'
import type { SyncDiagnostic } from '@/features/offline/application/syncRunner'
import { createMemoryQueueStorage } from '@/features/offline/infrastructure/queueStorage'
import type { ApiClient, ApiResult } from '@/shared/api/client'
import type {
  DiscardedScanReceipt,
  DiscardedScanReportBatch,
  ScanBatchRequest,
  ScanBatchResponse,
  ScanOk,
} from '@/shared/api/types'
import { fixedClock } from '@/shared/time/clock'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const CLOCK = fixedClock(new Date('2026-08-14T09:30:00.000Z'))

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

/** Fichaje de respaldo por PIN (tarea 1.12): mismo `scan_id`/`occurred_at`, otra via. */
function pinScan(scanId: string, occurredAt: string): QueuedPinScan {
  return {
    kind: 'pin',
    scan_id: scanId,
    employee_code: 'E7QK2MXPR',
    // Un sobre cerrado de mentira: al drenaje le da igual, solo lo transporta.
    pin_sealed: 'c2VhbGVkLXBpbi1lbnZlbG9wZS1wbGFjZWhvbGRlcg==',
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
  /** `scan_id` de cada llamada a `/scan/pin`, en el orden en que se hicieron. */
  readonly pinCalls: string[]
  /** Cuerpos de `POST /scan/discarded`, en orden (RN-22). */
  readonly reports: DiscardedScanReportBatch[]
  readonly diagnostics: SyncDiagnostic[]
  readonly queue: ScanQueue
}

interface HarnessOptions {
  readonly onBatch?: (request: ScanBatchRequest) => ApiResult<ScanBatchResponse>
  readonly onSingle?: (scanId: string) => ApiResult<ScanOk>
  readonly onPin?: (scanId: string) => ApiResult<ScanOk>
  /** Por defecto acusa todos los `scan_id` recibidos. */
  readonly onReport?: (body: DiscardedScanReportBatch) => ApiResult<DiscardedScanReceipt>
}

function harness(options: HarnessOptions = {}): Harness {
  const batches: ScanBatchRequest[] = []
  const batchKeys: string[] = []
  const singles: string[] = []
  const pinCalls: string[] = []
  const reports: DiscardedScanReportBatch[] = []
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
    recordPinScan: vi.fn(async (request) => {
      pinCalls.push(request.scan_id)
      return (
        options.onPin?.(request.scan_id) ?? {
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
    reportDiscardedScans: vi.fn(async (body: DiscardedScanReportBatch) => {
      reports.push(body)
      return (
        options.onReport?.(body) ?? {
          outcome: 'ok' as const,
          data: { acknowledged: body.reports.map((report) => report.scan_id) },
        }
      )
    }),
    fetchRoster: vi.fn(),
    sendHeartbeat: vi.fn(),
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    fetchBranding: vi.fn(),
  }

  const queue = createScanQueue({ openStorage: createMemoryQueueStorage, clock: CLOCK })
  return { api, batches, batchKeys, singles, pinCalls, reports, diagnostics, queue }
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

  // RN-18 «fichaje irreconciliable»: el servidor deja de reintentar para
  // siempre un elemento cuyo `occurred_at` no puede cuadrar con el tramo
  // abierto -antes daba `503` (garantia 5, tarea 3.6)- y contesta `422` con
  // el mismo cuerpo generico `ScanRejected` de cualquier otro rechazo. El
  // quiosco NO distingue el motivo (RS-03): esto prueba que un `422` saca su
  // elemento aunque venga MEZCLADO, en el mismo `207`, con un `200` de otro.
  it('un `422` mezclado con un `200` en el MISMO lote saca solo el rechazado, y confirma el otro (RN-18)', async () => {
    const REJECTED_ID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'
    const bench = harness({
      onBatch: (request) => ({
        outcome: 'ok',
        data: {
          results: request.scans.map((item) =>
            item.scan_id === REJECTED_ID
              ? {
                  scan_id: item.scan_id,
                  status: 422 as const,
                  outcome: {
                    type: 'urn:kronoqr:problem:scan-rejected' as const,
                    title: 'Escaneo no valido' as const,
                    status: 422 as const,
                    detail: 'El escaneo no se ha podido registrar.' as const,
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

    await bench.queue.enqueue(scan(REJECTED_ID, '2026-08-14T08:00:00.000Z'))
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T16:00:00.000Z'),
    )
    await runner.drain({ ignoreSchedule: true })

    // Los dos han desaparecido: el `422` es un desenlace tanto como el `200`
    // (regla dura 8, al reves de un `503` -que SI se conserva, ver arriba-).
    expect(bench.queue.stats().size).toBe(0)
    // Ambos viajaron en la MISMA llamada de lote: no son dos pasadas.
    expect(bench.batches).toHaveLength(1)
    expect(bench.batches[0]?.scans).toHaveLength(2)
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

describe('fichaje de respaldo por PIN (tarea 1.12, RF-AT-11)', () => {
  it('con la cola vacia usa el envio individual a `/scan/pin`, no `/scan`', async () => {
    const bench = harness()
    const runner = runnerFor(bench)

    const result = await runner.submit(
      pinScan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )

    expect(result.kind).toBe('accepted')
    expect(bench.pinCalls).toEqual(['0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'])
    expect(bench.singles).toHaveLength(0)
    expect(bench.batches).toHaveLength(0)
  })

  it('sin red se encola y se aplaza igual que un fichaje por tarjeta (regla dura 19)', async () => {
    const bench = harness()
    const runner = runnerFor(bench, { online: false })

    const result = await runner.submit(
      pinScan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )

    expect(result.kind).toBe('deferred')
    expect(bench.pinCalls).toHaveLength(0)
    expect(bench.queue.stats().size).toBe(1)
  })

  it('el PIN no tiene lote: cada elemento del tramo viaja en su propia llamada, en orden', async () => {
    const bench = harness()
    const runner = runnerFor(bench)

    // Dos fichajes por PIN encolados sin red: la salida antes que la entrada,
    // como pasaria con una cola desordenada.
    await bench.queue.enqueue(
      pinScan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T16:00:00.000Z'),
    )
    await bench.queue.enqueue(
      pinScan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )

    await runner.drain({ ignoreSchedule: true })

    expect(bench.pinCalls).toEqual([
      '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
      '0199f13a-7c22-7b41-9e88-0c4d5e6f7a81',
    ])
    expect(bench.batches).toHaveLength(0)
    expect(bench.queue.stats().size).toBe(0)
  })

  it('un PIN y un QR encolados se aplican en su orden real, cada uno por su via', async () => {
    const bench = harness()
    const runner = runnerFor(bench)

    // Entrada por tarjeta a las 08:00; salida por PIN (tarjeta olvidada) a las
    // 16:00. Se encolan al reves para que el orden de encolado no pueda
    // confundirse con el orden de `occurred_at`.
    await bench.queue.enqueue(
      pinScan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T16:00:00.000Z'),
    )
    await bench.queue.enqueue(
      scan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )

    await runner.drain({ ignoreSchedule: true })

    // El QR (mas temprano) se envia por lote ANTES que el PIN (mas tardio).
    expect(bench.batches[0]?.scans.map((item) => item.scan_id)).toEqual([
      '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
    ])
    expect(bench.pinCalls).toEqual(['0199f13a-7c22-7b41-9e88-0c4d5e6f7a81'])
    expect(bench.queue.stats().size).toBe(0)
  })

  it('un PIN atascado no deja que un QR mas tardio del mismo drenaje lo adelante', async () => {
    const bench = harness({ onPin: () => ({ outcome: 'failed', cause: 'network' }) })
    const runner = runnerFor(bench)

    // PIN a las 08:00 (fallara), QR a las 16:00 (funcionaria si se probara).
    await bench.queue.enqueue(
      pinScan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    await bench.queue.enqueue(
      scan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T16:00:00.000Z'),
    )

    await runner.drain({ ignoreSchedule: true })

    // El QR NO se ha intentado: adelantarlo habria roto el orden real.
    expect(bench.batches).toHaveLength(0)
    expect(bench.pinCalls).toEqual(['0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'])
    // Los dos siguen en la cola: nada se ha perdido (garantia 5).
    expect(bench.queue.stats().size).toBe(2)
  })

  it('el PIN rechazado por el servidor sale de la cola, como cualquier `422`', async () => {
    const bench = harness({
      onPin: (scanId) => ({
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
    const runner = runnerFor(bench)

    await bench.queue.enqueue(
      pinScan('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T08:00:00.000Z'),
    )
    await bench.queue.enqueue(
      pinScan('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T16:00:00.000Z'),
    )
    await runner.drain({ ignoreSchedule: true })

    expect(bench.queue.stats().size).toBe(0)
  })
})

// RN-22 / ADR-047 (antes PIN-08). Un 400, o un 422 que no es el rechazo
// estandar, es el desenlace de ESE fichaje en cuanto al ORDEN: sale de la cola
// de envio y la cola sigue. Pero NO se consolida como perdida: se MUEVE a la
// lista de descartados y se AVISA al servidor (`POST /scan/discarded`) para que
// una persona lo revise. Antes se borraba con un diagnostico y nadie lo veia.
describe('RN-22 — un 400 se mueve a descartados y se avisa (RF-KI-04, RF-AT-07)', () => {
  const INVALID: ApiResult<ScanOk> = {
    outcome: 'failed',
    cause: 'invalid',
    httpStatus: 400,
    problemType: 'urn:kronoqr:problem:invalid-request',
  }
  const POISONED = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'
  const GOOD = '0199f13a-7c22-7b41-9e88-0c4d5e6f7a81'

  it('un PIN envenenado se descarta (no se pierde) y el siguiente SI se envia', async () => {
    const bench = harness({
      onPin: (scanId) => (scanId === POISONED ? INVALID : accepted200(scanId)),
    })
    const runner = runnerFor(bench)

    await bench.queue.enqueue(pinScan(POISONED, '2026-08-14T08:00:00.000Z'))
    await bench.queue.enqueue(pinScan(GOOD, '2026-08-14T16:00:00.000Z'))
    await runner.drain({ ignoreSchedule: true })

    expect(bench.pinCalls).toEqual([POISONED, GOOD])
    expect(bench.queue.stats().size).toBe(0)
    expect(bench.diagnostics).toContain('sync.item_discarded')
    // El aviso salio, con lo que hace falta para atribuirlo y SIN el PIN.
    expect(bench.reports).toHaveLength(1)
    const [report] = bench.reports[0]?.reports ?? []
    expect(report).toMatchObject({
      scan_id: POISONED,
      kind: 'pin',
      employee_code: 'E7QK2MXPR',
      http_status: 400,
      problem_type: 'urn:kronoqr:problem:invalid-request',
      occurred_at: '2026-08-14T08:00:00.000Z',
    })
    expect(JSON.stringify(bench.reports)).not.toContain('pin_sealed')
    expect(JSON.stringify(bench.reports)).not.toContain('c2VhbGVk')
    // Acusado: ya no queda nada pendiente de avisar.
    expect(bench.queue.stats().unreportedDiscards).toBe(0)
  })

  it('un lote QR rechazado por mal formado se reenvia de uno en uno y solo cae el envenenado', async () => {
    const bench = harness({
      onBatch: () => INVALID as unknown as ApiResult<ScanBatchResponse>,
      onSingle: (scanId) => (scanId === POISONED ? INVALID : accepted200(scanId)),
    })
    const runner = runnerFor(bench)

    await bench.queue.enqueue(scan(POISONED, '2026-08-14T08:00:00.000Z'))
    await bench.queue.enqueue(scan(GOOD, '2026-08-14T16:00:00.000Z'))
    await runner.drain({ ignoreSchedule: true })

    // En orden de `occurred_at` y por el endpoint individual.
    expect(bench.singles).toEqual([POISONED, GOOD])
    expect(bench.queue.stats().size).toBe(0)
    expect(bench.diagnostics).toEqual(['sync.item_discarded'])
    expect(bench.reports[0]?.reports[0]).toMatchObject({
      scan_id: POISONED,
      kind: 'qr',
      qr_payload: PAYLOAD,
    })
  })

  it('el diagnostico no lleva ni `scan_id` ni payload (regla dura 21)', async () => {
    const contexts: Record<string, string | number>[] = []
    const bench = harness({ onPin: () => INVALID })
    const runner = createSyncRunner({
      api: bench.api,
      queue: bench.queue,
      clock: CLOCK,
      isOnline: () => true,
      onDiagnostic: (_code, context) => contexts.push(context),
      setTimer: () => 0,
      clearTimer: () => undefined,
    })

    await bench.queue.enqueue(pinScan(POISONED, '2026-08-14T08:00:00.000Z'))
    await runner.drain({ ignoreSchedule: true })

    expect(JSON.stringify(contexts)).not.toContain(POISONED)
    expect(JSON.stringify(contexts)).not.toContain('E7QK2MXPR')
    expect(contexts[0]).toMatchObject({
      http_status: 400,
      kind: 'pin',
      problem_type: 'urn:kronoqr:problem:invalid-request',
    })
  })

  it('en el camino rapido, un 400 saca el fichaje de la cola, se enseña como rechazado y se avisa', async () => {
    const bench = harness({ onSingle: () => INVALID })
    const runner = runnerFor(bench)

    const result = await runner.submit(scan(POISONED, '2026-08-14T08:00:00.000Z'))

    expect(result).toEqual({ kind: 'rejected' })
    expect(bench.queue.stats().size).toBe(0)
    expect(bench.diagnostics).toContain('sync.item_discarded')
    await vi.waitFor(() => expect(bench.reports).toHaveLength(1))
    expect(bench.reports[0]?.reports[0]?.scan_id).toBe(POISONED)
  })

  it('un 422 que NO es el rechazo estandar tambien se descarta, no se borra', async () => {
    const bench = harness({
      onPin: () => ({ ...INVALID, httpStatus: 422, problemType: null }),
    })
    const runner = runnerFor(bench)

    await bench.queue.enqueue(pinScan(POISONED, '2026-08-14T08:00:00.000Z'))
    await runner.drain({ ignoreSchedule: true })

    expect(bench.reports[0]?.reports[0]).toMatchObject({ http_status: 422, problem_type: null })
  })

  it('un 422 estandar (`scan-rejected`) SI se borra: es un desenlace, no un descarte', async () => {
    const bench = harness({
      onPin: (scanId) => ({
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
    const runner = runnerFor(bench)

    await bench.queue.enqueue(pinScan(POISONED, '2026-08-14T08:00:00.000Z'))
    await runner.drain({ ignoreSchedule: true })

    expect(bench.queue.stats().size).toBe(0)
    expect(bench.reports).toHaveLength(0)
    expect(bench.diagnostics).toEqual([])
  })

  it('un fallo transitorio (5xx) sigue conservando el fichaje, sin descartar nada', async () => {
    const bench = harness({
      onSingle: () => ({ outcome: 'failed', cause: 'server', httpStatus: 500 }),
    })
    const runner = runnerFor(bench)

    const result = await runner.submit(scan(POISONED, '2026-08-14T08:00:00.000Z'))

    expect(result).toEqual({ kind: 'deferred' })
    expect(bench.queue.stats().size).toBe(1)
    expect(bench.queue.stats().unreportedDiscards).toBe(0)
  })
})

function accepted200(scanId: string): ApiResult<ScanOk> {
  return { outcome: 'ok', data: accepted(scanId, '2026-08-14T08:00:00.000Z', 'clock_in') }
}

// G1 y G2. Sobre el drenaje real, no solo sobre `claim()`.
describe('G1/G2 — el orden sobrevive a un fallo y el drenaje no gira en vacio (RF-KI-04, RQ-05)', () => {
  const OLD = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'
  const NEW = '0199f13a-7c22-7b41-9e88-0c4d5e6f7a81'

  it('una entrada atascada no es adelantada por una salida nueva cuando vuelve el servidor (G1)', async () => {
    const order: string[] = []
    let failFirst = true
    const bench = harness({
      onBatch: (request) => {
        if (failFirst) {
          failFirst = false
          return { outcome: 'failed', cause: 'server', httpStatus: 500 }
        }
        order.push(...request.scans.map((item) => item.scan_id))
        return {
          outcome: 'ok',
          data: {
            results: request.scans.map((item) => ({
              scan_id: item.scan_id,
              status: 200 as const,
              outcome: accepted(item.scan_id, item.occurred_at, 'clock_in'),
            })),
          },
        }
      },
    })
    const runner = runnerFor(bench)

    await bench.queue.enqueue(scan(OLD, '2026-08-14T08:00:00.000Z'))
    await runner.drain({ ignoreSchedule: true }) // falla: la entrada queda en espera

    // Llega una salida nueva y se drena SIN saltarse la espera (temporizador).
    await bench.queue.enqueue(scan(NEW, '2026-08-14T16:00:00.000Z'))
    await runner.drain()
    expect(order).toEqual([])
    expect(bench.queue.stats().size).toBe(2)

    // Vuelve la red: sale todo, la entrada primero.
    await runner.drain({ ignoreSchedule: true })
    expect(order).toEqual([OLD, NEW])
    expect(bench.queue.stats().size).toBe(0)
  })

  it('el relanzamiento pedido mientras se drenaba hereda `ignoreSchedule` (G1)', async () => {
    let release: (() => void) | undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    let calls = 0
    const bench = harness({
      onBatch: () => ({ outcome: 'failed', cause: 'network' }),
    })
    const original = bench.api.syncScanBatch
    bench.api.syncScanBatch = vi.fn(async (request: ScanBatchRequest, key: string) => {
      calls += 1
      if (calls === 1) await gate
      return original(request, key)
    })
    const runner = runnerFor(bench)

    await bench.queue.enqueue(scan(OLD, '2026-08-14T08:00:00.000Z'))
    const first = runner.drain({ ignoreSchedule: true })
    await vi.waitFor(() => expect(calls).toBe(1))
    // «Vuelve la red» mientras el primer drenaje sigue en vuelo.
    void runner.drain({ ignoreSchedule: true })
    release?.()
    await first

    // El relanzamiento tambien se salto la espera que el primer fallo acaba de fijar.
    await vi.waitFor(() => expect(calls).toBe(2))
  })

  it('un escaneo nuevo NO se salta el retroceso; el evento online (wakeNow) si (bateria, RN-21)', async () => {
    const bench = harness()
    const runner = runnerFor(bench)
    runner.start()
    await new Promise((resolve) => setTimeout(resolve, 20))

    // La cabeza esta aplazada (servidor en 503 continuado).
    await bench.queue.enqueue(scan(OLD, '2026-08-14T08:00:00.000Z'))
    await bench.queue.claim(1)
    await bench.queue.retryLater([OLD], CLOCK.now())

    // Entra un fichaje nuevo: queda detras de la cabeza y no dispara ninguna peticion.
    const result = await runner.submit(scan(NEW, '2026-08-14T09:00:00.000Z'))
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(result.kind).toBe('deferred')
    expect(bench.api.syncScanBatch).not.toHaveBeenCalled()
    expect(bench.api.recordScan).not.toHaveBeenCalled()

    // Vuelve la red: ahi si, y en orden.
    runner.wakeNow()
    await vi.waitFor(() => expect(bench.batches).toHaveLength(1))
    expect(bench.batches[0]?.scans.map((item) => item.scan_id)).toEqual([OLD, NEW])
  })

  it('cola en memoria y vacia: el drenaje se programa UNA vez a la espera larga, sin leer en bucle (G2)', async () => {
    const delays: number[] = []
    let lists = 0
    const queue = createScanQueue({
      openStorage: () => ({
        ...createMemoryQueueStorage(),
        durable: false,
        list: async () => {
          lists += 1
          return []
        },
      }),
      clock: CLOCK,
    })
    const runner = createSyncRunner({
      api: harness().api,
      queue,
      clock: CLOCK,
      isOnline: () => true,
      setTimer: (_handler, delayMs) => {
        delays.push(delayMs)
        return 1
      },
      clearTimer: () => undefined,
    })

    runner.start()
    await vi.waitFor(() => expect(delays.length).toBeGreaterThan(0))
    await new Promise((resolve) => setTimeout(resolve, 30))
    const readsAfterDrain = lists
    await new Promise((resolve) => setTimeout(resolve, 30))

    // Nunca a 0 ms: sin nada con fecha que esperar, el sondeo es el lento.
    expect(new Set(delays)).toEqual(new Set([30_000]))
    expect(lists).toBe(readsAfterDrain)
  })

  it('sin red el drenaje se programa a la espera larga, no a 0 ms (G2)', async () => {
    const delays: number[] = []
    const bench = harness()
    const runner = createSyncRunner({
      api: bench.api,
      queue: bench.queue,
      clock: CLOCK,
      isOnline: () => false,
      setTimer: (_handler, delayMs) => {
        delays.push(delayMs)
        return 1
      },
      clearTimer: () => undefined,
    })

    runner.start()
    await bench.queue.enqueue(scan(OLD, '2026-08-14T08:00:00.000Z'))
    await runner.drain({ ignoreSchedule: true })

    // `start()` lanza su propio drenaje: el programado al terminar puede llegar
    // despues de que este `drain()` devuelva el control.
    await vi.waitFor(() => expect(delays.length).toBeGreaterThan(0))
    expect(bench.api.syncScanBatch).not.toHaveBeenCalled()
    expect(Math.min(...delays)).toBeGreaterThanOrEqual(30_000)
  })
})

// KT2 (RF-KI-03, RF-KI-04): el camino rapido de `submit()` con la cola vacia
// (el escaneo es lo unico pendiente) ante un fallo de red o un 5xx. El
// empleado ya fue confirmado en local: lo que se prueba es que el fichaje NO
// se pierde, sigue en la cola con su espera, y que se programa el reintento.
describe('KT2 — camino rapido de submit() con fallo de red o 5xx (RF-KI-03, RF-KI-04)', () => {
  const ID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'

  /** `start()` deja un drenaje inicial en vuelo: se espera a que termine para que `submit()` tome el camino rapido. */
  async function started(runner: ReturnType<typeof createSyncRunner>): Promise<void> {
    runner.start()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }

  function runnerWithTimers(bench: Harness, timers: number[]) {
    return createSyncRunner({
      api: bench.api,
      queue: bench.queue,
      clock: CLOCK,
      isOnline: () => true,
      onDiagnostic: (code) => bench.diagnostics.push(code),
      setTimer: (_handler, delayMs) => {
        timers.push(delayMs)
        return 1
      },
      clearTimer: () => undefined,
    })
  }

  it.each([
    ['fallo de red', { outcome: 'failed', cause: 'network' }],
    ['tiempo agotado', { outcome: 'failed', cause: 'timeout' }],
    ['un 500', { outcome: 'failed', cause: 'server', httpStatus: 500 }],
    ['un 503', { outcome: 'failed', cause: 'server', httpStatus: 503 }],
  ] as const)(
    '%s: se aplaza, sigue en la cola y se programa el reintento',
    async (_name, failure) => {
      const timers: number[] = []
      const bench = harness({ onSingle: () => failure })
      const runner = runnerWithTimers(bench, timers)
      await started(runner)

      const result = await runner.submit(scan(ID, '2026-08-14T08:00:00.000Z'))

      expect(result).toEqual({ kind: 'deferred' })
      expect(bench.queue.stats().size).toBe(1)
      const [row] = await bench.queue.claim(10, { ignoreSchedule: true })
      expect(row).toMatchObject({ scan_id: ID, attempts: 1 })
      expect(row?.next_attempt_at).toBeGreaterThan(CLOCK.now().getTime())
      expect(timers.length).toBeGreaterThan(0)
    },
  )

  it('un fallo de transporte se anota; quedarse sin red no (es lo normal)', async () => {
    const network = harness({ onSingle: () => ({ outcome: 'failed', cause: 'network' }) })
    await runnerWithTimers(network, []).submit(scan(ID, '2026-08-14T08:00:00.000Z'))
    expect(network.diagnostics).toContain('sync.transport_failed')

    const offline = harness({ onSingle: () => ({ outcome: 'failed', cause: 'offline' }) })
    await runnerWithTimers(offline, []).submit(scan(ID, '2026-08-14T08:00:00.000Z'))
    expect(offline.diagnostics).toEqual([])
  })

  it('el reintento reenvia el MISMO scan_id (regla dura 8) y, al confirmar, la cola queda vacia', async () => {
    let attempt = 0
    const bench = harness({
      onSingle: (scanId) => {
        attempt += 1
        return attempt === 1
          ? { outcome: 'failed', cause: 'server', httpStatus: 500 }
          : { outcome: 'ok', data: accepted(scanId, '2026-08-14T08:00:00.000Z', 'clock_in') }
      },
    })
    const runner = runnerWithTimers(bench, [])
    await started(runner)

    await runner.submit(scan(ID, '2026-08-14T08:00:00.000Z'))
    await runner.drain({ ignoreSchedule: true })

    expect(bench.singles[0]).toBe(ID)
    expect(bench.batches.flatMap((batch) => batch.scans.map((item) => item.scan_id))).toContain(ID)
    await vi.waitFor(() => expect(bench.queue.stats().size).toBe(0))
  })

  it('un 401 cuenta para la revocacion pero NO saca el fichaje', async () => {
    const outcomes: boolean[] = []
    const bench = harness({ onSingle: () => ({ outcome: 'failed', cause: 'unauthorized' }) })
    const runner = createSyncRunner({
      api: bench.api,
      queue: bench.queue,
      clock: CLOCK,
      isOnline: () => true,
      onAuthOutcome: (unauthorized) => outcomes.push(unauthorized),
      setTimer: () => 0,
      clearTimer: () => undefined,
    })

    const result = await runner.submit(scan(ID, '2026-08-14T08:00:00.000Z'))

    expect(result).toEqual({ kind: 'deferred' })
    expect(outcomes).toContain(true)
    expect(bench.queue.stats().size).toBe(1)
  })
})

// KT3 (RF-KI-03, regla dura 19): ni IndexedDB ni la memoria aceptan el
// fichaje. No se puede prometer un reintento, asi que se intenta AHORA, por el
// endpoint individual, aunque sea lo unico que queda.
describe('KT3 — rescate cuando no hay donde encolar (RF-KI-03)', () => {
  const ID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'

  /** Cola cuyo almacen no es duradero y rechaza toda escritura: no hay respaldo al que caer. */
  function brokenQueue(): ScanQueue {
    return createScanQueue({
      openStorage: () => ({
        ...createMemoryQueueStorage(),
        durable: false,
        add: async () => {
          throw new Error('quota exceeded')
        },
      }),
      clock: CLOCK,
    })
  }

  function rescueHarness(options: Parameters<typeof harness>[0] = {}) {
    const bench = harness(options)
    const runner = createSyncRunner({
      api: bench.api,
      queue: brokenQueue(),
      clock: CLOCK,
      isOnline: () => true,
      onDiagnostic: (code) => bench.diagnostics.push(code),
      setTimer: () => 0,
      clearTimer: () => undefined,
    })
    return { bench, runner }
  }

  it('la cola rota de verdad no guarda nada', async () => {
    const queue = brokenQueue()

    expect(await queue.enqueue(scan(ID, '2026-08-14T08:00:00.000Z'))).toEqual({
      stored: false,
      durable: false,
    })
  })

  it('un QR se envia directo a /scan y, aceptado, se confirma con lo que dijo el servidor', async () => {
    const { bench, runner } = rescueHarness()

    const result = await runner.submit(scan(ID, '2026-08-14T08:00:00.000Z'))

    expect(bench.singles).toEqual([ID])
    expect(result).toMatchObject({ kind: 'accepted', response: { scan_id: ID } })
  })

  it('un PIN se envia directo a /scan/pin', async () => {
    const { bench, runner } = rescueHarness()

    const result = await runner.submit(pinScan(ID, '2026-08-14T08:00:00.000Z'))

    expect(bench.pinCalls).toEqual([ID])
    expect(bench.singles).toEqual([])
    expect(result.kind).toBe('accepted')
  })

  it('un anti-rebote es un desenlace aceptado, no un error', async () => {
    const { runner } = rescueHarness({
      onSingle: (scanId) => ({
        outcome: 'ok',
        data: {
          scan_id: scanId,
          action: 'debounced',
          employee_display_name: 'Lucia G.',
          work_date: '2026-08-14',
          occurred_at: '2026-08-14T08:00:00.000Z',
          recorded_at: '2026-08-14T09:30:00.000Z',
          worked_minutes: 10,
          last_accepted_at: '2026-08-14T07:59:50.000Z',
        } as unknown as ScanOk,
      }),
    })

    const result = await runner.submit(scan(ID, '2026-08-14T08:00:00.000Z'))

    expect(result.kind).toBe('debounced')
  })

  it('un 422 del servidor es un rechazo', async () => {
    const { runner } = rescueHarness({
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

    expect(await runner.submit(scan(ID, '2026-08-14T08:00:00.000Z'))).toEqual({
      kind: 'rejected',
    })
  })

  it('un 400 del servidor (RN-22) tambien es un rechazo, con diagnostico', async () => {
    const { bench, runner } = rescueHarness({
      onSingle: () => ({ outcome: 'failed', cause: 'invalid', httpStatus: 400 }),
    })

    expect(await runner.submit(scan(ID, '2026-08-14T08:00:00.000Z'))).toEqual({
      kind: 'rejected',
    })
    expect(bench.diagnostics).toContain('sync.item_discarded')
  })

  it('sin red tampoco hay donde guardarlo: se dice `deferred`, sin lanzar y avisando al indicador', async () => {
    const reachability: boolean[] = []
    const bench = harness({ onSingle: () => ({ outcome: 'failed', cause: 'network' }) })
    const runner = createSyncRunner({
      api: bench.api,
      queue: brokenQueue(),
      clock: CLOCK,
      isOnline: () => true,
      onReachability: (reachable) => reachability.push(reachable),
      setTimer: () => 0,
      clearTimer: () => undefined,
    })

    const result = await runner.submit(scan(ID, '2026-08-14T08:00:00.000Z'))

    expect(result).toEqual({ kind: 'deferred' })
    expect(reachability).toContain(false)
  })

  it('un 401 en el rescate cuenta para la revocacion del dispositivo', async () => {
    const auth: boolean[] = []
    const bench = harness({ onSingle: () => ({ outcome: 'failed', cause: 'unauthorized' }) })
    const runner = createSyncRunner({
      api: bench.api,
      queue: brokenQueue(),
      clock: CLOCK,
      isOnline: () => true,
      onAuthOutcome: (unauthorized) => auth.push(unauthorized),
      setTimer: () => 0,
      clearTimer: () => undefined,
    })

    const result = await runner.submit(scan(ID, '2026-08-14T08:00:00.000Z'))

    expect(result).toEqual({ kind: 'deferred' })
    expect(auth).toEqual([true])
  })
})
