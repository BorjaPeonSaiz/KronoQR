// Transporte del buffer de errores de cliente hacia `error_events` (tarea
// 5.12, RF-PD-15).
//
// Lo que se afirma: que sin sesion no se manda nada (ni al instalar ni con el
// tiempo), que `notifyAuthenticated()` vacia de inmediato sin esperar al
// intervalo, que este modulo NO sondea la sesion por su cuenta (no hay
// temporizador corto: sin llamar a `notifyAuthenticated()`, nada se dispara
// hasta el intervalo periodico), que un fallo de red no se reporta a si mismo
// ni reintenta agresivo, y que `pagehide`/`visibilitychange: hidden` mandan lo
// pendiente con `keepalive` sin confirmarlo.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createWebErrorReporter } from '../../src/clientErrors'
import { installClientErrorTransport } from '../../src/clientErrorTransport'
import { setAuthTokenProvider, setUnauthenticatedHandler } from '../../src/http'
import { jsonResponse, stubFetch } from './support/harness'

const fixedNow = () => new Date('2026-09-09T06:00:00Z')

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(fixedNow())
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
  setAuthTokenProvider(() => null)
  setUnauthenticatedHandler(() => {})
})

describe('installClientErrorTransport', () => {
  it('sin sesion no manda nada, ni al instalar ni con el tiempo', async () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })
    reporter.report('web.vue_error', { message: 'boom' })
    const spy = stubFetch(() => jsonResponse({ accepted: 1 }))

    const transport = installClientErrorTransport({ reporter, isAuthenticated: () => false })

    await vi.advanceTimersByTimeAsync(120_000)

    expect(spy).not.toHaveBeenCalled()
    expect(reporter.size()).toBe(1)

    transport.stop()
  })

  it('instalar con sesion ya activa no manda nada por si solo: no hay sondeo interno', async () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })
    reporter.report('web.vue_error', {})
    const spy = stubFetch(() => jsonResponse({ accepted: 1 }))

    const transport = installClientErrorTransport({ reporter, isAuthenticated: () => true })

    // Sin llamar a `notifyAuthenticated()`, nada se dispara hasta el
    // intervalo periodico: este modulo no vigila la sesion por su cuenta.
    await vi.advanceTimersByTimeAsync(5_000)

    expect(spy).not.toHaveBeenCalled()

    transport.stop()
  })

  it('notifyAuthenticated() vacia de inmediato, sin esperar al intervalo', async () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })
    reporter.report('web.vue_error', { message: 'boom' })
    const spy = stubFetch(() => jsonResponse({ accepted: 1 }))

    const transport = installClientErrorTransport({
      reporter,
      isAuthenticated: () => true,
      intervalMs: 60_000,
    })

    transport.notifyAuthenticated()
    await vi.advanceTimersByTimeAsync(0)

    expect(spy).toHaveBeenCalledTimes(1)
    expect(reporter.size()).toBe(0)

    const [url, init] = spy.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/v1/client-errors')
    const body = JSON.parse(String(init.body)) as { errors: unknown[] }
    expect(body.errors).toEqual([
      {
        code: 'web.vue_error',
        occurred_at: fixedNow().toISOString(),
        app_version: '1.0.0',
        context: { message: 'boom' },
      },
    ])
    // Nunca `app` ni `device_id`: el servidor decide el origen por el token.
    expect(body.errors[0]).not.toHaveProperty('app')
    expect(body.errors[0]).not.toHaveProperty('device_id')

    transport.stop()
  })

  it('notifyAuthenticated() sin sesion no manda nada: flush() sigue comprobando isAuthenticated()', async () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })
    reporter.report('web.vue_error', {})
    const spy = stubFetch(() => jsonResponse({ accepted: 1 }))

    const transport = installClientErrorTransport({ reporter, isAuthenticated: () => false })

    transport.notifyAuthenticated()
    await vi.advanceTimersByTimeAsync(0)

    expect(spy).not.toHaveBeenCalled()

    transport.stop()
  })

  it('cada intervalo, si queda algo pendiente', async () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })
    const spy = stubFetch(() => jsonResponse({ accepted: 0 }))

    const transport = installClientErrorTransport({
      reporter,
      isAuthenticated: () => true,
      intervalMs: 10_000,
    })

    await vi.advanceTimersByTimeAsync(0)
    expect(spy).not.toHaveBeenCalled() // nada pendiente todavia

    reporter.report('web.unhandled_error', {})
    await vi.advanceTimersByTimeAsync(10_000)

    expect(spy).toHaveBeenCalledTimes(1)

    transport.stop()
  })

  it('maximo 50 por envio: lo que sobra espera al siguiente tick', async () => {
    const reporter = createWebErrorReporter({
      app: 'admin',
      appVersion: '1.0.0',
      now: fixedNow,
      maxBuffered: 60,
    })
    for (let index = 0; index < 55; index += 1) {
      reporter.report('web.unhandled_error', { seq: index })
    }
    const spy = stubFetch(() => jsonResponse({ accepted: 50 }))

    const transport = installClientErrorTransport({ reporter, isAuthenticated: () => true })

    transport.notifyAuthenticated()
    await vi.advanceTimersByTimeAsync(0)

    const [, init] = spy.mock.calls[0] as [string, RequestInit]
    const body = JSON.parse(String(init.body)) as { errors: unknown[] }

    expect(body.errors).toHaveLength(50)
    expect(reporter.size()).toBe(5)

    transport.stop()
  })

  it('un fallo de red se ignora, no reintenta en el acto ni se reporta a si mismo', async () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })
    reporter.report('web.vue_error', {})
    let calls = 0
    stubFetch(() => {
      calls += 1
      throw new Error('red caida')
    })

    const transport = installClientErrorTransport({
      reporter,
      isAuthenticated: () => true,
      intervalMs: 5_000,
    })

    transport.notifyAuthenticated()
    await vi.advanceTimersByTimeAsync(0)
    expect(calls).toBe(1)
    // El fallo no se reporto a si mismo: sigue habiendo un solo evento en el buffer.
    expect(reporter.size()).toBe(1)

    await vi.advanceTimersByTimeAsync(5_000)
    expect(calls).toBe(2) // se reintenta en el SIGUIENTE tick, no antes

    transport.stop()
  })

  it('en pagehide manda lo pendiente con keepalive y no lo confirma', async () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })
    const spy = stubFetch(() => jsonResponse({ accepted: 1 }))

    const transport = installClientErrorTransport({ reporter, isAuthenticated: () => true })
    reporter.report('web.unhandled_error', {})

    window.dispatchEvent(new Event('pagehide'))
    await vi.advanceTimersByTimeAsync(0)

    expect(spy).toHaveBeenCalledTimes(1)
    const [, init] = spy.mock.calls[0] as [string, RequestInit]
    expect(init.keepalive).toBe(true)
    // No se confirma: el buffer sigue con el evento hasta que el propio
    // servidor lo confirme en un envio normal.
    expect(reporter.size()).toBe(1)

    transport.stop()
  })

  it('sin nada pendiente, ocultarse no manda ninguna peticion', async () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })
    const spy = stubFetch(() => jsonResponse({ accepted: 0 }))

    const transport = installClientErrorTransport({ reporter, isAuthenticated: () => true })

    window.dispatchEvent(new Event('pagehide'))
    await vi.advanceTimersByTimeAsync(0)

    expect(spy).not.toHaveBeenCalled()

    transport.stop()
  })

  it('stop() detiene el intervalo periodico y los listeners de pagehide/visibilitychange', async () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })
    const spy = stubFetch(() => jsonResponse({ accepted: 0 }))

    const transport = installClientErrorTransport({
      reporter,
      isAuthenticated: () => true,
      intervalMs: 1_000,
    })

    transport.stop()
    reporter.report('web.vue_error', {})
    await vi.advanceTimersByTimeAsync(5_000)
    window.dispatchEvent(new Event('pagehide'))
    await vi.advanceTimersByTimeAsync(0)

    expect(spy).not.toHaveBeenCalled()
  })
})
