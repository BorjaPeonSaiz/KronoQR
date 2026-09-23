import { afterEach, describe, expect, it } from 'vitest'
import {
  DEFAULT_UPDATE_QUIET_MINUTES,
  DEFAULT_UPDATE_WINDOW,
} from '@/features/offline/domain/updateWindow'
import {
  persistPairedDevice,
  readBreakClockingEnabled,
  readClockSkewToleranceSeconds,
  readDeviceName,
  readDeviceToken,
  readDeviceTokenExpiresAt,
  readLastScanAt,
  readServiceCodeHash,
  readUpdateQuietMinutes,
  readUpdateWindow,
  resolveDeviceId,
  storeBreakClockingEnabled,
  storeClockSkewToleranceSeconds,
  storeLastScanAt,
  storeServiceCodeHash,
  storeUpdateQuietMinutes,
  storeUpdateWindow,
} from '@/shared/telemetry/deviceIdentity'

const KEYS = [
  'kronoqr.kiosk.device_token',
  'kronoqr.kiosk.device_id',
  'kronoqr.kiosk.device_token_expires_at',
  'kronoqr.kiosk.device_name',
  'kronoqr.kiosk.service_code_hash',
  'kronoqr.kiosk.break_clocking_enabled',
  'kronoqr.kiosk.clock_skew_tolerance_seconds',
  'kronoqr.kiosk.update_window',
  'kronoqr.kiosk.update_quiet_minutes',
  'kronoqr.kiosk.last_scan_at',
]

afterEach(() => {
  for (const key of KEYS) localStorage.removeItem(key)
})

describe('identidad del dispositivo — caducidad y nombre (RF-KI-08, tarea 3.3)', () => {
  it('sin emparejar, ninguno de los dos existe', () => {
    expect(readDeviceTokenExpiresAt()).toBeNull()
    expect(readDeviceName()).toBeNull()
  })

  it('persistPairedDevice guarda token, id, caducidad y nombre', () => {
    persistPairedDevice(
      '92|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ',
      '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81',
      '2026-12-06T10:07:00Z',
      'Recepcion',
    )

    expect(readDeviceToken()).toBe('92|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ')
    expect(resolveDeviceId()).toBe('0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81')
    expect(readDeviceTokenExpiresAt()).toBe('2026-12-06T10:07:00Z')
    expect(readDeviceName()).toBe('Recepcion')
  })
})

describe('huella del codigo de servicio (RF-KI-08, tarea 3.3)', () => {
  it('sin latido todavia, null', () => {
    expect(readServiceCodeHash()).toBeNull()
  })

  it('storeServiceCodeHash guarda lo que devuelve el latido', () => {
    storeServiceCodeHash('9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08')
    expect(readServiceCodeHash()).toBe(
      '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
    )
  })

  it('`null` BORRA la huella cacheada: la instalacion ha quitado el codigo', () => {
    storeServiceCodeHash('huella-vieja')
    storeServiceCodeHash(null)
    expect(readServiceCodeHash()).toBeNull()
  })
})

describe('fichaje de pausa y umbral de desfase (RF-AT-12, RF-AT-10, tarea 3.5)', () => {
  it('sin latido todavia, el fichaje de pausa esta desactivado y no hay umbral', () => {
    expect(readBreakClockingEnabled()).toBe(false)
    expect(readClockSkewToleranceSeconds()).toBeNull()
  })

  it('storeBreakClockingEnabled guarda lo que devuelve el latido', () => {
    storeBreakClockingEnabled(true)
    expect(readBreakClockingEnabled()).toBe(true)

    storeBreakClockingEnabled(false)
    expect(readBreakClockingEnabled()).toBe(false)
  })

  it('storeClockSkewToleranceSeconds guarda el umbral de la instalacion', () => {
    storeClockSkewToleranceSeconds(600)
    expect(readClockSkewToleranceSeconds()).toBe(600)
  })
})

describe('ventana de actualizacion y ultimo escaneo (RF-KI-07, tarea 3.12)', () => {
  it('sin latido todavia, la ventana y los minutos son los de serie, nunca `null`', () => {
    expect(readUpdateWindow()).toEqual(DEFAULT_UPDATE_WINDOW)
    expect(readUpdateQuietMinutes()).toBe(DEFAULT_UPDATE_QUIET_MINUTES)
  })

  it('storeUpdateWindow guarda la ventana que declara el latido', () => {
    storeUpdateWindow({ start: '22:00', end: '01:00' })
    expect(readUpdateWindow()).toEqual({ start: '22:00', end: '01:00' })
  })

  it('storeUpdateQuietMinutes guarda los minutos que declara el latido', () => {
    storeUpdateQuietMinutes(15)
    expect(readUpdateQuietMinutes()).toBe(15)
  })

  it('un valor corrupto en disco no rompe nada: se cae a la ventana de serie', () => {
    localStorage.setItem('kronoqr.kiosk.update_window', '{no es json')
    expect(readUpdateWindow()).toEqual(DEFAULT_UPDATE_WINDOW)

    localStorage.setItem('kronoqr.kiosk.update_window', '{"start":"25:00","end":"05:00"}')
    expect(readUpdateWindow()).toEqual(DEFAULT_UPDATE_WINDOW)
  })

  it('sin ningun escaneo todavia, `null`', () => {
    expect(readLastScanAt()).toBeNull()
  })

  it('storeLastScanAt guarda el `occurred_at` del ultimo escaneo', () => {
    storeLastScanAt('2026-08-14T05:58:31.000Z')
    expect(readLastScanAt()).toBe('2026-08-14T05:58:31.000Z')
  })
})
