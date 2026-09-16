import { afterEach, describe, expect, it } from 'vitest'
import { createServiceCodeGate } from '@/features/diagnostics/application/serviceCodeGate'
import { sha256Hex } from '@/shared/crypto/sha256'

const DEVICE_ID = '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81'
const CODE = '48392017'
const HASH = sha256Hex(`${DEVICE_ID}:${CODE}`)
const LOCKOUT_KEY = 'kronoqr.kiosk.service_code_lockout'

afterEach(() => {
  localStorage.removeItem(LOCKOUT_KEY)
})

describe('puerta del codigo de servicio (RF-KI-08, tarea 3.3)', () => {
  it('acepta el codigo correcto', () => {
    const gate = createServiceCodeGate({ deviceId: DEVICE_ID, expectedHash: HASH })
    expect(gate.attempt(CODE)).toEqual({ outcome: 'accepted' })
  })

  it('rechaza un codigo incorrecto y cuenta los intentos que quedan', () => {
    const gate = createServiceCodeGate({ deviceId: DEVICE_ID, expectedHash: HASH })
    expect(gate.attempt('00000000')).toEqual({ outcome: 'rejected', attemptsLeft: 4 })
    expect(gate.attempt('11111111')).toEqual({ outcome: 'rejected', attemptsLeft: 3 })
  })

  it('un acierto despues de un fallo reinicia el contador de fallos', () => {
    const gate = createServiceCodeGate({ deviceId: DEVICE_ID, expectedHash: HASH })
    gate.attempt('00000000')
    gate.attempt(CODE)
    // Tres fallos mas no deberian bloquear todavia: el contador se reinicio,
    // asi que el tercero de esta tanda deja 2 intentos (5 - 3), no 0.
    gate.attempt('1')
    gate.attempt('2')
    expect(gate.attempt('3')).toEqual({ outcome: 'rejected', attemptsLeft: 2 })
  })

  it('bloquea al quinto fallo, 60 s por defecto', () => {
    const now = 1_000_000
    const gate = createServiceCodeGate({
      deviceId: DEVICE_ID,
      expectedHash: HASH,
      now: () => now,
    })

    for (let i = 0; i < 4; i += 1) gate.attempt('00000000')
    expect(gate.isLocked()).toBe(false)

    const result = gate.attempt('00000000')
    expect(result).toEqual({ outcome: 'locked', retryAfterMs: 60_000 })
    expect(gate.isLocked()).toBe(true)
    expect(gate.remainingLockoutMs()).toBe(60_000)
  })

  it('rechaza intentos mientras esta bloqueada, aunque el codigo sea correcto', () => {
    let now = 0
    const gate = createServiceCodeGate({ deviceId: DEVICE_ID, expectedHash: HASH, now: () => now })
    for (let i = 0; i < 5; i += 1) gate.attempt('00000000')
    expect(gate.isLocked()).toBe(true)

    now = 30_000
    const result = gate.attempt(CODE)
    expect(result.outcome).toBe('locked')
    if (result.outcome === 'locked') expect(result.retryAfterMs).toBe(30_000)
  })

  it('se desbloquea sola al pasar el tiempo, con el reloj inyectado', () => {
    let now = 0
    const gate = createServiceCodeGate({ deviceId: DEVICE_ID, expectedHash: HASH, now: () => now })
    for (let i = 0; i < 5; i += 1) gate.attempt('00000000')
    expect(gate.isLocked()).toBe(true)

    now = 60_001
    expect(gate.isLocked()).toBe(false)
    expect(gate.attempt(CODE)).toEqual({ outcome: 'accepted' })
  })

  it('admite umbrales configurables', () => {
    const now = 0
    const gate = createServiceCodeGate({
      deviceId: DEVICE_ID,
      expectedHash: HASH,
      maxAttempts: 2,
      lockoutMs: 5_000,
      now: () => now,
    })

    expect(gate.attempt('00000000')).toEqual({ outcome: 'rejected', attemptsLeft: 1 })
    expect(gate.attempt('00000000')).toEqual({ outcome: 'locked', retryAfterMs: 5_000 })
  })

  describe('persistencia del bloqueo (revision de la 3.3, segunda vuelta)', () => {
    it('los fallos sobreviven a una instancia NUEVA del gate (equivalente a «Volver a fichar» y reabrir)', () => {
      const first = createServiceCodeGate({ deviceId: DEVICE_ID, expectedHash: HASH })
      first.attempt('00000000')
      first.attempt('00000000')

      // Instancia nueva: la que crearia `DiagnosticsView.vue` al volver a
      // montarse. Sin persistencia, esto empezaria en 0 fallos.
      const second = createServiceCodeGate({ deviceId: DEVICE_ID, expectedHash: HASH })
      expect(second.attempt('00000000')).toEqual({ outcome: 'rejected', attemptsLeft: 2 })
    })

    it('el bloqueo sobrevive a una instancia nueva, con el mismo `now` inyectado', () => {
      const now = 1_000_000
      const clock = () => now
      const first = createServiceCodeGate({ deviceId: DEVICE_ID, expectedHash: HASH, now: clock })
      for (let i = 0; i < 5; i += 1) first.attempt('00000000')
      expect(first.isLocked()).toBe(true)

      const second = createServiceCodeGate({ deviceId: DEVICE_ID, expectedHash: HASH, now: clock })
      expect(second.isLocked()).toBe(true)
      expect(second.remainingLockoutMs()).toBe(60_000)
      expect(second.attempt(CODE)).toEqual({ outcome: 'locked', retryAfterMs: 60_000 })
    })

    it('un acierto limpia el bloqueo persistido: una instancia nueva empieza sin fallos', () => {
      const first = createServiceCodeGate({ deviceId: DEVICE_ID, expectedHash: HASH })
      first.attempt('00000000')
      first.attempt('00000000')
      first.attempt(CODE)

      expect(localStorage.getItem(LOCKOUT_KEY)).toBeNull()

      const second = createServiceCodeGate({ deviceId: DEVICE_ID, expectedHash: HASH })
      expect(second.attempt('00000000')).toEqual({ outcome: 'rejected', attemptsLeft: 4 })
    })

    it('un bloqueo persistido y ya caducado no bloquea una instancia nueva', () => {
      let now = 0
      const first = createServiceCodeGate({
        deviceId: DEVICE_ID,
        expectedHash: HASH,
        now: () => now,
      })
      for (let i = 0; i < 5; i += 1) first.attempt('00000000')
      expect(first.isLocked()).toBe(true)

      now = 60_001
      const second = createServiceCodeGate({
        deviceId: DEVICE_ID,
        expectedHash: HASH,
        now: () => now,
      })
      expect(second.isLocked()).toBe(false)
      expect(second.attempt(CODE)).toEqual({ outcome: 'accepted' })
    })
  })
})
