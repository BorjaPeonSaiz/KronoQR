import { describe, expect, it, vi } from 'vitest'
import type { ApiClient } from '@/shared/api/client'
import { createErrorReporter } from '@/shared/telemetry/errorReporter'
import {
  buildHeartbeatBody,
  clockSkewSeconds,
  createHeartbeatScheduler,
} from '@/shared/telemetry/heartbeat'
import { fixedClock } from '@/shared/time/clock'

function apiReturning(serverTime: string): ApiClient {
  return {
    recordScan: vi.fn(),
    syncScanBatch: vi.fn(),
    fetchRoster: vi.fn(),
    sendHeartbeat: vi.fn(async () => ({
      outcome: 'ok' as const,
      data: { server_time: serverTime },
    })),
  }
}

describe('latido del quiosco', () => {
  it('declara version y cola pendiente', () => {
    expect(buildHeartbeatBody({ appVersion: '1.4.2', pendingQueueSize: 37 })).toEqual({
      app_version: '1.4.2',
      pending_queue_size: 37,
    })
  })

  it('omite oldest_pending_at cuando no hay cola, en vez de mandarlo nulo', () => {
    const body = buildHeartbeatBody({
      appVersion: '1.4.2',
      pendingQueueSize: 0,
      oldestPendingAt: undefined,
    })
    expect('oldest_pending_at' in body).toBe(false)
  })

  it('incluye el mas antiguo cuando lo hay', () => {
    expect(
      buildHeartbeatBody({
        appVersion: '1.4.2',
        pendingQueueSize: 37,
        oldestPendingAt: '2026-08-14T05:58:31Z',
      }),
    ).toMatchObject({ oldest_pending_at: '2026-08-14T05:58:31Z' })
  })

  it('mide el desfase de reloj contra la hora del servidor', () => {
    expect(clockSkewSeconds(new Date('2026-08-14T09:00:20.000Z'), '2026-08-14T09:00:00.000Z')).toBe(
      20,
    )
    expect(clockSkewSeconds(new Date('2026-08-14T08:59:40.000Z'), '2026-08-14T09:00:00.000Z')).toBe(
      -20,
    )
    expect(clockSkewSeconds(new Date(), 'no es una fecha')).toBeNull()
  })

  it('anota el desfase grande pero NO impide nada (regla dura 19)', async () => {
    const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
    const scheduler = createHeartbeatScheduler({
      api: apiReturning('2026-08-14T09:00:00.000Z'),
      reporter,
      snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
      clock: fixedClock(new Date('2026-08-14T09:20:00.000Z')),
    })

    const skew = await scheduler.beat()

    expect(skew).toBe(1200)
    expect(reporter.pending()[0]?.code).toBe('kiosk.clock.skew_detected')
    expect(reporter.pending()[0]?.context['skew_seconds']).toBe(1200)
  })

  it('no reporta un latido perdido por estar sin red: eso no es una averia', async () => {
    const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
    const scheduler = createHeartbeatScheduler({
      api: {
        recordScan: vi.fn(),
        syncScanBatch: vi.fn(),
        fetchRoster: vi.fn(),
        sendHeartbeat: vi.fn(async () => ({
          outcome: 'failed' as const,
          cause: 'offline' as const,
        })),
      },
      reporter,
      snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
    })

    expect(await scheduler.beat()).toBeNull()
    expect(reporter.size()).toBe(0)
  })

  it('si reporta un latido rechazado por el servidor', async () => {
    const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
    const scheduler = createHeartbeatScheduler({
      api: {
        recordScan: vi.fn(),
        syncScanBatch: vi.fn(),
        fetchRoster: vi.fn(),
        sendHeartbeat: vi.fn(async () => ({
          outcome: 'failed' as const,
          cause: 'unauthorized' as const,
          httpStatus: 401,
        })),
      },
      reporter,
      snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
    })

    await scheduler.beat()

    expect(reporter.pending()[0]).toMatchObject({
      code: 'kiosk.heartbeat.failed',
      context: { cause: 'unauthorized', http_status: 401 },
    })
  })

  it('expone los errores de cliente pendientes de campo en el contrato', async () => {
    const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
    reporter.report('kiosk.camera.unavailable', {})
    const scheduler = createHeartbeatScheduler({
      api: apiReturning(new Date().toISOString()),
      reporter,
      snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
    })

    expect(scheduler.pendingClientErrors()).toHaveLength(1)
    // Y NO viajan todavia en el cuerpo: `KioskHeartbeatRequest` declara
    // `additionalProperties: false` y no tiene donde ponerlos (RF-PD-15, 5.12).
    expect(buildHeartbeatBody({ appVersion: '1.4.2', pendingQueueSize: 0 })).toEqual({
      app_version: '1.4.2',
      pending_queue_size: 0,
    })
  })

  it('para el temporizador al detenerse', () => {
    vi.useFakeTimers()
    const api = apiReturning(new Date().toISOString())
    const scheduler = createHeartbeatScheduler({
      api,
      reporter: createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' }),
      snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
      intervalMs: 1000,
    })

    scheduler.start()
    vi.advanceTimersByTime(3500)
    scheduler.stop()
    vi.advanceTimersByTime(10_000)

    expect(api.sendHeartbeat).toHaveBeenCalledTimes(4) // 1 al arrancar + 3 ciclos
    vi.useRealTimers()
  })
})
