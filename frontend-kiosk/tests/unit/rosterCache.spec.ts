// Padron cacheado: cifrado en reposo (RL-12), resolucion sincrona y purga.
//
// El oraculo del hash es `node:crypto`, no la implementacion de produccion: una
// prueba que comprueba una funcion contra si misma no comprueba nada.

import { createHash, webcrypto } from 'node:crypto'
import { describe, expect, it, vi } from 'vitest'
import { createCachedRoster } from '@/features/offline/application/cachedRoster'
import { createMemoryQueueStorage } from '@/features/offline/infrastructure/queueStorage'
import type { QueueStorage } from '@/features/offline/infrastructure/queueStorage'
import { openRoster, sealRoster } from '@/features/offline/infrastructure/rosterCipher'
import type { ApiClient } from '@/shared/api/client'
import type { KioskRoster } from '@/shared/api/types'
import { sha256Hex, sha256HexOfBytes } from '@/shared/crypto/sha256'

const TOKEN = '7QK2mXpR9vLdN4tZbYcF1w'
const PAYLOAD = `FH1.a3.${TOKEN}.k9Xm2pQrT5vN8wLa`
const OTHER_PAYLOAD = 'FH1.a3.Wp7Lm2Qx8ZrT4vNc9YbK1e.3Bq7Rt9WzX2mK6pL'
const DEVICE_TOKEN = 'device-token-de-emparejamiento'

// jsdom no trae `crypto.subtle`; se inyecta el de Node.
const cryptoDeps = { subtle: webcrypto.subtle as SubtleCrypto }

const tokenHash = (value: string): string => createHash('sha256').update(value).digest('hex')

function rosterApi(roster: KioskRoster, calls: { count: number }): ApiClient {
  return {
    recordScan: vi.fn(),
    syncScanBatch: vi.fn(),
    fetchRoster: vi.fn(async () => {
      calls.count += 1
      return { outcome: 'ok' as const, data: roster }
    }),
    sendHeartbeat: vi.fn(),
  }
}

const ROSTER: KioskRoster = {
  generated_at: '2026-08-14T04:00:00.000Z',
  entries: [{ token_hash: tokenHash(TOKEN), display_name: 'Lucia G.' }],
}

describe('SHA-256 sincrono', () => {
  it('coincide con los vectores conocidos', () => {
    expect(sha256Hex('')).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
    expect(sha256Hex('abc')).toBe(
      'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
    )
  })

  it('coincide con `node:crypto` para el token de una tarjeta', () => {
    expect(sha256Hex(TOKEN)).toBe(tokenHash(TOKEN))
  })

  it('coincide en los tamanos frontera del relleno', () => {
    for (const length of [1, 54, 55, 56, 63, 64, 65, 119, 120, 128]) {
      const input = 'a'.repeat(length)
      expect(sha256Hex(input), `longitud ${length}`).toBe(tokenHash(input))
    }
  })

  it('acepta bytes ademas de texto', () => {
    const bytes = new Uint8Array([0, 1, 2, 250, 255])
    expect(sha256HexOfBytes(bytes)).toBe(createHash('sha256').update(bytes).digest('hex'))
  })
})

describe('cifrado del padron (RL-12)', () => {
  it('lo que se guarda no contiene el nombre en claro', async () => {
    const sealed = await sealRoster(ROSTER.entries, ROSTER.generated_at, DEVICE_TOKEN, cryptoDeps)
    expect(sealed).not.toBeNull()

    const asText = new TextDecoder().decode(sealed?.ciphertext)
    expect(asText).not.toContain('Lucia')
    expect(asText).not.toContain(tokenHash(TOKEN))
  })

  it('se abre con el token del dispositivo', async () => {
    const sealed = await sealRoster(ROSTER.entries, ROSTER.generated_at, DEVICE_TOKEN, cryptoDeps)
    const opened = sealed === null ? null : await openRoster(sealed, DEVICE_TOKEN, cryptoDeps)

    expect(opened).toEqual(ROSTER.entries)
  })

  it('NO se abre con otro token: la tablet reemparejada no lee el padron viejo', async () => {
    const sealed = await sealRoster(ROSTER.entries, ROSTER.generated_at, DEVICE_TOKEN, cryptoDeps)
    const opened = sealed === null ? null : await openRoster(sealed, 'otro-token', cryptoDeps)

    expect(opened).toBeNull()
  })

  it('sin token de dispositivo no se cifra nada, y por tanto no se cachea', async () => {
    expect(await sealRoster(ROSTER.entries, ROSTER.generated_at, '', cryptoDeps)).toBeNull()
  })
})

