import { afterEach, describe, expect, it } from 'vitest'
import {
  persistPairedDevice,
  readDeviceName,
  readDeviceToken,
  readDeviceTokenExpiresAt,
  readServiceCodeHash,
  resolveDeviceId,
  storeServiceCodeHash,
} from '@/shared/telemetry/deviceIdentity'

const KEYS = [
  'kronoqr.kiosk.device_token',
  'kronoqr.kiosk.device_id',
  'kronoqr.kiosk.device_token_expires_at',
  'kronoqr.kiosk.device_name',
  'kronoqr.kiosk.service_code_hash',
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
