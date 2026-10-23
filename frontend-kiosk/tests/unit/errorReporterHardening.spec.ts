// R3-KI-02 / R3-KI-03 / R3-KI-04 (RF-PD-15, regla dura 21): el texto de un error
// viaja saneado, el buffer sobrevive a un reinicio, y una lectura que no es
// tarjeta deja rastro tecnico sin su contenido.

import 'fake-indexeddb/auto'
import { describe, expect, it, vi } from 'vitest'
import { createMalformedReadReporter } from '@/features/scan/application/malformedReadReporter'
import { createScanPipeline } from '@/features/scan/application/scanPipeline'
import { createDexieErrorBufferStorage } from '@/shared/telemetry/errorBufferStorage'
import { createErrorReporter, sanitizeContext } from '@/shared/telemetry/errorReporter'
import { errorMessageOf, scrubErrorMessage } from '@/shared/telemetry/errorType'
import { fixedClock } from '@/shared/time/clock'

describe('R3-KI-02 — el `message` sale saneado', () => {
  it.each([
    [
      "Cannot read properties of undefined (reading 'name')",
      'Cannot read properties of undefined (reading …)',
    ],
    ['Falla para "Ana Ruiz" en el turno', 'Falla para … en el turno'],
    ['envio a ana.ruiz@hotel.test rechazado', 'envio a [email] rechazado'],
    ['DNI 12345678 y telefono 612 345 678', 'DNI [num] y telefono [num]'],
    ['tarjeta FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa leida', 'tarjeta [token] leida'],
    ['scan 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90 fallo', 'scan [id] fallo'],
    ['token abcdefghijklmnopqrstuvwxyz0123456789 caducado', 'token [token] caducado'],
  ])('%s', (input, expected) => {
    expect(scrubErrorMessage(input)).toBe(expected)
  })

  it('un codigo tecnico corto pasa intacto', () => {
    for (const code of [
      'confirm_not_persisted',
      'network',
      'NotAllowedError',
      'HTTP 503',
      '1280x720',
    ]) {
      expect(scrubErrorMessage(code)).toBe(code)
    }
  })

  it('errorMessageOf saca el texto ya saneado, de un Error o de una cadena', () => {
    expect(errorMessageOf(new Error('fallo con "Lucia Garcia"'))).toBe('fallo con …')
    expect(errorMessageOf('hola lucia@hotel.test')).toBe('hola [email]')
    expect(errorMessageOf({})).toBe('')
  })

  it('sanitizeContext sanea `message` aunque quien reporta no lo haya hecho', () => {
    const context = sanitizeContext({ message: 'error con "Lucia Garcia" y lucia@hotel.test' })

    expect(context['message']).toBe('error con … y [email]')
  })
})

