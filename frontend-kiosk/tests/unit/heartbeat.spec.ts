import { describe, expect, it, vi } from 'vitest'
import type { ApiClient } from '@/shared/api/client'
import type { ClientErrorEvent } from '@/shared/telemetry/errorReporter'
import { createErrorReporter } from '@/shared/telemetry/errorReporter'
import {
  buildHeartbeatBody,
  clockSkewSeconds,
  createHeartbeatScheduler,
} from '@/shared/telemetry/heartbeat'
import { fixedClock } from '@/shared/time/clock'

function apiReturning(serverTime: string, clientErrorsAccepted = 0): ApiClient {
  return {
    recordScan: vi.fn(),
    recordPinScan: vi.fn(),
    syncScanBatch: vi.fn(),
    fetchRoster: vi.fn(),
    sendHeartbeat: vi.fn(async () => ({
      outcome: 'ok' as const,
      data: { server_time: serverTime, client_errors_accepted: clientErrorsAccepted },
    })),
    // El latido no empareja nada: estos dos no los usa ninguna prueba de aqui.
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),

    fetchBranding: vi.fn(),
  }
}

/** Un `ApiClient` cuyo latido siempre falla, con la causa y el estado que se le indique. */
function apiFailingHeartbeat(
  cause: 'network' | 'offline' | 'unauthorized',
  httpStatus = 0,
): ApiClient {
  return {
    recordScan: vi.fn(),
    recordPinScan: vi.fn(),
    syncScanBatch: vi.fn(),
    fetchRoster: vi.fn(),
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    fetchBranding: vi.fn(),
    sendHeartbeat: vi.fn(async () => ({ outcome: 'failed' as const, cause, httpStatus })),
  }
}

/**
 * Un `ApiClient` cuyo latido responde `400` (RF-PD-15, tarea 5.12, decision
 * 7). `invalidFields` reproduce el `ValidationProblem.errors` que expone
 * `ApiClient` (`client.ts` -> `invalidFieldsOf`): el nombre de los campos que
 * el servidor considera invalidos, no un texto libre.
 */
function apiRejecting400(invalidFields?: readonly string[]): ApiClient {
  return {
    recordScan: vi.fn(),
    recordPinScan: vi.fn(),
    syncScanBatch: vi.fn(),
    fetchRoster: vi.fn(),
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    fetchBranding: vi.fn(),
    sendHeartbeat: vi.fn(async () => ({
      outcome: 'failed' as const,
      cause: 'server' as const,
      httpStatus: 400,
      ...(invalidFields === undefined ? {} : { invalidFields }),
    })),
  }
}

