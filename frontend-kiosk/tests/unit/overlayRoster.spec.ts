// El padron en el almacen compuesto (disco que se lee pero no se escribe + memoria):
// con la cuota agotada la copia NUEVA vive en memoria y gana a la vieja del disco, tambien
// tras un relevo de token y con la clave publica de PIN nueva.

import { describe, expect, it } from 'vitest'
import { createOverlayQueueStorage } from '@/features/offline/infrastructure/overlayStorage'
import { createMemoryQueueStorage } from '@/features/offline/infrastructure/queueStorage'
import type {
  EncryptedRosterRecord,
  QueueStorage,
} from '@/features/offline/infrastructure/queueStorage'

function roster(tag: number, pinKey: string | null): EncryptedRosterRecord {
  return {
    id: 'current',
    salt: new Uint8Array([tag]),
    iv: new Uint8Array([tag]),
    ciphertext: new Uint8Array([tag, tag]),
    generated_at: `2026-08-14T0${tag}:00:00.000Z`,
    pin_sealing_public_key: pinKey,
  }
}

function fullDisk(state: { full: boolean }): QueueStorage {
  const real = createMemoryQueueStorage()
  return {
    ...real,
    durable: true,
    writeRoster: async (record) => {
      if (state.full) throw new DOMException('quota', 'QuotaExceededError')
      return real.writeRoster(record)
    },
  }
}

describe('padron con la cuota agotada', () => {
  it('escribir con fallo y leer despues devuelve la copia NUEVA, no la vieja del disco', async () => {
    const state = { full: false }
    const overlay = createOverlayQueueStorage(fullDisk(state), createMemoryQueueStorage())
    await overlay.writeRoster(roster(1, 'clave-vieja'))
    state.full = true

    await overlay.writeRoster(roster(2, 'clave-nueva'))

    const read = await overlay.readRoster()
    expect(read?.generated_at).toBe('2026-08-14T02:00:00.000Z')
    expect(read?.ciphertext).toEqual(new Uint8Array([2, 2]))
    // Incluye la clave publica de PIN nueva (RF-AT-11), que viaja fuera del sobre.
    expect(read?.pin_sealing_public_key).toBe('clave-nueva')
  })

  it('con relevo de token (nuevo sobre), nunca se sirve el sobre del token anterior', async () => {
    const state = { full: false }
    const overlay = createOverlayQueueStorage(fullDisk(state), createMemoryQueueStorage())
    await overlay.writeRoster(roster(1, null))
    state.full = true
    await overlay.writeRoster(roster(3, null))

    expect((await overlay.readRoster())?.salt).toEqual(new Uint8Array([3]))
  })

  it('cuando el disco vuelve a admitir escrituras, la copia de memoria se retira', async () => {
    const state = { full: true }
    const overlay = createOverlayQueueStorage(fullDisk(state), createMemoryQueueStorage())
    await overlay.writeRoster(roster(2, null))
    state.full = false

    await overlay.writeRoster(roster(4, 'k'))

    expect(await overlay.memoryPart.readRoster()).toBeNull()
    expect((await overlay.readRoster())?.pin_sealing_public_key).toBe('k')
  })

  it('sin nada en memoria, lee el disco', async () => {
    const state = { full: false }
    const overlay = createOverlayQueueStorage(fullDisk(state), createMemoryQueueStorage())
    await overlay.writeRoster(roster(1, null))

    expect((await overlay.readRoster())?.salt).toEqual(new Uint8Array([1]))
  })
})
