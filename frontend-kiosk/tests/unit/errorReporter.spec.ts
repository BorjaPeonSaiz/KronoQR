import { describe, expect, it } from 'vitest'
import { createErrorReporter, sanitizeContext } from '@/shared/telemetry/errorReporter'

function reporter() {
  return createErrorReporter({
    appVersion: '1.4.2',
    deviceId: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
    now: () => new Date('2026-08-14T05:02:00.000Z'),
  })
}

describe('reporte de errores del cliente', () => {
  it('lleva codigo, version, device_id y contexto tecnico', () => {
    const sut = reporter()
    sut.report('kiosk.camera.unavailable', { error_type: 'NotReadableError' })

    expect(sut.pending()[0]).toEqual({
      code: 'kiosk.camera.unavailable',
      occurred_at: '2026-08-14T05:02:00.000Z',
      app_version: '1.4.2',
      device_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
      context: { error_type: 'NotReadableError' },
    })
  })

  it('NUNCA deja pasar datos personales (regla dura 21)', () => {
    const sanitized = sanitizeContext({
      employee_display_name: 'Lucia Garcia',
      name: 'Lucia',
      qr_payload: 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
      token: 'secreto',
      token_hash: '6b86b273ff34fce1',
      pin: '1234',
      email: 'lucia@example.test',
      dni: '00000000T',
      // Lo tecnico si pasa.
      error_type: 'NotAllowedError',
      retry_count: 3,
      online: false,
    })

    expect(sanitized).toEqual({ error_type: 'NotAllowedError', retry_count: 3, online: false })
  })

  it('descarta estructuras enteras: son la via por la que se cuela un objeto con PII', () => {
    expect(
      sanitizeContext({
        employee: { first: 'Lucia' },
        list: ['Lucia', 'Juan'],
        nothing: null,
        callback: () => undefined,
        ok: 'plain',
      }),
    ).toEqual({ ok: 'plain' })
  })

  it('recorta los textos largos y descarta numeros no finitos', () => {
    const sanitized = sanitizeContext({ message: 'x'.repeat(500), ratio: Number.NaN })
    expect(String(sanitized['message']).length).toBe(200)
    expect(sanitized['ratio']).toBeUndefined()
  })

  it('tiene techo: el bucle de decodificacion corre ocho horas', () => {
    const sut = createErrorReporter({ appVersion: '1.4.2', deviceId: 'd', maxBuffered: 3 })
    for (let index = 0; index < 50; index += 1) {
      sut.report('kiosk.scanner.watchdog_restart', { attempt: index })
    }

    expect(sut.size()).toBe(3)
    // Se conservan los MAS RECIENTES: el ultimo fallo describe mejor el estado
    // actual de la tablet que el primero de la manana.
    expect(sut.pending()[2]?.context['attempt']).toBe(49)
  })

  it('solo descarta lo confirmado', () => {
    const sut = reporter()
    sut.report('kiosk.heartbeat.failed', { cause: 'network' })
    sut.report('kiosk.wake_lock.denied', {})

    sut.acknowledge(1)

    expect(sut.size()).toBe(1)
    expect(sut.pending()[0]?.code).toBe('kiosk.wake_lock.denied')
  })
})
