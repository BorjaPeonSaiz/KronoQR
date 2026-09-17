import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ApiClient } from '@/shared/api/client'
import {
  readBreakClockingEnabled,
  readClockSkewToleranceSeconds,
  readServiceCodeHash,
} from '@/shared/telemetry/deviceIdentity'
import type { ClientErrorEvent } from '@/shared/telemetry/errorReporter'
import { createErrorReporter } from '@/shared/telemetry/errorReporter'
import {
  buildHeartbeatBody,
  clockSkewSeconds,
  createHeartbeatScheduler,
  getLastHeartbeatResult,
} from '@/shared/telemetry/heartbeat'
import { fixedClock } from '@/shared/time/clock'

/** `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` por defecto (doc 02, Anexo B), en segundos. */
const DEFAULT_TOLERANCE_SECONDS = 15 * 60

function apiReturning(
  serverTime: string,
  clientErrorsAccepted = 0,
  serviceCodeHash: string | null = null,
  breakClockingEnabled = false,
  clockSkewToleranceSeconds = DEFAULT_TOLERANCE_SECONDS,
): ApiClient {
  return {
    recordScan: vi.fn(),
    recordPinScan: vi.fn(),
    syncScanBatch: vi.fn(),
    fetchRoster: vi.fn(),
    sendHeartbeat: vi.fn(async () => ({
      outcome: 'ok' as const,
      data: {
        server_time: serverTime,
        client_errors_accepted: clientErrorsAccepted,
        service_code_hash: serviceCodeHash,
        break_clocking_enabled: breakClockingEnabled,
        clock_skew_tolerance_seconds: clockSkewToleranceSeconds,
      },
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
  // Cualquier `scheduler.beat()` con exito de ESTE fichero cachea los dos
  // ajustes de la tarea 3.5 (mismo patron que `service_code_hash`): sin esto,
  // una prueba que corre despues de otra hereda lo que la anterior escribio en
  // el `localStorage` compartido de jsdom, y el describe de mas abajo que
  // comprueba «sin latido todavia» dejaria de ser verdad.
  afterEach(() => {
    localStorage.removeItem('kronoqr.kiosk.break_clocking_enabled')
    localStorage.removeItem('kronoqr.kiosk.clock_skew_tolerance_seconds')
  })

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

  describe('bateria en el cuerpo (RF-PA-07, tarea 3.3)', () => {
    it('omite los dos campos cuando el navegador no ofrece Battery Status API', () => {
      const body = buildHeartbeatBody({ appVersion: '1.4.2', pendingQueueSize: 0 })
      expect('battery_level' in body).toBe(false)
      expect('battery_charging' in body).toBe(false)
    })

    it('incluye nivel y carga cuando se conocen', () => {
      const body = buildHeartbeatBody({
        appVersion: '1.4.2',
        pendingQueueSize: 0,
        batteryLevel: 83,
        batteryCharging: true,
      })
      expect(body).toMatchObject({ battery_level: 83, battery_charging: true })
    })

    it('los dos son independientes: se puede conocer el nivel sin saber si carga', () => {
      const body = buildHeartbeatBody({
        appVersion: '1.4.2',
        pendingQueueSize: 0,
        batteryLevel: 15,
      })
      expect(body).toMatchObject({ battery_level: 15 })
      expect('battery_charging' in body).toBe(false)
    })
  })

  describe('huella del codigo de servicio y ultimo resultado (RF-KI-08, tarea 3.3)', () => {
    afterEach(() => {
      localStorage.removeItem('kronoqr.kiosk.service_code_hash')
    })

    it('cachea la huella que devuelve el servidor en cada 200', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      const scheduler = createHeartbeatScheduler({
        api: apiReturning('2026-09-16T06:00:00.000Z', 0, 'a1b2c3'),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
        clock: fixedClock(new Date('2026-09-16T06:00:00.000Z')),
      })

      await scheduler.beat()

      expect(readServiceCodeHash()).toBe('a1b2c3')
    })

    it('`null` BORRA la huella cacheada: la instalacion ha quitado el codigo', async () => {
      localStorage.setItem('kronoqr.kiosk.service_code_hash', 'huella-vieja')
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      const scheduler = createHeartbeatScheduler({
        api: apiReturning('2026-09-16T06:00:00.000Z', 0, null),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
        clock: fixedClock(new Date('2026-09-16T06:00:00.000Z')),
      })

      await scheduler.beat()

      expect(readServiceCodeHash()).toBeNull()
    })

    it('conserva el ultimo resultado (hora y desfase) para la pantalla de diagnostico', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      const scheduler = createHeartbeatScheduler({
        api: apiReturning('2026-09-16T06:00:20.000Z'),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
        clock: fixedClock(new Date('2026-09-16T06:00:00.000Z')),
      })

      await scheduler.beat()

      expect(getLastHeartbeatResult()).toEqual({
        beatAt: '2026-09-16T06:00:00.000Z',
        skewSeconds: -20,
      })
    })
  })

  describe('fichaje de pausa y umbral de desfase (RF-AT-12, RF-AT-10, tarea 3.5)', () => {
    it('cachea los dos ajustes de cada 200, con el mismo patron que la huella del codigo', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      const scheduler = createHeartbeatScheduler({
        api: apiReturning('2026-09-17T06:00:00.000Z', 0, null, true, 600),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
        clock: fixedClock(new Date('2026-09-17T06:00:00.000Z')),
      })

      await scheduler.beat()

      expect(readBreakClockingEnabled()).toBe(true)
      expect(readClockSkewToleranceSeconds()).toBe(600)
    })

    it('sin latido todavia, el fichaje de pausa esta desactivado y no hay umbral', () => {
      expect(readBreakClockingEnabled()).toBe(false)
      expect(readClockSkewToleranceSeconds()).toBeNull()
    })

    it('avisa a `onSettingsUpdated` en CADA 200, no solo al primero', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      const updates: Array<{ breakClockingEnabled: boolean; clockSkewToleranceSeconds: number }> =
        []
      const scheduler = createHeartbeatScheduler({
        api: apiReturning('2026-09-17T06:00:00.000Z', 0, null, true, 300),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
        clock: fixedClock(new Date('2026-09-17T06:00:00.000Z')),
        onSettingsUpdated: (settings) => updates.push(settings),
      })

      await scheduler.beat()

      expect(updates).toEqual([{ breakClockingEnabled: true, clockSkewToleranceSeconds: 300 }])
    })

    it('usa el umbral de ESTE latido para decidir si avisa del desfase, no una constante', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      // 90 s de desfase: por debajo de la vieja constante de 15 min, pero por
      // encima de un umbral de instalacion mas estricto (60 s, el minimo del
      // contrato).
      const scheduler = createHeartbeatScheduler({
        api: apiReturning('2026-09-17T06:00:00.000Z', 0, null, false, 60),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
        clock: fixedClock(new Date('2026-09-17T06:01:30.000Z')),
      })

      const skew = await scheduler.beat()

      expect(skew).toBe(90)
      expect(reporter.pending()[0]?.code).toBe('kiosk.clock.skew_detected')
    })

    it('con un umbral holgado, el mismo desfase NO avisa', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      const scheduler = createHeartbeatScheduler({
        api: apiReturning('2026-09-17T06:00:00.000Z', 0, null, false, 900),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
        clock: fixedClock(new Date('2026-09-17T06:01:30.000Z')),
      })

      await scheduler.beat()

      expect(reporter.size()).toBe(0)
    })

    // Un solo operador (revision de la segunda vuelta): aqui vivia un `>=`
    // distinto del `>` estricto de `settleFrom.ts` y del servidor. Un desfase
    // EXACTAMENTE igual al umbral no es "superarlo".
    it('no avisa con un desfase exactamente igual al umbral', async () => {
      const reporter = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd' })
      const scheduler = createHeartbeatScheduler({
        api: apiReturning('2026-09-17T06:00:00.000Z', 0, null, false, 90),
        reporter,
        snapshot: () => ({ appVersion: '1.4.2', pendingQueueSize: 0 }),
        // 90 s de desfase exactos contra un umbral de 90 s.
        clock: fixedClock(new Date('2026-09-17T06:01:30.000Z')),
      })

      const skew = await scheduler.beat()

      expect(skew).toBe(90)
      expect(reporter.size()).toBe(0)
    })
  })
})
