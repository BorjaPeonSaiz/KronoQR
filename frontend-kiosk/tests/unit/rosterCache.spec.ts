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
    recordPinScan: vi.fn(),
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
  // Nulo: esta instalacion no ofrece fichaje por PIN (RF-AT-11, tarea 1.12).
  // El padron lo trae siempre, y quien lo consuma decide si ensena el teclado
  // numerico o no; esta prueba solo mira el cache del padron.
  pin_sealing_public_key: null,
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
    const sealed = await sealRoster(
      ROSTER.entries,
      ROSTER.generated_at,
      DEVICE_TOKEN,
      ROSTER.pin_sealing_public_key,
      cryptoDeps,
    )
    expect(sealed).not.toBeNull()

    const asText = new TextDecoder().decode(sealed?.ciphertext)
    expect(asText).not.toContain('Lucia')
    expect(asText).not.toContain(tokenHash(TOKEN))
  })

  it('se abre con el token del dispositivo', async () => {
    const sealed = await sealRoster(
      ROSTER.entries,
      ROSTER.generated_at,
      DEVICE_TOKEN,
      ROSTER.pin_sealing_public_key,
      cryptoDeps,
    )
    const opened = sealed === null ? null : await openRoster(sealed, DEVICE_TOKEN, cryptoDeps)

    expect(opened).toEqual(ROSTER.entries)
  })

  it('NO se abre con otro token: la tablet reemparejada no lee el padron viejo', async () => {
    const sealed = await sealRoster(
      ROSTER.entries,
      ROSTER.generated_at,
      DEVICE_TOKEN,
      ROSTER.pin_sealing_public_key,
      cryptoDeps,
    )
    const opened = sealed === null ? null : await openRoster(sealed, 'otro-token', cryptoDeps)

    expect(opened).toBeNull()
  })

  it('sin token de dispositivo no se cifra nada, y por tanto no se cachea', async () => {
    expect(
      await sealRoster(
        ROSTER.entries,
        ROSTER.generated_at,
        '',
        ROSTER.pin_sealing_public_key,
        cryptoDeps,
      ),
    ).toBeNull()
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
      recordPinScan: vi.fn(),
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

  it('no distingue "aun no se sabe" hasta que un refresh termina, exito o no', async () => {
    const storage = createMemoryQueueStorage()
    const { roster } = build(storage, DEVICE_TOKEN)

    expect(roster.settled()).toBe(false)
    await roster.refresh()
    expect(roster.settled()).toBe(true)
  })

  it('queda resuelto tambien cuando el refresh falla: null deja de ser ambiguo', async () => {
    const storage = createMemoryQueueStorage()
    const offlineApi: ApiClient = {
      recordScan: vi.fn(),
      recordPinScan: vi.fn(),
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

    expect(roster.settled()).toBe(false)
    expect(await roster.refresh()).toBe(false)
    expect(roster.settled()).toBe(true)
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

describe('clave publica del PIN (RF-AT-11, ADR-017)', () => {
  const PUBLIC_KEY = '7cXt0m5rXf8mB2mHnV1kQe0k0f5T2xY3rZq8w9AbCdE='

  function build(storage: QueueStorage, roster: KioskRoster, deviceToken: string | null) {
    return createCachedRoster({
      api: rosterApi(roster, { count: 0 }),
      storage: () => storage,
      deviceToken: () => deviceToken,
      crypto: cryptoDeps,
    })
  }

  it('null si la instalacion no ofrece fichaje por PIN', async () => {
    const storage = createMemoryQueueStorage()
    const roster = build(storage, ROSTER, DEVICE_TOKEN)

    await roster.refresh()

    expect(roster.pinSealingPublicKey()).toBeNull()
  })

  it('distingue "aun no se sabe" de "esta instalacion no ofrece PIN": ambas son null', async () => {
    const storage = createMemoryQueueStorage()
    const roster = build(storage, ROSTER, DEVICE_TOKEN)

    // Recien creado, sin ningun refresh: null es "no lo se todavia".
    expect(roster.pinSealingPublicKey()).toBeNull()
    expect(roster.settled()).toBe(false)

    await roster.refresh()

    // Tras el refresh, el mismo null pasa a significar, sin ambiguedad,
    // "esta instalacion no ofrece PIN" (RF-AT-11, ADR-017).
    expect(roster.pinSealingPublicKey()).toBeNull()
    expect(roster.settled()).toBe(true)
  })

  it('la trae el padron cuando la instalacion si la ofrece', async () => {
    const storage = createMemoryQueueStorage()
    const roster = build(storage, { ...ROSTER, pin_sealing_public_key: PUBLIC_KEY }, DEVICE_TOKEN)

    await roster.refresh()

    expect(roster.pinSealingPublicKey()).toBe(PUBLIC_KEY)
  })

  it('sobrevive a un reinicio: viaja EN CLARO junto al padron cifrado, no dentro del sobre', async () => {
    const storage = createMemoryQueueStorage()
    await build(storage, { ...ROSTER, pin_sealing_public_key: PUBLIC_KEY }, DEVICE_TOKEN).refresh()

    // Arranque siguiente: mismo almacenamiento, instancia nueva, SIN refresh.
    const reloaded = build(storage, ROSTER, DEVICE_TOKEN)
    await reloaded.load()

    expect(reloaded.pinSealingPublicKey()).toBe(PUBLIC_KEY)
  })

  it('se purga junto con el resto de la copia al desvincular el dispositivo', async () => {
    const storage = createMemoryQueueStorage()
    await build(storage, { ...ROSTER, pin_sealing_public_key: PUBLIC_KEY }, DEVICE_TOKEN).refresh()

    const unpaired = build(storage, ROSTER, null)
    await unpaired.load()

    expect(unpaired.pinSealingPublicKey()).toBeNull()
  })
})
