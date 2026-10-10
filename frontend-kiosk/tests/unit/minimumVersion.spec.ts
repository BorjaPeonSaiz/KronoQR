// Version minima de la PWA y modo urgente (RF-KI-07, RF-KI-08): decision pura.

import { describe, expect, it } from 'vitest'
import {
  isBelowMinimumVersion,
  MAX_URGENT_CHECKS_WITHOUT_UPDATE,
  nextUrgentChecks,
  urgentGiveUpReason,
  withoutUrgentChecks,
  MAX_URGENT_UPDATE_ATTEMPTS,
  nextUrgentAttempts,
  parseMinimumVersionFromHeartbeatData,
  urgentUpdateState,
  versionCore,
} from '@/features/offline/domain/minimumVersion'

describe('nucleo X.Y.Z (RF-KI-07)', () => {
  it('extrae el nucleo e ignora el sufijo', () => {
    expect(versionCore('2.2.1')).toEqual([2, 2, 1])
    expect(versionCore('2.2.1-dev')).toEqual([2, 2, 1])
    expect(versionCore('2.2.1+build.7')).toEqual([2, 2, 1])
  })

  it('lo que no es SemVer no tiene nucleo', () => {
    for (const bad of ['', 'dev', '2.2', '2.2.x', 'v2.2.1', '2.2.1.4']) {
      expect(versionCore(bad)).toBeNull()
    }
  })
})

describe('isBelowMinimumVersion: solo por el nucleo', () => {
  it('por debajo', () => {
    expect(isBelowMinimumVersion('2.2.0', '2.2.1')).toBe(true)
    expect(isBelowMinimumVersion('2.1.9', '2.2.0')).toBe(true)
    expect(isBelowMinimumVersion('1.9.9', '2.0.0')).toBe(true)
  })

  it('el mismo nucleo con sufijo NO esta desfasada (igual que el servidor)', () => {
    expect(isBelowMinimumVersion('2.2.1-dev', '2.2.1')).toBe(false)
    expect(isBelowMinimumVersion('2.2.1', '2.2.1')).toBe(false)
  })

  it('por encima', () => {
    expect(isBelowMinimumVersion('2.2.2', '2.2.1')).toBe(false)
    expect(isBelowMinimumVersion('2.10.0', '2.9.0')).toBe(false)
  })

  it('`0.0.0-dev` SI esta desfasada (es SemVer), igual que en el servidor', () => {
    expect(isBelowMinimumVersion('0.0.0-dev', '2.2.1')).toBe(true)
  })

  it('ante la duda, no esta desfasada', () => {
    expect(isBelowMinimumVersion('dev', '2.2.1')).toBe(false)
    expect(isBelowMinimumVersion('2.2.0', 'raro')).toBe(false)
  })
})

describe('lector tolerante de `minimum_app_version`', () => {
  it('ausente', () => {
    expect(parseMinimumVersionFromHeartbeatData({ server_time: 'x' })).toEqual({ status: 'absent' })
    expect(parseMinimumVersionFromHeartbeatData(null)).toEqual({ status: 'absent' })
    expect(parseMinimumVersionFromHeartbeatData({ minimum_app_version: null })).toEqual({
      status: 'absent',
    })
  })

  it('invalido', () => {
    expect(parseMinimumVersionFromHeartbeatData({ minimum_app_version: 'dev' })).toEqual({
      status: 'invalid',
    })
    expect(parseMinimumVersionFromHeartbeatData({ minimum_app_version: 221 })).toEqual({
      status: 'invalid',
    })
  })

  it('valido: devuelve el nucleo normalizado', () => {
    expect(parseMinimumVersionFromHeartbeatData({ minimum_app_version: '2.2.1' })).toEqual({
      status: 'valid',
      version: '2.2.1',
    })
    expect(parseMinimumVersionFromHeartbeatData({ minimum_app_version: '2.2.1-dev' })).toEqual({
      status: 'valid',
      version: '2.2.1',
    })
  })
})

