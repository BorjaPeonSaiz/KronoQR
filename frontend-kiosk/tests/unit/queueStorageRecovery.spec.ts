// ADR-047 sobre IndexedDB: REABRIR antes de degradar, y degradado no es «cola 0»
// (RF-KI-03, RF-KI-04, RF-KI-08).
//
// Se usa `fake-indexeddb` con la MISMA base abierta por dos manejadores, como
// pasa en la tablet cuando Dexie se cierra y se reabre: el disco conserva lo
// que habia aunque el manejador que fallo se tire.

import 'fake-indexeddb/auto'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import type { QueuedScan } from '@/features/scan/application/ports'
import { createScanQueue } from '@/features/offline/application/scanQueue'
import type { ScanQueue } from '@/features/offline/application/scanQueue'
import { createSyncRunner } from '@/features/offline/application/syncRunner'
import {
  createDexieQueueStorage,
  openKioskDatabase,
} from '@/features/offline/infrastructure/dexieStorage'
import type { QueueStorage } from '@/features/offline/infrastructure/queueStorage'
import type { ApiClient } from '@/shared/api/client'
import { createAppI18n } from '@/shared/i18n'
import { buildHeartbeatBody } from '@/shared/telemetry/heartbeat'
import ConnectionStatusBadge from '@/shared/ui/ConnectionStatusBadge.vue'
import type { Clock } from '@/shared/time/clock'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const IDS = [
  '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b01',
  '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b02',
  '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b03',
  '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b04',
] as const

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

let counter = 0
function freshName(): string {
  counter += 1
  return `kronoqr-b18-rec-${counter}`
}

function steppingClock(): Clock & { advance(ms: number): void } {
  let current = Date.parse('2026-08-14T09:30:00.000Z')
  return {
    now: () => new Date(current),
    advance: (ms) => {
      current += ms
    },
  }
}

/** Todo lo que toca el almacen falla: ni reabriendo se arregla. */
function brokenStorage(): QueueStorage {
  const fail = async (): Promise<never> => {
    throw new Error('InvalidStateError')
  }
  return {
    durable: true,
    add: fail,
    list: fail,
    count: fail,
    remove: fail,
    reschedule: fail,
    discard: fail,
    putDiscarded: fail,
    listDiscarded: fail,
    countDiscarded: fail,
    removeDiscarded: fail,
    rescheduleDiscarded: fail,
    clear: fail,
    readRoster: fail,
    writeRoster: fail,
    clearRoster: fail,
    close: () => undefined,
  }
}

describe('ADR-047 — un fallo puntual de IndexedDB se arregla reabriendo, sin degradar (RF-KI-03)', () => {
  it('`list()` que falla UNA vez: el disco conserva las filas y `stats().size` las cuenta', async () => {
    const name = freshName()
    // El disco ya tenia dos fichajes de antes.
    const seed = createDexieQueueStorage(openKioskDatabase(name))
    for (const [index, id] of IDS.slice(0, 2).entries()) {
      await seed.add({
        kind: 'qr',
        scan_id: id,
        qr_payload: PAYLOAD,
        occurred_at: `2026-08-14T0${index + 5}:00:00.000Z`,
        intent: 'auto',
        device_id: 'kiosk-1',
        attempts: 0,
        next_attempt_at: 0,
        enqueued_at: 1,
      })
    }
    seed.close()

    let opened = 0
    const failures: string[] = []
    const queue = createScanQueue({
      openStorage: () => {
        opened += 1
        const real = createDexieQueueStorage(openKioskDatabase(name))
        if (opened > 1) return real
        // El primer manejador revienta en su primera lectura (p. ej. el sistema
        // cerro la conexion de IndexedDB).
        let broke = false
        return {
          ...real,
          list: async (limit) => {
            if (!broke) {
              broke = true
              throw new Error('InvalidStateError')
            }
            return real.list(limit)
          },
        }
      },
      sleep: async () => undefined,
      onStorageFailure: (reason) => failures.push(reason),
    })

    const stats = await queue.refresh()

    expect(stats.size).toBe(2)
    expect(stats.storage).toBe('durable')
    expect(stats.durable).toBe(true)
    expect(stats.oldestOccurredAt).toBe('2026-08-14T05:00:00.000Z')
    // No hubo caida a memoria: ni un solo aviso de «almacenamiento no disponible».
    expect(failures).toEqual([])
    expect(opened).toBe(2)
  })

  it('reabre dos veces (a los 0 y a los 250 ms) antes de rendirse, y repite la operacion', async () => {
    const name = freshName()
    const sleeps: number[] = []
    let opened = 0
    const queue = createScanQueue({
      openStorage: () => {
        opened += 1
        // El 1.o y el 2.o manejador fallan; el 3.o abre bien.
        return opened < 3 ? brokenStorage() : createDexieQueueStorage(openKioskDatabase(name))
      },
      sleep: async (ms) => {
        sleeps.push(ms)
      },
    })

    const outcome = await queue.enqueue(scan(IDS[0], '2026-08-14T08:00:00.000Z'))

    // 1.o (inicial) + reapertura inmediata (falla) + reapertura a los 250 ms (abre).
    expect(outcome).toEqual({ stored: true, durable: true })
    expect(sleeps).toEqual([250])
    expect(queue.stats().storage).toBe('durable')
    expect(queue.stats().size).toBe(1)
  })
})