function clientError(overrides: Partial<ClientErrorEvent> = {}): ClientErrorEvent {
  return {
    code: 'kiosk.camera.unavailable',
    occurred_at: '2026-09-09T05:58:31.000Z',
    app_version: '2.2.0',
    device_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
    context: {},
    ...overrides,
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

  describe('client_errors en el cuerpo (RF-PD-15, tarea 5.12)', () => {
    it('omite la clave cuando no hay nada pendiente', () => {
      const body = buildHeartbeatBody({ appVersion: '1.4.2', pendingQueueSize: 0 }, [])
      expect('client_errors' in body).toBe(false)
    })

    it('traduce ClientErrorEvent a la forma del contrato, SIN device_id', () => {
      const body = buildHeartbeatBody({ appVersion: '2.2.0', pendingQueueSize: 0 }, [
        clientError({
          code: 'kiosk.camera.stream_lost',
          context: { cause: 'NotReadableError' },
        }),
      ])

      expect(body.client_errors).toEqual([
        {
          code: 'kiosk.camera.stream_lost',
          occurred_at: '2026-09-09T05:58:31.000Z',
          app_version: '2.2.0',
          context: { cause: 'NotReadableError' },
        },
      ])
      // Ni rastro de `device_id`: el servidor lo sabe por el token (regla dura 21).
      expect(body.client_errors?.[0]).not.toHaveProperty('device_id')
    })

    it('tiene techo de 50, los mas antiguos primero', () => {
      const sixty = Array.from({ length: 60 }, (_, index) =>
        clientError({ occurred_at: `2026-09-09T05:${String(index).padStart(2, '0')}:00.000Z` }),
      )

      const body = buildHeartbeatBody({ appVersion: '1.4.2', pendingQueueSize: 0 }, sixty)

      expect(body.client_errors).toHaveLength(50)
      expect(body.client_errors?.[0]?.occurred_at).toBe('2026-09-09T05:00:00.000Z')
      expect(body.client_errors?.[49]?.occurred_at).toBe('2026-09-09T05:49:00.000Z')
    })
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
      api: apiFailingHeartbeat('offline'),
      reporter,
      snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
    })

    expect(await scheduler.beat()).toBeNull()
    expect(reporter.size()).toBe(0)
  })

  it('si reporta un latido rechazado por el servidor', async () => {
    const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
    const scheduler = createHeartbeatScheduler({
      api: apiFailingHeartbeat('unauthorized', 401),
      reporter,
      snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
    })

    await scheduler.beat()

    expect(reporter.pending()[0]).toMatchObject({
      code: 'kiosk.heartbeat.failed',
      context: { cause: 'unauthorized', http_status: 401 },
    })
  })

  describe('drenaje del buffer de errores (RF-PD-15, tarea 5.12)', () => {
    it('un latido `ok` vacia solo lo que el servidor dice haber aceptado', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      reporter.report('kiosk.camera.unavailable', {})
      reporter.report('kiosk.wake_lock.denied', {})
      reporter.report('kiosk.audio.blocked', {})

      const scheduler = createHeartbeatScheduler({
        api: apiReturning('2026-09-09T06:00:00.000Z', 2),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
        // Reloj del dispositivo IGUAL al `server_time`: desfase cero, para que
        // esta prueba compruebe solo el drenaje y no se contamine con un
        // `kiosk.clock.skew_detected` añadido por la diferencia con el reloj
        // real de la maquina que ejecuta la prueba.
        clock: fixedClock(new Date('2026-09-09T06:00:00.000Z')),
      })

      await scheduler.beat()

      // Se han descartado los DOS mas antiguos, no un numero cualquiera.
      expect(reporter.size()).toBe(1)
      expect(reporter.pending()[0]?.code).toBe('kiosk.audio.blocked')
    })

    it('un latido `ok` con `client_errors_accepted: 0` no toca el buffer', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      reporter.report('kiosk.camera.unavailable', {})

      const scheduler = createHeartbeatScheduler({
        api: apiReturning('2026-09-09T06:00:00.000Z', 0),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
        clock: fixedClock(new Date('2026-09-09T06:00:00.000Z')),
      })

      await scheduler.beat()

      expect(reporter.size()).toBe(1)
    })

    it('un latido que NO contesta no vacia nada, aunque hubiera errores pendientes', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      reporter.report('kiosk.camera.unavailable', {})

      const scheduler = createHeartbeatScheduler({
        api: apiFailingHeartbeat('network', 0),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
      })

      await scheduler.beat()

      // El error de camara sigue ahi Y ademas se sumo uno de latido fallido.
      expect(reporter.size()).toBe(2)
      expect(reporter.pending()[0]?.code).toBe('kiosk.camera.unavailable')
    })

    it('un `400` que NOMBRA `client_errors` vacia el buffer para que el siguiente latido pase', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      reporter.report('kiosk.camera.unavailable', {})
      reporter.report('kiosk.wake_lock.denied', {})

      const scheduler = createHeartbeatScheduler({
        api: apiRejecting400(['client_errors']),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
      })

      await scheduler.beat()

      // Se descartan los DOS que se intento enviar...
      expect(reporter.pending().some((event) => event.code === 'kiosk.camera.unavailable')).toBe(
        false,
      )
      expect(reporter.pending().some((event) => event.code === 'kiosk.wake_lock.denied')).toBe(
        false,
      )
      // ...y queda dicho por que, para que la tablet no se quede muda para siempre.
      expect(reporter.pending()[0]).toMatchObject({
        code: 'kiosk.heartbeat.failed',
        context: { cause: 'client_errors_rejected', http_status: 400 },
      })
      expect(reporter.size()).toBe(1)
    })

    it('un `400` que nombra OTRO campo (`app_version`, `pending_queue_size`…) CONSERVA el buffer', async () => {
      // Este es el caso que distingue esta revision: el `400` puede venir de
      // cualquier campo del cuerpo, y solo `client_errors` justifica vaciar
      // el buffer. Vaciar por cualquier `400` borraria errores que nunca
      // llegaron a rechazarse.
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      reporter.report('kiosk.camera.unavailable', {})
      reporter.report('kiosk.wake_lock.denied', {})

      const scheduler = createHeartbeatScheduler({
        api: apiRejecting400(['app_version']),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
      })

      await scheduler.beat()

      // Los DOS siguen ahi, mas el nuevo `kiosk.heartbeat.failed` del rechazo.
      expect(reporter.pending().some((event) => event.code === 'kiosk.camera.unavailable')).toBe(
        true,
      )
      expect(reporter.pending().some((event) => event.code === 'kiosk.wake_lock.denied')).toBe(true)
      expect(reporter.size()).toBe(3)
      expect(reporter.pending()[2]).toMatchObject({
        code: 'kiosk.heartbeat.failed',
        context: { cause: 'server', http_status: 400 },
      })
    })

    it('un `400` SIN `invalidFields` reconocibles (cuerpo sin `errors`, u otro formato) CONSERVA el buffer', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      reporter.report('kiosk.camera.unavailable', {})

      const scheduler = createHeartbeatScheduler({
        api: apiRejecting400(undefined),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
      })

      await scheduler.beat()

      expect(reporter.pending().some((event) => event.code === 'kiosk.camera.unavailable')).toBe(
        true,
      )
    })

    it('un `400` SIN errores pendientes no se atribuye a `client_errors`: no es lo que fallo', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })

      const scheduler = createHeartbeatScheduler({
        api: apiRejecting400(['client_errors']),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
      })

      await scheduler.beat()

      expect(reporter.pending()[0]).toMatchObject({
        code: 'kiosk.heartbeat.failed',
        context: { cause: 'server', http_status: 400 },
      })
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