describe('R3-KI-03 — el buffer de errores sobrevive a un reinicio', () => {
  let counter = 0
  function freshStorage() {
    counter += 1
    return createDexieErrorBufferStorage(`kronoqr-test-errors-${counter}`)
  }
  function reporterOn(storage: ReturnType<typeof freshStorage>, at = '2026-08-14T05:02:00.000Z') {
    return createErrorReporter({
      appVersion: '2.3.0',
      deviceId: 'dev-1',
      now: () => new Date(at),
      storage,
    })
  }

  it('lo reportado antes del reinicio vuelve al buffer, delante de lo nuevo', async () => {
    const storage = freshStorage()
    const before = reporterOn(storage)
    before.report('kiosk.camera.unavailable', { error_type: 'NotReadableError' })
    before.report('kiosk.unhandled_error', { message: 'TypeError', scope: 'vue' })
    await vi.waitFor(async () => expect(await storage.load()).toHaveLength(2))

    // La tablet se reinicia: un reporter nuevo sobre el mismo almacen.
    const after = reporterOn(storage, '2026-08-14T05:03:00.000Z')
    after.report('kiosk.wake_lock.denied', {})
    await after.restore()

    expect(after.pending().map((event) => event.code)).toEqual([
      'kiosk.camera.unavailable',
      'kiosk.unhandled_error',
      'kiosk.wake_lock.denied',
    ])
  })

  it('restaurar dos veces no duplica', async () => {
    const storage = freshStorage()
    const before = reporterOn(storage)
    before.report('kiosk.heartbeat.failed', { cause: 'network' })
    await vi.waitFor(async () => expect(await storage.load()).toHaveLength(1))

    const after = reporterOn(storage)
    await after.restore()
    await after.restore()

    expect(after.size()).toBe(1)
  })

  it('lo confirmado por el servidor se borra tambien del disco', async () => {
    const storage = freshStorage()
    const sut = reporterOn(storage)
    sut.report('kiosk.heartbeat.failed', { cause: 'network' })
    sut.report('kiosk.wake_lock.denied', {})
    await vi.waitFor(async () => expect(await storage.load()).toHaveLength(2))

    sut.acknowledge(1)
    await vi.waitFor(async () => expect(await storage.load()).toHaveLength(1))
    sut.acknowledge(1)
    await vi.waitFor(async () => expect(await storage.load()).toHaveLength(0))

    const after = reporterOn(storage)
    await after.restore()
    expect(after.size()).toBe(0)
  })

  it('respeta el techo del buffer al restaurar (se conservan los mas recientes)', async () => {
    const storage = freshStorage()
    const before = createErrorReporter({ appVersion: 'v', deviceId: 'd', maxBuffered: 5, storage })
    for (let index = 0; index < 5; index += 1) {
      before.report('kiosk.scanner.watchdog_restart', { attempt: index })
    }
    await vi.waitFor(async () => expect(await storage.load()).toHaveLength(5))

    const after = createErrorReporter({ appVersion: 'v', deviceId: 'd', maxBuffered: 3, storage })
    await after.restore()

    expect(after.pending().map((event) => event.context['attempt'])).toEqual([2, 3, 4])
  })

  it('un almacen que falla no estorba: ni al reportar ni al restaurar', async () => {
    const broken = {
      load: vi.fn(async () => {
        throw new Error('InvalidStateError')
      }),
      save: vi.fn(async () => {
        throw new Error('QuotaExceededError')
      }),
    }
    const sut = createErrorReporter({ appVersion: 'v', deviceId: 'd', storage: broken })

    sut.report('kiosk.camera.unavailable', {})
    await expect(sut.restore()).resolves.toBeUndefined()

    expect(sut.size()).toBe(1)
  })

  it('una fila corrupta del disco se descarta, y su contexto se vuelve a sanear', async () => {
    const storage = {
      load: async () =>
        [
          { nonsense: true },
          {
            code: 'kiosk.camera.unavailable',
            occurred_at: '2026-08-14T05:00:00.000Z',
            app_version: '2.3.0',
            device_id: 'dev-1',
            context: { name: 'Lucia', error_type: 'NotReadableError' },
          },
        ] as never,
      save: async () => undefined,
    }
    const sut = createErrorReporter({ appVersion: 'v', deviceId: 'd', storage })

    await sut.restore()

    expect(sut.pending()).toHaveLength(1)
    expect(sut.pending()[0]?.context).toEqual({ error_type: 'NotReadableError' })
  })
})

describe('R3-KI-04 — `kiosk.scan.malformed_payload`', () => {
  it('el pipeline avisa de una lectura que no es tarjeta, solo con su longitud', () => {
    const lengths: number[] = []
    const pipeline = createScanPipeline({
      submission: { submit: async () => ({ kind: 'deferred' }) },
      deviceId: 'dev-1',
      onMalformedPayload: (length) => lengths.push(length),
    })

    pipeline.handleDecoded('https://wifi.example/clave-secreta')

    expect(lengths).toEqual(['https://wifi.example/clave-secreta'.length])
  })

  it('una repeticion inmediata no vuelve a avisar, y una tarjeta valida tampoco', () => {
    const onMalformedPayload = vi.fn()
    const pipeline = createScanPipeline({
      submission: { submit: async () => ({ kind: 'deferred' }) },
      deviceId: 'dev-1',
      onMalformedPayload,
    })

    pipeline.handleDecoded('no-es-una-tarjeta')
    pipeline.handleDecoded('no-es-una-tarjeta')
    pipeline.handleDecoded('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa')

    expect(onMalformedPayload).toHaveBeenCalledTimes(1)
  })

  it('el informe sale como mucho una vez por minuto y suma lo suprimido', () => {
    const reports: Array<{ length: number; count: number; message: string }> = []
    let nowMs = Date.parse('2026-08-14T05:00:00.000Z')
    const report = createMalformedReadReporter((context) => reports.push({ ...context }), {
      clock: { now: () => new Date(nowMs) },
    })

    report(10)
    nowMs += 5_000
    report(11)
    nowMs += 5_000
    report(12)
    expect(reports).toEqual([{ length: 10, count: 1, message: 'malformed_payload' }])

    nowMs += 60_000
    report(13)
    expect(reports[1]).toEqual({ length: 13, count: 3, message: 'malformed_payload' })
  })

  it('el informe no lleva el contenido leido', () => {
    const reports: unknown[] = []
    const report = createMalformedReadReporter((context) => reports.push(context), {
      clock: fixedClock(new Date('2026-08-14T05:00:00.000Z')),
    })

    report('https://wifi.example/clave-secreta'.length)

    expect(JSON.stringify(reports)).not.toContain('wifi')
  })
})