describe('ADR-047 — si la reapertura falla, se cae a memoria y se dice que NO se sabe (RF-KI-04)', () => {
  it('`size` es `null` (nunca 0), la cola sigue aceptando fichajes y `inStore` cuenta lo nuevo', async () => {
    const failures: string[] = []
    const queue = createScanQueue({
      openStorage: brokenStorage,
      sleep: async () => undefined,
      onStorageFailure: (reason) => failures.push(reason),
    })

    // Regla dura 19: el empleado ficha igual.
    const outcome = await queue.enqueue(scan(IDS[0], '2026-08-14T08:00:00.000Z'))

    expect(outcome.stored).toBe(true)
    expect(outcome.durable).toBe(false)
    const stats = queue.stats()
    expect(stats.storage).toBe('memory')
    expect(stats.size).toBeNull()
    expect(stats.inStore).toBe(1)
    // Lo mas antiguo de lo que SI se ve no es «lo mas antiguo de la cola».
    expect(stats.oldestOccurredAt).toBeNull()
    expect(failures).toHaveLength(1)
  })

  it('el latido declara `pending_queue_size: null` con `queue_storage: memory`, nunca 0 y sin `oldest_pending_at`', async () => {
    const queue = createScanQueue({ openStorage: brokenStorage, sleep: async () => undefined })
    await queue.enqueue(scan(IDS[0], '2026-08-14T08:00:00.000Z'))
    const stats = queue.stats()

    const body = buildHeartbeatBody({
      appVersion: '2.2.0',
      pendingQueueSize: stats.size,
      queueStorage: stats.storage,
      unreportedDiscards: stats.unreportedDiscards,
      oldestPendingAt: stats.oldestOccurredAt ?? undefined,
    })

    expect(body.pending_queue_size).toBeNull()
    expect(body.queue_storage).toBe('memory')
    expect('oldest_pending_at' in body).toBe(false)
    expect('unreported_discards' in body).toBe(false)
  })

  it('si ni la memoria acepta, el estado es `unavailable`, `size` sigue siendo `null` y `enqueue` no promete nada', async () => {
    const queue = createScanQueue({
      openStorage: () => ({ ...brokenStorage(), durable: false }),
      sleep: async () => undefined,
    })

    const outcome = await queue.enqueue(scan(IDS[0], '2026-08-14T08:00:00.000Z'))

    expect(outcome.stored).toBe(false)
    expect(queue.stats().storage).toBe('unavailable')
    expect(queue.stats().size).toBeNull()
  })

  it('el indicador de pendientes dice «desconocido» con `null`, no pinta un 0', () => {
    const wrapper = mount(ConnectionStatusBadge, {
      props: { status: 'online', pendingCount: null },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.get('[data-testid="pending-unknown"]').text()).toContain('desconocido')
    expect(wrapper.attributes('data-pending')).toBe('unknown')
    expect(wrapper.text()).not.toContain('0 fichajes')
  })

  it('el indicador, en ingles', () => {
    const wrapper = mount(ConnectionStatusBadge, {
      props: { status: 'online', pendingCount: null },
      global: { plugins: [createAppI18n('en')] },
    })

    expect(wrapper.get('[data-testid="pending-unknown"]').text()).toContain('unknown')
  })

  it('un 0 de verdad sigue sin enseñar nada (la cola esta vacia y se sabe)', () => {
    const wrapper = mount(ConnectionStatusBadge, {
      props: { status: 'online', pendingCount: 0 },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.find('[data-testid="pending-unknown"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('pendiente')
  })
})

describe('ADR-047 — en cada drenaje se intenta volver al disco y se migra lo de memoria', () => {
  async function degradedQueue(): Promise<{
    queue: ScanQueue
    name: string
    clock: ReturnType<typeof steppingClock>
    setDiskHealthy: (healthy: boolean) => void
    /** IndexedDB se rompe: el manejador vivo se cierra y no hay manera de reabrir. */
    breakDisk: () => void
  }> {
    const name = freshName()
    const clock = steppingClock()
    let healthy = true
    const queue = createScanQueue({
      openStorage: () =>
        healthy ? createDexieQueueStorage(openKioskDatabase(name)) : brokenStorage(),
      clock,
      sleep: async () => undefined,
    })
    return {
      queue,
      name,
      clock,
      setDiskHealthy: (value) => {
        healthy = value
      },
      breakDisk: () => {
        healthy = false
        queue.storage().close()
      },
    }
  }

  it('al volver a disco migra lo de memoria (clave `scan_id`, idempotente) y pasa a `durable`', async () => {
    const { queue, name, clock, setDiskHealthy, breakDisk } = await degradedQueue()
    // Antes de la averia, un fichaje llega a disco.
    await queue.enqueue(scan(IDS[0], '2026-08-14T05:00:00.000Z'))
    expect(queue.stats().storage).toBe('durable')

    // IndexedDB se rompe y no hay manera de reabrirlo: caida a memoria.
    breakDisk()
    await queue.enqueue(scan(IDS[1], '2026-08-14T06:00:00.000Z'))
    await queue.enqueue(scan(IDS[2], '2026-08-14T07:00:00.000Z'))
    expect(queue.stats().storage).toBe('memory')
    expect(queue.stats().size).toBeNull()
    expect(queue.stats().inStore).toBe(2)

    // Vuelve el disco. Pasado el minuto de rigor, el siguiente drenaje lo reabre.
    setDiskHealthy(true)
    clock.advance(61_000)
    const recovered = await queue.tryReopen()

    expect(recovered).toBe(true)
    const stats = queue.stats()
    expect(stats.storage).toBe('durable')
    // Ahora SI se sabe: el de antes (que seguia en disco) + los dos de memoria.
    expect(stats.size).toBe(3)
    expect(stats.oldestOccurredAt).toBe('2026-08-14T05:00:00.000Z')

    // Y estan EN DISCO: se leen con un manejador nuevo.
    const raw = await openKioskDatabase(name).scans.orderBy('occurred_at').toArray()
    expect(raw.map((row) => row.scan_id)).toEqual([IDS[0], IDS[1], IDS[2]])
  })

  it('migrar dos veces no duplica nada (idempotente por `scan_id`)', async () => {
    const { queue, name, clock, setDiskHealthy, breakDisk } = await degradedQueue()
    breakDisk()
    await queue.enqueue(scan(IDS[0], '2026-08-14T06:00:00.000Z'))
    // Un descartado en memoria tambien migra.
    await queue.discard(scan(IDS[1], '2026-08-14T07:00:00.000Z'), {
      http_status: 400,
      problem_type: null,
    })
    setDiskHealthy(true)
    // Una migracion a medias previa: la fila ya esta en disco.
    const disk = createDexieQueueStorage(openKioskDatabase(name))
    await disk.add({
      kind: 'qr',
      scan_id: IDS[0],
      qr_payload: PAYLOAD,
      occurred_at: '2026-08-14T06:00:00.000Z',
      intent: 'auto',
      device_id: 'kiosk-1',
      attempts: 0,
      next_attempt_at: 0,
      enqueued_at: 1,
    })

    clock.advance(61_000)
    expect(await queue.tryReopen()).toBe(true)

    expect(queue.stats().size).toBe(1)
    expect(queue.stats().unreportedDiscards).toBe(1)
    expect(await openKioskDatabase(name).scans.count()).toBe(1)
    expect(await openKioskDatabase(name).discarded.count()).toBe(1)
  })

  it('RN-21: disco con 07:00 + memoria con 15:00 → nada se envia hasta reabrir; tras reabrir salen en orden', async () => {
    const { queue, clock, setDiskHealthy, breakDisk } = await degradedQueue()
    await queue.enqueue(scan(IDS[0], '2026-08-14T07:00:00.000Z'))
    breakDisk()
    await queue.enqueue(scan(IDS[1], '2026-08-14T15:00:00.000Z'))
    expect(queue.stats().storage).toBe('memory')

    // La de las 15:00 NO puede adelantar a la de las 07:00, que sigue en un disco que no se ve.
    expect(queue.isClaimBlocked()).toBe(true)
    expect(await queue.claim(10, { ignoreSchedule: true })).toEqual([])

    setDiskHealthy(true)
    clock.advance(61_000)
    expect(await queue.tryReopen()).toBe(true)
    const both = await queue.claim(10, { ignoreSchedule: true })
    expect(both.map((row) => row.scan_id)).toEqual([IDS[0], IDS[1]])
  })

  it('RN-21: con el disco a 0 antes de degradar, lo de memoria SI sale', async () => {
    const { queue, breakDisk } = await degradedQueue()
    await queue.refresh()
    expect(queue.stats().size).toBe(0)
    breakDisk()
    await queue.enqueue(scan(IDS[1], '2026-08-14T15:00:00.000Z'))

    expect(queue.isClaimBlocked()).toBe(false)
    const claimed = await queue.claim(10, { ignoreSchedule: true })
    expect(claimed.map((row) => row.scan_id)).toEqual([IDS[1]])
  })

  it('como mucho una vez por minuto, y si el disco sigue roto se queda en memoria con las filas intactas', async () => {
    const { queue, clock, setDiskHealthy, breakDisk } = await degradedQueue()
    breakDisk()
    await queue.enqueue(scan(IDS[0], '2026-08-14T06:00:00.000Z'))

    // Primer intento (el disco sigue roto): no se rinde, y no pierde nada.
    expect(await queue.tryReopen()).toBe(false)
    expect(queue.stats().storage).toBe('memory')
    expect(queue.stats().inStore).toBe(1)

    // Dentro del minuto, ni se intenta aunque el disco ya este sano.
    setDiskHealthy(true)
    clock.advance(30_000)
    expect(await queue.tryReopen()).toBe(false)
    expect(queue.stats().storage).toBe('memory')

    clock.advance(31_000)
    expect(await queue.tryReopen()).toBe(true)
    expect(queue.stats().storage).toBe('durable')
    expect(queue.stats().size).toBe(1)
  })

  it('el drenaje intenta volver a disco: `drain()` llama a `tryReopen()`', async () => {
    const { queue, clock, setDiskHealthy, breakDisk } = await degradedQueue()
    breakDisk()
    await queue.enqueue(scan(IDS[0], '2026-08-14T06:00:00.000Z'))
    setDiskHealthy(true)
    clock.advance(61_000)

    const api = {
      syncScanBatch: vi.fn(async () => ({ outcome: 'failed' as const, cause: 'network' as const })),
      reportDiscardedScans: vi.fn(),
    } as unknown as ApiClient
    const runner = createSyncRunner({
      api,
      queue,
      clock,
      isOnline: () => true,
      setTimer: () => 0,
      clearTimer: () => undefined,
    })

    await runner.drain({ ignoreSchedule: true })

    expect(queue.stats().storage).toBe('durable')
  })

  it('con la cola en memoria y vacia el drenaje se sigue programando (para volver al disco), a la espera larga, sin girar', async () => {
    const delays: number[] = []
    const queue = createScanQueue({ openStorage: brokenStorage, sleep: async () => undefined })
    await queue.refresh()
    expect(queue.stats().storage).toBe('memory')
    const runner = createSyncRunner({
      api: { reportDiscardedScans: vi.fn() } as unknown as ApiClient,
      queue,
      isOnline: () => true,
      setTimer: (_handler, delayMs) => {
        delays.push(delayMs)
        return 1
      },
      clearTimer: () => undefined,
    })

    runner.start()
    await runner.drain()

    await vi.waitFor(() => expect(delays.length).toBeGreaterThan(0))
    // Nunca a 0 ms: sin nada con fecha que esperar, el sondeo es el lento (G2).
    expect(Math.min(...delays)).toBeGreaterThanOrEqual(30_000)
  })
})