describe('estado urgente y corte del bucle', () => {
  it('sin minima o ya cumplida: none', () => {
    expect(urgentUpdateState({ current: '2.2.0', minimum: null, attempts: null })).toBe('none')
    expect(urgentUpdateState({ current: '2.2.1-dev', minimum: '2.2.1', attempts: null })).toBe(
      'none',
    )
  })

  it('por debajo: urgent', () => {
    expect(urgentUpdateState({ current: '2.2.0', minimum: '2.2.1', attempts: null })).toBe('urgent')
  })

  it('tras N intentos desde la misma version sin cambio: gave_up', () => {
    const attempts = {
      minimum: '2.2.1',
      from: '2.2.0',
      count: MAX_URGENT_UPDATE_ATTEMPTS,
      checks: 0,
    }
    expect(urgentUpdateState({ current: '2.2.0', minimum: '2.2.1', attempts })).toBe('gave_up')
    expect(
      urgentUpdateState({
        current: '2.2.0',
        minimum: '2.2.1',
        attempts: { ...attempts, count: MAX_URGENT_UPDATE_ATTEMPTS - 1 },
      }),
    ).toBe('urgent')
  })

  it('si la version de origen cambio entre medias, la cuenta empieza de cero', () => {
    const attempts = {
      minimum: '2.2.1',
      from: '2.1.0',
      count: MAX_URGENT_UPDATE_ATTEMPTS,
      checks: 0,
    }
    expect(urgentUpdateState({ current: '2.2.0', minimum: '2.2.1', attempts })).toBe('urgent')
  })

  it('la cuenta va atada a la version de origen: una minima que alterna NO la reinicia', () => {
    const attempts = {
      minimum: '2.2.1',
      from: '2.2.0',
      count: MAX_URGENT_UPDATE_ATTEMPTS,
      checks: 0,
    }
    expect(urgentUpdateState({ current: '2.2.0', minimum: '2.3.0', attempts })).toBe('gave_up')
    expect(
      nextUrgentAttempts('2.2.0', '2.3.0', nextUrgentAttempts('2.2.0', '2.2.1', null)).count,
    ).toBe(2)
  })

  it('tras N comprobaciones sin version nueva tambien se rinde, y dice por que', () => {
    let attempts = null as ReturnType<typeof nextUrgentChecks> | null
    for (let check = 0; check < MAX_URGENT_CHECKS_WITHOUT_UPDATE - 1; check += 1) {
      attempts = nextUrgentChecks('2.2.0', '2.2.1', attempts)
    }
    expect(urgentUpdateState({ current: '2.2.0', minimum: '2.2.1', attempts })).toBe('urgent')
    expect(urgentGiveUpReason('2.2.0', attempts)).toBeNull()

    attempts = nextUrgentChecks('2.2.0', '2.2.1', attempts)
    expect(urgentUpdateState({ current: '2.2.0', minimum: '2.2.1', attempts })).toBe('gave_up')
    expect(urgentGiveUpReason('2.2.0', attempts)).toBe('no_update')
  })

  it('las recargas agotadas dicen `reloads`; una version nueva pone las comprobaciones a cero', () => {
    const reloads = {
      minimum: '2.2.1',
      from: '2.2.0',
      count: MAX_URGENT_UPDATE_ATTEMPTS,
      checks: 0,
    }
    expect(urgentGiveUpReason('2.2.0', reloads)).toBe('reloads')

    const checked = nextUrgentChecks('2.2.0', '2.2.1', null)
    expect(withoutUrgentChecks('2.2.0', checked)?.checks).toBe(0)
  })

  it('nextUrgentAttempts suma sobre la misma version de origen y reinicia en otra', () => {
    const first = nextUrgentAttempts('2.2.0', '2.2.1', null)
    expect(first.count).toBe(1)
    expect(nextUrgentAttempts('2.2.0', '2.2.1', first).count).toBe(2)
    expect(nextUrgentAttempts('2.2.5', '2.2.6', first).count).toBe(1)
  })
})
