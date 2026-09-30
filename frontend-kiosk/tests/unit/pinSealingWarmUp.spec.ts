// PIN-03: `warmUpSealing()` devuelve un ESTADO y no cachea el fallo.
//
// Se sustituye `libsodium-wrappers` por un doble cuyo `ready` falla la primera
// vez y funciona la segunda. Antes, la promesa rechazada se quedaba cacheada y
// el quiosco no volvia a poder sellar hasta la siguiente recarga.

import { afterEach, describe, expect, it, vi } from 'vitest'

afterEach(() => {
  vi.resetModules()
  vi.doUnmock('libsodium-wrappers')
})

describe('warmUpSealing (PIN-03)', () => {
  it('dice `ready` cuando libsodium arranca', async () => {
    const { warmUpSealing } = await import('@/features/pin/infrastructure/pinSealing')

    expect(await warmUpSealing()).toBe('ready')
  })

  it('dice `unavailable` si WebAssembly no arranca, sin lanzar', async () => {
    vi.resetModules()
    vi.doMock('libsodium-wrappers', () => ({
      default: { ready: Promise.reject(new Error('wasm blocked')) },
    }))
    const { warmUpSealing } = await import('@/features/pin/infrastructure/pinSealing')

    expect(await warmUpSealing()).toBe('unavailable')
  })

  it('dice `unavailable` si el modulo arranca pero falta la primitiva de sellado', async () => {
    vi.resetModules()
    vi.doMock('libsodium-wrappers', () => ({ default: { ready: Promise.resolve() } }))
    const { warmUpSealing } = await import('@/features/pin/infrastructure/pinSealing')

    expect(await warmUpSealing()).toBe('unavailable')
  })

  it('NO cachea el fallo: el siguiente intento vuelve a preguntar', async () => {
    vi.resetModules()
    let attempts = 0
    const fake = {
      get ready() {
        attempts += 1
        return attempts === 1 ? Promise.reject(new Error('first try fails')) : Promise.resolve()
      },
      crypto_box_seal: () => new Uint8Array(),
    }
    vi.doMock('libsodium-wrappers', () => ({ default: fake }))
    const { warmUpSealing } = await import('@/features/pin/infrastructure/pinSealing')

    expect(await warmUpSealing()).toBe('unavailable')
    expect(await warmUpSealing()).toBe('ready')
    expect(attempts).toBe(2)
  })

  it('`sealPin` con libsodium caido lanza PinSealingError, no el error crudo', async () => {
    vi.resetModules()
    vi.doMock('libsodium-wrappers', () => ({
      default: { ready: Promise.reject(new Error('wasm blocked')) },
    }))
    const { PinSealingError, sealPin } = await import('@/features/pin/infrastructure/pinSealing')

    await expect(sealPin('483920', 'AAAA')).rejects.toBeInstanceOf(PinSealingError)
  })
})
