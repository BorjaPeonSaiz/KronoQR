// Aritmetica de presentacion de la flota de quioscos (RF-PA-07, tarea 3.3):
// medida contra el reloj del SERVIDOR, nunca contra `Date.now()` del
// navegador (regla dura 3).
import { describe, expect, it } from 'vitest'
import { elapsedSinceHeartbeat, elapsedSinceOldestPending } from '@/features/devices/useDeviceRows'

describe('elapsedSinceHeartbeat', () => {
  it('cuenta en horas y minutos enteros, nunca decimales', () => {
    const lastSeenAt = '2026-09-07T07:30:00.000000Z'
    const serverNowMs = Date.parse('2026-09-07T10:00:00.000000Z')

    expect(elapsedSinceHeartbeat(lastSeenAt, serverNowMs)).toEqual({ hours: 2, minutes: '30' })
  })

  it('`null` -nunca ha latido- no es lo mismo que «cero minutos»', () => {
    expect(elapsedSinceHeartbeat(null, Date.now())).toBeNull()
  })

  it('clava a cero un latido «del futuro» (reloj de la tablet mal puesto)', () => {
    const lastSeenAt = '2026-09-07T10:05:00.000000Z'
    const serverNowMs = Date.parse('2026-09-07T10:00:00.000000Z')

    expect(elapsedSinceHeartbeat(lastSeenAt, serverNowMs)).toEqual({ hours: 0, minutes: '00' })
  })
})

describe('elapsedSinceOldestPending', () => {
  it('cuenta desde el fichaje mas antiguo sin sincronizar', () => {
    const oldestPendingAt = '2026-09-07T07:00:00.000000Z'
    const serverNowMs = Date.parse('2026-09-07T10:00:00.000000Z')

    expect(elapsedSinceOldestPending(oldestPendingAt, serverNowMs)).toEqual({
      hours: 3,
      minutes: '00',
    })
  })

  it('`null` -cola vacia o sin latido- no cuenta como cero', () => {
    expect(elapsedSinceOldestPending(null, Date.now())).toBeNull()
  })

  it('tambien clava a cero un instante «del futuro»', () => {
    const oldestPendingAt = '2026-09-07T10:05:00.000000Z'
    const serverNowMs = Date.parse('2026-09-07T10:00:00.000000Z')

    expect(elapsedSinceOldestPending(oldestPendingAt, serverNowMs)).toEqual({
      hours: 0,
      minutes: '00',
    })
  })
})