describe('padron cacheado en uso', () => {
  function build(storage: QueueStorage, deviceToken: string | null, calls = { count: 0 }) {
    return {
      calls,
      roster: createCachedRoster({
        api: rosterApi(ROSTER, calls),
        storage: () => storage,
        deviceToken: () => deviceToken,
        crypto: cryptoDeps,
      }),
    }
  }

  it('resuelve el nombre SIN esperar a nada (es sincrono)', async () => {
    const storage = createMemoryQueueStorage()
    const { roster } = build(storage, DEVICE_TOKEN)

    await roster.refresh()

    // Sin `await`: esto es lo que permite confirmar en el mismo turno del bucle.
    expect(roster.port.displayNameFor(PAYLOAD)).toBe('Lucia G.')
  })

  it('sobrevive a un reinicio: se descifra desde IndexedDB', async () => {
    const storage = createMemoryQueueStorage()
    await build(storage, DEVICE_TOKEN).roster.refresh()

    // Arranque siguiente: mismo almacenamiento, instancia nueva.
    const { roster: reloaded } = build(storage, DEVICE_TOKEN)
    await reloaded.load()

    expect(reloaded.port.displayNameFor(PAYLOAD)).toBe('Lucia G.')
    expect(reloaded.generatedAt()).toBe('2026-08-14T04:00:00.000Z')
  })

  it('si no reconoce la tarjeta devuelve `null` — y el fichaje seguira su curso', async () => {
    const storage = createMemoryQueueStorage()
    const { roster } = build(storage, DEVICE_TOKEN)
    await roster.refresh()

    expect(roster.port.displayNameFor(OTHER_PAYLOAD)).toBeNull()
    // Y lo que no es una tarjeta, tampoco rompe nada.
    expect(roster.port.displayNameFor('https://wifi.hotel.example')).toBeNull()
  })

  it('sin emparejamiento no hay padron, y el quiosco no pregunta al servidor', async () => {
    const storage = createMemoryQueueStorage()
    const { roster, calls } = build(storage, null)

    await roster.load()
    expect(await roster.refresh()).toBe(false)

    expect(calls.count).toBe(0)
    expect(roster.size()).toBe(0)
    expect(roster.port.displayNameFor(PAYLOAD)).toBeNull()
  })

  it('purga la copia al desvincular el dispositivo (doc 01 §8.1)', async () => {
    const storage = createMemoryQueueStorage()
    await build(storage, DEVICE_TOKEN).roster.refresh()
    expect(await storage.readRoster()).not.toBeNull()

    // La tablet se desvincula: desaparece el token.
    const { roster: unpaired } = build(storage, null)
    await unpaired.load()

    expect(await storage.readRoster()).toBeNull()
    expect(unpaired.size()).toBe(0)
  })

  it('purga tambien si la copia no se puede abrir con el token actual', async () => {
    const storage = createMemoryQueueStorage()
    await build(storage, DEVICE_TOKEN).roster.refresh()

    const diagnostics: string[] = []
    const roster = createCachedRoster({
      api: rosterApi(ROSTER, { count: 0 }),
      storage: () => storage,
      deviceToken: () => 'token-de-otro-emparejamiento',
      crypto: cryptoDeps,
      onDiagnostic: (code) => diagnostics.push(code),
    })
    await roster.load()

    expect(diagnostics).toContain('roster.decrypt_failed')
    expect(await storage.readRoster()).toBeNull()
  })

  it('si el servidor no contesta, se queda con lo que tenia', async () => {
    const storage = createMemoryQueueStorage()
    await build(storage, DEVICE_TOKEN).roster.refresh()

    const offlineApi: ApiClient = {
      recordScan: vi.fn(),
      syncScanBatch: vi.fn(),
      fetchRoster: vi.fn(async () => ({ outcome: 'failed' as const, cause: 'offline' as const })),
      sendHeartbeat: vi.fn(),
    }
    const roster = createCachedRoster({
      api: offlineApi,
      storage: () => storage,
      deviceToken: () => DEVICE_TOKEN,
      crypto: cryptoDeps,
    })
    await roster.load()

    expect(await roster.refresh()).toBe(false)
    expect(roster.port.displayNameFor(PAYLOAD)).toBe('Lucia G.')
  })

  it('sin WebCrypto no cachea nada: mejor sin nombre que con el padron en claro', async () => {
    const storage = createMemoryQueueStorage()
    const diagnostics: string[] = []
    const roster = createCachedRoster({
      api: rosterApi(ROSTER, { count: 0 }),
      storage: () => storage,
      deviceToken: () => DEVICE_TOKEN,
      crypto: { subtle: null },
      onDiagnostic: (code) => diagnostics.push(code),
    })

    expect(await roster.refresh()).toBe(false)
    expect(await storage.readRoster()).toBeNull()
    expect(diagnostics).toContain('roster.not_cacheable')
  })
})
