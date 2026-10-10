// Registro de intentos del modo urgente (RF-KI-07): sobrevive a un disco que se niega a escribir.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MAX_URGENT_UPDATE_ATTEMPTS } from '@/features/offline/domain/minimumVersion'
import {
  currentUrgentGiveUpMotive,
  currentUrgentUpdateMode,
  recordUrgentUpdateAttempt,
  resetUrgentUpdateSession,
} from '@/shared/telemetry/urgentUpdate'

const KEYS = ['kronoqr.kiosk.minimum_app_version', 'kronoqr.kiosk.urgent_update_attempts']

beforeEach(() => {
  for (const key of KEYS) localStorage.removeItem(key)
  resetUrgentUpdateSession()
  localStorage.setItem('kronoqr.kiosk.minimum_app_version', '999.0.0')
})

afterEach(() => {
  vi.restoreAllMocks()
  for (const key of KEYS) localStorage.removeItem(key)
  resetUrgentUpdateSession()
})

describe('registro del modo urgente', () => {
  it('con el disco funcionando, MAX recargas sin avanzar = rendido por `reloads`', () => {
    for (let attempt = 0; attempt < MAX_URGENT_UPDATE_ATTEMPTS; attempt += 1) {
      expect(currentUrgentUpdateMode()).toBe('urgent')
      recordUrgentUpdateAttempt()
    }

    expect(currentUrgentUpdateMode()).toBe('gave_up')
    expect(currentUrgentGiveUpMotive()).toBe('reloads')
  })

  it('con `setItem` fallando siempre, el contador sigue en memoria durante la sesion', () => {
    const real = Storage.prototype.setItem
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (
      this: Storage,
      key: string,
      value: string,
    ) {
      if (key === 'kronoqr.kiosk.urgent_update_attempts') throw new Error('QuotaExceededError')
      real.call(this, key, value)
    })

    for (let attempt = 0; attempt < MAX_URGENT_UPDATE_ATTEMPTS; attempt += 1) {
      recordUrgentUpdateAttempt()
    }

    expect(localStorage.getItem('kronoqr.kiosk.urgent_update_attempts')).toBeNull()
    expect(currentUrgentUpdateMode()).toBe('gave_up')
  })
})
