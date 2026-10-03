// Relevo del token del quiosco (RF-ID-04, ADR-044, doc 02 §7.3).
//
// El servidor entrega el relevo en el latido. Lo que se prueba aqui es la mitad
// de cliente: que se guarda como una unidad, que el padron cacheado (que deriva
// su clave del token) se re-cifra sin dejar nunca una combinacion ilegible, que
// siempre gana el ultimo relevo recibido, que la peticion en vuelo firmada con
// el token viejo se repite UNA vez, y que nada de esto bloquea el fichaje ni
// provoca una falsa desvinculacion.

import { createHash, webcrypto } from 'node:crypto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createCachedRoster } from '@/features/offline/application/cachedRoster'
import type { CachedRoster } from '@/features/offline/application/cachedRoster'
import { createMemoryQueueStorage } from '@/features/offline/infrastructure/queueStorage'
import type { QueueStorage } from '@/features/offline/infrastructure/queueStorage'
import { openRoster } from '@/features/offline/infrastructure/rosterCipher'
import { createDeviceRevocationWatcher } from '@/features/pairing/application/deviceRevocation'
import type { ApiClient } from '@/shared/api/client'
import { createApiClient } from '@/shared/api/client'
import type { KioskHeartbeat, KioskRoster } from '@/shared/api/types'
import {
  clearDeviceToken,
  persistPairedDevice,
  readDeviceToken,
  readDeviceTokenExpiresAt,
  readPreviousDeviceToken,
  storeRotatedDeviceToken,
} from '@/shared/telemetry/deviceIdentity'
import { createErrorReporter } from '@/shared/telemetry/errorReporter'
import { createHeartbeatScheduler } from '@/shared/telemetry/heartbeat'
import {
  adoptRotatedToken,
  confirmDeviceToken,
  deviceTokenApiOptions,
  parseRotatedToken,
  registerRosterKeyRotator,
  revertToPreviousToken,
} from '@/shared/telemetry/tokenRotation'
import { fixedClock } from '@/shared/time/clock'

const TOKEN_KEY = 'kronoqr.kiosk.device_token'
const EXPIRES_KEY = 'kronoqr.kiosk.device_token_expires_at'

const OLD_TOKEN = '41|token-viejo-del-quiosco'
const NEW_TOKEN = '42|token-nuevo-del-quiosco'
const REDELIVERED_TOKEN = '43|token-reentregado-del-quiosco'
const OLD_EXPIRES = '2026-10-20T06:00:00.000Z'
const NEW_EXPIRES = '2027-01-01T06:00:00.000Z'

const cryptoDeps = { subtle: webcrypto.subtle as SubtleCrypto }
const tokenHash = (value: string): string => createHash('sha256').update(value).digest('hex')

const CARD_TOKEN = '7QK2mXpR9vLdN4tZbYcF1w'
const CARD_PAYLOAD = `FH1.a3.${CARD_TOKEN}.k9Xm2pQrT5vN8wLa`
const ROSTER: KioskRoster = {
  generated_at: '2026-10-01T04:00:00.000Z',
  entries: [{ token_hash: tokenHash(CARD_TOKEN), display_name: 'Lucia G.' }],
  pin_sealing_public_key: null,
}

function pairWith(token: string, expiresAt: string): void {
  localStorage.setItem(TOKEN_KEY, token)
  localStorage.setItem(EXPIRES_KEY, expiresAt)
}

function apiStub(overrides: Partial<ApiClient> = {}): ApiClient {
  return {
    recordScan: vi.fn(),
    recordPinScan: vi.fn(),
    syncScanBatch: vi.fn(),
    fetchRoster: vi.fn(async () => ({ outcome: 'ok' as const, data: ROSTER })),
    sendHeartbeat: vi.fn(),
    requestPairing: vi.fn(),
    claimPairing: vi.fn(),
    reportDiscardedScans: vi.fn(),
    fetchBranding: vi.fn(),
    ...overrides,
  }
}

/** Un padron ya cacheado y cifrado con el token VIGENTE, y registrado como rotador. */
async function rosterReadyWith(
  storage: QueueStorage,
  api: ApiClient = apiStub(),
): Promise<CachedRoster> {
  const roster = createCachedRoster({
    api,
    storage: () => storage,
    deviceToken: readDeviceToken,
    crypto: cryptoDeps,
  })
  await roster.refresh()
  return roster
}

async function storedRosterOpensWith(storage: QueueStorage, token: string): Promise<boolean> {
  const record = await storage.readRoster()
  if (record === null) return false
  return (await openRoster(record, token, cryptoDeps)) !== null
}

function deferredGate(): { promise: Promise<void>; open: () => void } {
  let open: () => void = () => undefined
  const promise = new Promise<void>((resolve) => {
    open = resolve
  })
  return { promise, open: () => open() }
}

let unregister: (() => void) | null = null

function register(roster: CachedRoster): void {
  unregister = registerRosterKeyRotator(roster)
}

beforeEach(() => {
  localStorage.clear()
  pairWith(OLD_TOKEN, OLD_EXPIRES)
})

afterEach(() => {
  unregister?.()
  unregister = null
  vi.restoreAllMocks()
  localStorage.clear()
})

describe('RF-ID-04 · lectura defensiva de `rotated_token`', () => {
  it('devuelve el relevo cuando la forma encaja', () => {
    expect(
      parseRotatedToken({
        server_time: 'x',
        rotated_token: { value: NEW_TOKEN, expires_at: NEW_EXPIRES },
      }),
    ).toEqual({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })
  })

  it('la clave ausente, nula o mal formada significa «no toca»', () => {
    expect(parseRotatedToken({ server_time: 'x' })).toBeNull()
    expect(parseRotatedToken({ rotated_token: null })).toBeNull()
    expect(parseRotatedToken(null)).toBeNull()
    expect(parseRotatedToken('texto')).toBeNull()
    expect(parseRotatedToken({ rotated_token: { value: '', expires_at: NEW_EXPIRES } })).toBeNull()
    expect(parseRotatedToken({ rotated_token: { value: NEW_TOKEN } })).toBeNull()
    expect(
      parseRotatedToken({ rotated_token: { value: NEW_TOKEN, expires_at: 'no es una fecha' } }),
    ).toBeNull()
    expect(parseRotatedToken({ rotated_token: { value: 7, expires_at: NEW_EXPIRES } })).toBeNull()
  })
})

describe('RF-ID-04 · guardado atomico del token y su caducidad', () => {
  it('escribe las dos cosas y la caducidad que enseña la UI es la nueva', () => {
    expect(storeRotatedDeviceToken(NEW_TOKEN, NEW_EXPIRES, null)).toBe(true)

    expect(readDeviceToken()).toBe(NEW_TOKEN)
    expect(readDeviceTokenExpiresAt()).toBe(NEW_EXPIRES)
  })

  it('si el token no se puede escribir, la caducidad vuelve a la anterior', () => {
    const original = Storage.prototype.setItem
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (
      this: Storage,
      key: string,
      value: string,
    ) {
      if (key === TOKEN_KEY) throw new DOMException('cuota', 'QuotaExceededError')
      original.call(this, key, value)
    })

    expect(storeRotatedDeviceToken(NEW_TOKEN, NEW_EXPIRES, null)).toBe(false)

    expect(readDeviceToken()).toBe(OLD_TOKEN)
    expect(readDeviceTokenExpiresAt()).toBe(OLD_EXPIRES)
  })

  it('si la caducidad no se puede escribir, el token no se toca', () => {
    const original = Storage.prototype.setItem
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (
      this: Storage,
      key: string,
      value: string,
    ) {
      if (key === EXPIRES_KEY) throw new DOMException('cuota', 'QuotaExceededError')
      original.call(this, key, value)
    })

    expect(storeRotatedDeviceToken(NEW_TOKEN, NEW_EXPIRES, null)).toBe(false)

    expect(readDeviceToken()).toBe(OLD_TOKEN)
    expect(readDeviceTokenExpiresAt()).toBe(OLD_EXPIRES)
  })
})

describe('RF-ID-04 · adopcion del relevo y re-cifrado del padron', () => {
  it('re-cifra el padron con el token nuevo, guarda el token y el escaneo sigue resolviendo el nombre', async () => {
    const storage = createMemoryQueueStorage()
    const roster = await rosterReadyWith(storage)
    register(roster)
    expect(await storedRosterOpensWith(storage, OLD_TOKEN)).toBe(true)

    const outcome = await adoptRotatedToken({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })

    expect(outcome).toBe('adopted')
    expect(readDeviceToken()).toBe(NEW_TOKEN)
    expect(readDeviceTokenExpiresAt()).toBe(NEW_EXPIRES)
    expect(await storedRosterOpensWith(storage, NEW_TOKEN)).toBe(true)
    expect(await storedRosterOpensWith(storage, OLD_TOKEN)).toBe(false)
    // El indice en memoria no se ha tocado: el camino de los 300 ms sigue igual.
    expect(roster.port.displayNameFor(CARD_PAYLOAD)).toBe('Lucia G.')
  })

  it('tras el relevo, un arranque en frio (`load`) abre la copia con el token nuevo', async () => {
    const storage = createMemoryQueueStorage()
    register(await rosterReadyWith(storage))

    await adoptRotatedToken({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })

    const restarted = createCachedRoster({
      api: apiStub(),
      storage: () => storage,
      deviceToken: readDeviceToken,
      crypto: cryptoDeps,
    })
    await restarted.load()
    expect(restarted.port.displayNameFor(CARD_PAYLOAD)).toBe('Lucia G.')
  })

  it('fallo a mitad del re-cifrado: se conserva el token viejo, la copia sigue legible y se avisa sin datos personales', async () => {
    const storage = createMemoryQueueStorage()
    const roster = await rosterReadyWith(storage)
    register(roster)
    vi.spyOn(storage, 'writeRoster').mockRejectedValueOnce(new Error('IndexedDB lleno'))

    const outcome = await adoptRotatedToken({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })

    expect(outcome).toBe('reseal_failed')
    expect(readDeviceToken()).toBe(OLD_TOKEN)
    expect(readDeviceTokenExpiresAt()).toBe(OLD_EXPIRES)
    expect(await storedRosterOpensWith(storage, OLD_TOKEN)).toBe(true)
    expect(roster.port.displayNameFor(CARD_PAYLOAD)).toBe('Lucia G.')
  })

  it('si el token no se puede guardar tras re-cifrar, la copia vuelve a la clave vigente', async () => {
    const storage = createMemoryQueueStorage()
    register(await rosterReadyWith(storage))
    const original = Storage.prototype.setItem
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (
      this: Storage,
      key: string,
      value: string,
    ) {
      if (key === TOKEN_KEY) throw new DOMException('cuota', 'QuotaExceededError')
      original.call(this, key, value)
    })

    const outcome = await adoptRotatedToken({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })

    expect(outcome).toBe('commit_failed')
    expect(readDeviceToken()).toBe(OLD_TOKEN)
    expect(await storedRosterOpensWith(storage, OLD_TOKEN)).toBe(true)
    expect(await storedRosterOpensWith(storage, NEW_TOKEN)).toBe(false)
  })

  it('un padron ya ilegible no retiene el relevo: se purga y el token se adopta', async () => {
    const storage = createMemoryQueueStorage()
    const roster = await rosterReadyWith(storage)
    register(roster)
    // La copia se cifro con otro token (p. ej. un emparejamiento anterior).
    localStorage.setItem(TOKEN_KEY, 'otro-token-cualquiera')
    pairWith('otro-token-cualquiera', OLD_EXPIRES)

    const outcome = await adoptRotatedToken({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })

    expect(outcome).toBe('adopted')
    expect(readDeviceToken()).toBe(NEW_TOKEN)
    expect(await storage.readRoster()).toBeNull()
  })

  it('sin padron cacheado, adopta el token directamente', async () => {
    const storage = createMemoryQueueStorage()
    register(
      createCachedRoster({
        api: apiStub(),
        storage: () => storage,
        deviceToken: readDeviceToken,
        crypto: cryptoDeps,
      }),
    )

    expect(await adoptRotatedToken({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })).toBe('adopted')
    expect(readDeviceToken()).toBe(NEW_TOKEN)
  })

  it('sin rotador registrado conserva el token viejo (el servidor reentregara)', async () => {
    expect(await adoptRotatedToken({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })).toBe(
      'unavailable',
    )
    expect(readDeviceToken()).toBe(OLD_TOKEN)
  })

  it('una tablet ya desvinculada no resucita con un relevo tardio', async () => {
    register(await rosterReadyWith(createMemoryQueueStorage()))
    localStorage.removeItem(TOKEN_KEY)

    expect(await adoptRotatedToken({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })).toBe(
      'not_paired',
    )
    expect(readDeviceToken()).toBeNull()
  })

  it('el mismo valor solo refresca la caducidad', async () => {
    const storage = createMemoryQueueStorage()
    register(await rosterReadyWith(storage))

    const outcome = await adoptRotatedToken({ value: OLD_TOKEN, expires_at: NEW_EXPIRES })

    expect(outcome).toBe('already_current')
    expect(readDeviceToken()).toBe(OLD_TOKEN)
    expect(readDeviceTokenExpiresAt()).toBe(NEW_EXPIRES)
    expect(await storedRosterOpensWith(storage, OLD_TOKEN)).toBe(true)
  })

  it('reentrega: un relevo DISTINTO sustituye al anterior y el padron queda con el ultimo', async () => {
    const storage = createMemoryQueueStorage()
    register(await rosterReadyWith(storage))

    await adoptRotatedToken({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })
    const outcome = await adoptRotatedToken({
      value: REDELIVERED_TOKEN,
      expires_at: '2027-01-02T06:00:00.000Z',
    })

    expect(outcome).toBe('adopted')
    expect(readDeviceToken()).toBe(REDELIVERED_TOKEN)
    expect(await storedRosterOpensWith(storage, REDELIVERED_TOKEN)).toBe(true)
    expect(await storedRosterOpensWith(storage, NEW_TOKEN)).toBe(false)
  })

  it('dos relevos a la vez se aplican en orden de llegada: manda el ultimo', async () => {
    const storage = createMemoryQueueStorage()
    register(await rosterReadyWith(storage))

    const [first, second] = await Promise.all([
      adoptRotatedToken({ value: NEW_TOKEN, expires_at: NEW_EXPIRES }),
      adoptRotatedToken({ value: REDELIVERED_TOKEN, expires_at: '2027-01-02T06:00:00.000Z' }),
    ])

    expect([first, second]).toEqual(['adopted', 'adopted'])
    expect(readDeviceToken()).toBe(REDELIVERED_TOKEN)
    expect(await storedRosterOpensWith(storage, REDELIVERED_TOKEN)).toBe(true)
  })

  it('un `refresh()` que estaba en el aire cuando rota el token sella con el token NUEVO', async () => {
    const storage = createMemoryQueueStorage()
    const deferred = deferredGate()
    const gate = deferred.promise
    const slowApi = apiStub({
      fetchRoster: vi.fn(async () => {
        await gate
        return { outcome: 'ok' as const, data: ROSTER }
      }),
    })
    const roster = createCachedRoster({
      api: slowApi,
      storage: () => storage,
      deviceToken: readDeviceToken,
      crypto: cryptoDeps,
    })
    register(roster)

    const refreshing = roster.refresh()
    await adoptRotatedToken({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })
    deferred.open()
    await refreshing

    expect(readDeviceToken()).toBe(NEW_TOKEN)
    expect(await storedRosterOpensWith(storage, NEW_TOKEN)).toBe(true)
    expect(await storedRosterOpensWith(storage, OLD_TOKEN)).toBe(false)
  })
})

function heartbeatResponse(rotated?: { value: string; expires_at: string }): KioskHeartbeat {
  return {
    server_time: '2026-10-01T06:00:00.000Z',
    client_errors_accepted: 0,
    service_code_hash: null,
    break_clocking_enabled: false,
    clock_skew_tolerance_seconds: 900,
    ...(rotated === undefined ? {} : { rotated_token: rotated }),
  } as KioskHeartbeat
}

function schedulerWith(
  api: ApiClient,
  extra: Partial<Parameters<typeof createHeartbeatScheduler>[0]> = {},
): { scheduler: ReturnType<typeof createHeartbeatScheduler>; pending: () => number } {
  const reporter = createErrorReporter({ appVersion: '2.2.0', deviceId: 'd' })
  const scheduler = createHeartbeatScheduler({
    api,
    reporter,
    snapshot: () => ({ appVersion: '2.2.0', pendingQueueSize: 0 }),
    clock: fixedClock(new Date('2026-10-01T06:00:00.000Z')),
    ...extra,
  })
  return { scheduler, pending: () => reporter.size() }
}

describe('RF-ID-04 · el latido recoge el relevo', () => {
  it('adopta el relevo, avisa a la pantalla con la caducidad nueva y no toca nada si no viene', async () => {
    const adoptToken = vi.fn(async () => 'adopted' as const)
    const onTokenRotated = vi.fn()
    const sendHeartbeat = vi
      .fn()
      .mockResolvedValueOnce({
        outcome: 'ok',
        data: heartbeatResponse({ value: NEW_TOKEN, expires_at: NEW_EXPIRES }),
      })
      .mockResolvedValueOnce({ outcome: 'ok', data: heartbeatResponse() })
    const { scheduler } = schedulerWith(apiStub({ sendHeartbeat }), { adoptToken, onTokenRotated })

    await scheduler.beat()
    await scheduler.beat()

    expect(adoptToken).toHaveBeenCalledTimes(1)
    expect(adoptToken).toHaveBeenCalledWith({ value: NEW_TOKEN, expires_at: NEW_EXPIRES })
    expect(onTokenRotated).toHaveBeenCalledTimes(1)
    expect(onTokenRotated).toHaveBeenCalledWith(NEW_EXPIRES)
  })

  it('con el adoptador real: el token y el padron cambian y el latido sigue completandose', async () => {
    const storage = createMemoryQueueStorage()
    register(await rosterReadyWith(storage))
    const sendHeartbeat = vi.fn(async () => ({
      outcome: 'ok' as const,
      data: heartbeatResponse({ value: NEW_TOKEN, expires_at: NEW_EXPIRES }),
    }))
    const { scheduler } = schedulerWith(apiStub({ sendHeartbeat }))

    const skew = await scheduler.beat()

    expect(skew).toBe(0)
    expect(readDeviceToken()).toBe(NEW_TOKEN)
    expect(await storedRosterOpensWith(storage, NEW_TOKEN)).toBe(true)
  })

  it('regla dura 19: un relevo que no se puede guardar deja un aviso sin datos personales y el latido NO falla', async () => {
    const adoptToken = vi.fn(async () => 'reseal_failed' as const)
    const reporter = createErrorReporter({ appVersion: '2.2.0', deviceId: 'd' })
    const scheduler = createHeartbeatScheduler({
      api: apiStub({
        sendHeartbeat: vi.fn(async () => ({
          outcome: 'ok' as const,
          data: heartbeatResponse({ value: NEW_TOKEN, expires_at: NEW_EXPIRES }),
        })),
      }),
      reporter,
      snapshot: () => ({ appVersion: '2.2.0', pendingQueueSize: 0 }),
      clock: fixedClock(new Date('2026-10-01T06:00:00.000Z')),
      adoptToken,
    })

    await expect(scheduler.beat()).resolves.toBe(0)

    const [event] = reporter.pending()
    expect(event?.code).toBe('kiosk.heartbeat.failed')
    expect(event?.context['cause']).toBe('token_rotation_reseal_failed')
    expect(JSON.stringify(event)).not.toContain(NEW_TOKEN)
    expect(JSON.stringify(event)).not.toContain(OLD_TOKEN)
  })

  it('un adoptador que lanza no tumba el latido', async () => {
    const adoptToken = vi.fn(async () => {
      throw new Error('boom')
    })
    const { scheduler } = schedulerWith(
      apiStub({
        sendHeartbeat: vi.fn(async () => ({
          outcome: 'ok' as const,
          data: heartbeatResponse({ value: NEW_TOKEN, expires_at: NEW_EXPIRES }),
        })),
      }),
      { adoptToken },
    )

    await expect(scheduler.beat()).resolves.toBe(0)
  })

  it('latidos en serie: pedir otro mientras hay uno en vuelo no manda una segunda peticion', async () => {
    const deferred = deferredGate()
    const gate = deferred.promise
    const sendHeartbeat = vi.fn(async () => {
      await gate
      return { outcome: 'ok' as const, data: heartbeatResponse() }
    })
    const first = schedulerWith(apiStub({ sendHeartbeat })).scheduler
    const second = schedulerWith(apiStub({ sendHeartbeat })).scheduler

    const inFlight = first.beat()
    const coalesced = second.beat()
    deferred.open()
    await Promise.all([inFlight, coalesced])

    expect(sendHeartbeat).toHaveBeenCalledTimes(1)

    // Y una vez terminado, el siguiente SI sale.
    await first.beat()
    expect(sendHeartbeat).toHaveBeenCalledTimes(2)
  })
})

type FetchLike = (url: string, init?: RequestInit) => Promise<Response>

function bearerOf(init: RequestInit | undefined): string | undefined {
  return (init?.headers as Record<string, string> | undefined)?.['Authorization']
}

const SCAN_REQUEST = {
  scan_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
  occurred_at: '2026-10-01T05:58:31.000Z',
  qr_payload: CARD_PAYLOAD,
  intent: 'auto' as const,
}
const SCAN_OK = {
  scan_id: SCAN_REQUEST.scan_id,
  action: 'clock_in',
  employee_display_name: 'Lucia G.',
  work_date: '2026-10-01',
  occurred_at: SCAN_REQUEST.occurred_at,
  recorded_at: '2026-10-01T05:58:31.412Z',
  worked_minutes: 0,
}

describe('RF-ID-04 · peticion en vuelo firmada con el token viejo', () => {
  it('un 401 tras el primer uso del nuevo se repite UNA vez con el vigente y la misma Idempotency-Key', async () => {
    const fetchImpl = vi.fn<FetchLike>(async (_url, init) => {
      if (bearerOf(init) === `Bearer ${OLD_TOKEN}`) {
        // El token cambia MIENTRAS la peticion viaja (el latido lo adopto).
        storeRotatedDeviceToken(NEW_TOKEN, NEW_EXPIRES, null)
        return new Response(JSON.stringify({ title: 'No autenticado' }), { status: 401 })
      }
      return new Response(JSON.stringify(SCAN_OK), { status: 200 })
    })
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: readDeviceToken,
    })

    const result = await client.recordScan(SCAN_REQUEST)

    expect(result.outcome).toBe('ok')
    expect(fetchImpl).toHaveBeenCalledTimes(2)
    expect(bearerOf(fetchImpl.mock.calls[0]?.[1])).toBe(`Bearer ${OLD_TOKEN}`)
    expect(bearerOf(fetchImpl.mock.calls[1]?.[1])).toBe(`Bearer ${NEW_TOKEN}`)
    const keys = fetchImpl.mock.calls.map(
      (call) => (call[1]?.headers as Record<string, string>)['Idempotency-Key'],
    )
    expect(keys).toEqual([SCAN_REQUEST.scan_id, SCAN_REQUEST.scan_id])
  })

  it('el reintento es UNO solo: si el vigente tambien recibe 401, se devuelve el fallo', async () => {
    const fetchImpl = vi.fn<FetchLike>(async (_url, init) => {
      if (bearerOf(init) === `Bearer ${OLD_TOKEN}`) {
        storeRotatedDeviceToken(NEW_TOKEN, NEW_EXPIRES, null)
      }
      return new Response('{}', { status: 401 })
    })
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: readDeviceToken,
    })

    const result = await client.recordScan(SCAN_REQUEST)

    expect(result).toMatchObject({ outcome: 'failed', cause: 'unauthorized', httpStatus: 401 })
    expect(fetchImpl).toHaveBeenCalledTimes(2)
  })

  it('con el token sin cambios, un 401 es un 401: no se repite nada', async () => {
    const fetchImpl = vi.fn<FetchLike>(async () => new Response('{}', { status: 401 }))
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: readDeviceToken,
    })

    const result = await client.syncScanBatch({ scans: [SCAN_REQUEST] }, 'clave-del-lote')

    expect(result).toMatchObject({ outcome: 'failed', cause: 'unauthorized' })
    expect(fetchImpl).toHaveBeenCalledTimes(1)
  })

  it('un 403 no se repite aunque el token haya cambiado (no es el solape)', async () => {
    const fetchImpl = vi.fn<FetchLike>(async () => {
      storeRotatedDeviceToken(NEW_TOKEN, NEW_EXPIRES, null)
      return new Response('{}', { status: 403 })
    })
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: readDeviceToken,
    })

    await client.fetchRoster()

    expect(fetchImpl).toHaveBeenCalledTimes(1)
  })

  it('las rutas publicas (sin token) nunca se repiten', async () => {
    const fetchImpl = vi.fn<FetchLike>(async () => new Response('{}', { status: 401 }))
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: readDeviceToken,
    })

    await client.fetchBranding()

    expect(fetchImpl).toHaveBeenCalledTimes(1)
    expect(bearerOf(fetchImpl.mock.calls[0]?.[1])).toBeUndefined()
  })
})

describe('RF-ID-04 · la rotacion no provoca una falsa desvinculacion', () => {
  it('varios 401 por el token viejo, repetidos con exito, nunca llegan al contador de revocacion', async () => {
    const onRevoked = vi.fn()
    const watcher = createDeviceRevocationWatcher({ onRevoked })
    const tokens = [OLD_TOKEN, NEW_TOKEN, REDELIVERED_TOKEN]
    let generation = 0
    const fetchImpl = vi.fn<FetchLike>(async (_url, init) => {
      // Cada peticion viaja con el token de su generacion; antes de llegar, el
      // quiosco ya ha rotado a la siguiente: el viejo recibe 401.
      if (bearerOf(init) === `Bearer ${tokens[generation]}` && generation < tokens.length - 1) {
        generation += 1
        storeRotatedDeviceToken(tokens[generation] ?? NEW_TOKEN, NEW_EXPIRES, null)
        return new Response('{}', { status: 401 })
      }
      return new Response(JSON.stringify(heartbeatResponse()), { status: 200 })
    })
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: readDeviceToken,
    })
    const { scheduler } = schedulerWith(client, {
      onAuthOutcome: (unauthorized) =>
        unauthorized ? watcher.reportUnauthorized() : watcher.reportAuthenticated(),
    })

    await scheduler.beat()
    await scheduler.beat()
    await scheduler.beat()

    expect(onRevoked).not.toHaveBeenCalled()
    expect(readDeviceToken()).toBe(REDELIVERED_TOKEN)
  })

  it('una revocacion REAL (dos 401 seguidos con el mismo token) sigue disparando', async () => {
    const onRevoked = vi.fn()
    const watcher = createDeviceRevocationWatcher({ onRevoked })
    const fetchImpl = vi.fn<FetchLike>(async () => new Response('{}', { status: 401 }))
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: readDeviceToken,
    })
    const { scheduler } = schedulerWith(client, {
      onAuthOutcome: (unauthorized) =>
        unauthorized ? watcher.reportUnauthorized() : watcher.reportAuthenticated(),
    })

    await scheduler.beat()
    await scheduler.beat()

    expect(onRevoked).toHaveBeenCalledTimes(1)
    expect(fetchImpl).toHaveBeenCalledTimes(2)
  })
})

const T1 = '41|token-vigente-t1'
const T2 = '42|token-relevo-t2-ya-retirado'
const T3 = '43|token-relevo-t3-vigente'

describe('RF-ID-04 · dos relevos cruzados (el segundo retira al primero)', () => {
  beforeEach(() => {
    pairWith(T1, OLD_EXPIRES)
  })

  it('llega primero el vigente (T3) y despues el retirado (T2): se queda con T3', async () => {
    const storage = createMemoryQueueStorage()
    register(await rosterReadyWith(storage))

    expect(await adoptRotatedToken({ value: T3, expires_at: NEW_EXPIRES })).toBe('adopted')
    expect(await adoptRotatedToken({ value: T2, expires_at: '2026-12-31T06:00:00.000Z' })).toBe(
      'stale',
    )

    expect(readDeviceToken()).toBe(T3)
    expect(readDeviceTokenExpiresAt()).toBe(NEW_EXPIRES)
    expect(await storedRosterOpensWith(storage, T3)).toBe(true)
  })

  it('las dos respuestas procesadas a la vez, en cualquier orden, acaban en el id mayor', async () => {
    for (const order of ['t3-first', 't2-first'] as const) {
      localStorage.clear()
      pairWith(T1, OLD_EXPIRES)
      const storage = createMemoryQueueStorage()
      register(await rosterReadyWith(storage))
      const t2 = { value: T2, expires_at: '2026-12-31T06:00:00.000Z' }
      const t3 = { value: T3, expires_at: NEW_EXPIRES }

      await Promise.all(
        order === 't3-first'
          ? [adoptRotatedToken(t3), adoptRotatedToken(t2)]
          : [adoptRotatedToken(t2), adoptRotatedToken(t3)],
      )

      expect(readDeviceToken(), order).toBe(T3)
      expect(await storedRosterOpensWith(storage, T3), order).toBe(true)
      unregister?.()
    }
  })

  it('un relevo sin id reconocible (formato ajeno) se adopta: el id protege, no condiciona', async () => {
    register(await rosterReadyWith(createMemoryQueueStorage()))

    expect(await adoptRotatedToken({ value: 'sin-id-de-sanctum', expires_at: NEW_EXPIRES })).toBe(
      'adopted',
    )
  })
})

describe('RF-ID-04 · el token anterior queda de respaldo hasta el primer uso del nuevo', () => {
  beforeEach(() => {
    pairWith(T1, OLD_EXPIRES)
  })

  it('al adoptar se guarda el anterior; el primer uso autenticado del nuevo lo borra', async () => {
    register(await rosterReadyWith(createMemoryQueueStorage()))

    await adoptRotatedToken({ value: T2, expires_at: NEW_EXPIRES })
    expect(readPreviousDeviceToken()).toEqual({ value: T1, expiresAt: OLD_EXPIRES })

    confirmDeviceToken(T1) // no es el vigente: no cuenta
    expect(readPreviousDeviceToken()).not.toBeNull()

    confirmDeviceToken(T2)
    expect(readPreviousDeviceToken()).toBeNull()
  })

  it('un segundo relevo antes de usar el primero NO pisa el respaldo vivo con el token muerto', async () => {
    register(await rosterReadyWith(createMemoryQueueStorage()))

    await adoptRotatedToken({ value: T2, expires_at: NEW_EXPIRES })
    await adoptRotatedToken({ value: T3, expires_at: NEW_EXPIRES })

    expect(readDeviceToken()).toBe(T3)
    expect(readPreviousDeviceToken()?.value).toBe(T1)
  })

  it('volver al respaldo re-cifra el padron con su clave y deja la tablet sin respaldo', async () => {
    const storage = createMemoryQueueStorage()
    register(await rosterReadyWith(storage))
    await adoptRotatedToken({ value: T2, expires_at: NEW_EXPIRES })
    expect(await storedRosterOpensWith(storage, T2)).toBe(true)

    expect(await revertToPreviousToken()).toBe('adopted')

    expect(readDeviceToken()).toBe(T1)
    expect(readDeviceTokenExpiresAt()).toBe(OLD_EXPIRES)
    expect(readPreviousDeviceToken()).toBeNull()
    expect(await storedRosterOpensWith(storage, T1)).toBe(true)
    expect(await storedRosterOpensWith(storage, T2)).toBe(false)
  })

  it('sin respaldo no hay nada a lo que volver', async () => {
    register(await rosterReadyWith(createMemoryQueueStorage()))

    expect(await revertToPreviousToken()).toBe('already_current')
    expect(readDeviceToken()).toBe(T1)
  })

  it('desvincular borra tambien el respaldo (la revocacion no tiene solape)', async () => {
    register(await rosterReadyWith(createMemoryQueueStorage()))
    await adoptRotatedToken({ value: T2, expires_at: NEW_EXPIRES })

    clearDeviceToken()

    expect(readDeviceToken()).toBeNull()
    expect(readPreviousDeviceToken()).toBeNull()
  })

  it('un emparejamiento nuevo empieza sin respaldo de ningun token anterior', async () => {
    register(await rosterReadyWith(createMemoryQueueStorage()))
    await adoptRotatedToken({ value: T2, expires_at: NEW_EXPIRES })

    persistPairedDevice('50|token-de-otro-emparejamiento', 'dev-1', NEW_EXPIRES, 'Recepcion')

    expect(readPreviousDeviceToken()).toBeNull()
  })

  it('si el almacenamiento falla a mitad, ninguna clave cambia (token, caducidad ni respaldo)', () => {
    const original = Storage.prototype.setItem
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (
      this: Storage,
      key: string,
      value: string,
    ) {
      if (key === TOKEN_KEY) throw new DOMException('cuota', 'QuotaExceededError')
      original.call(this, key, value)
    })

    expect(storeRotatedDeviceToken(T2, NEW_EXPIRES, { value: T1, expiresAt: OLD_EXPIRES })).toBe(
      false,
    )

    expect(readDeviceToken()).toBe(T1)
    expect(readDeviceTokenExpiresAt()).toBe(OLD_EXPIRES)
    expect(readPreviousDeviceToken()).toBeNull()
  })
})

describe('RF-ID-04 · ante un 401 con el relevo, el cliente reintenta una vez con el respaldo', () => {
  beforeEach(() => {
    pairWith(T2, NEW_EXPIRES)
    localStorage.setItem('kronoqr.kiosk.device_token_previous', T1)
  })

  function clientWith(
    fetchImpl: ReturnType<typeof vi.fn<FetchLike>>,
    hooks: { confirmed?: () => void; fallback?: () => void } = {},
  ): ApiClient {
    return createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: readDeviceToken,
      fallbackDeviceToken: () => readPreviousDeviceToken()?.value ?? null,
      onDeviceTokenConfirmed: () => hooks.confirmed?.(),
      onFallbackTokenConfirmed: () => hooks.fallback?.(),
    })
  }

  it('el relevo esta muerto y el respaldo vive: acepta el respaldo y avisa para volver a el', async () => {
    const fallback = vi.fn()
    const confirmed = vi.fn()
    const fetchImpl = vi.fn<FetchLike>(async (_url, init) =>
      bearerOf(init) === `Bearer ${T1}`
        ? new Response(JSON.stringify(SCAN_OK), { status: 200 })
        : new Response('{}', { status: 401 }),
    )

    const result = await clientWith(fetchImpl, { fallback, confirmed }).recordScan(SCAN_REQUEST)

    expect(result.outcome).toBe('ok')
    expect(fetchImpl.mock.calls.map((call) => bearerOf(call[1]))).toEqual([
      `Bearer ${T2}`,
      `Bearer ${T1}`,
    ])
    expect(fallback).toHaveBeenCalledTimes(1)
    expect(confirmed).not.toHaveBeenCalled()
  })

  it('si el respaldo tambien da 401, devuelve el 401 original tras DOS intentos y nada mas', async () => {
    const fallback = vi.fn()
    const fetchImpl = vi.fn<FetchLike>(async () => new Response('{}', { status: 401 }))

    const result = await clientWith(fetchImpl, { fallback }).recordScan(SCAN_REQUEST)

    expect(result).toMatchObject({ outcome: 'failed', cause: 'unauthorized', httpStatus: 401 })
    expect(fetchImpl).toHaveBeenCalledTimes(2)
    expect(fallback).not.toHaveBeenCalled()
  })

  it('el primer uso con exito del vigente se notifica con el token que firmo', async () => {
    const confirmed = vi.fn()
    const fetchImpl = vi.fn<FetchLike>(
      async () => new Response(JSON.stringify(SCAN_OK), { status: 200 }),
    )
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: readDeviceToken,
      onDeviceTokenConfirmed: confirmed,
    })

    await client.recordScan(SCAN_REQUEST)

    expect(confirmed).toHaveBeenCalledWith(T2)
  })

  it.each([401, 403, 429, 502])('un %i NO confirma el token', async (status) => {
    const confirmed = vi.fn()
    const fetchImpl = vi.fn<FetchLike>(async () => new Response('{}', { status }))
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: readDeviceToken,
      onDeviceTokenConfirmed: confirmed,
    })

    await client.fetchRoster()

    expect(confirmed).not.toHaveBeenCalled()
  })
})

describe('RF-ID-04 · dos relevos cruzados de punta a punta: nunca una falsa revocacion', () => {
  it('relevo muerto (T2) -> 401 -> respaldo (T1) -> vuelve a T1 -> adopta el T3 que trae esa misma respuesta', async () => {
    pairWith(T1, OLD_EXPIRES)
    const storage = createMemoryQueueStorage()
    register(await rosterReadyWith(storage))
    const onRevoked = vi.fn()
    const watcher = createDeviceRevocationWatcher({ onRevoked })

    // El servidor: T2 fue retirado al emitir T3; T1 sigue en solape; T3 vigente.
    const alive = new Set([T1, T3])
    let heartbeats = 0
    const fetchImpl = vi.fn<FetchLike>(async (_url, init) => {
      const bearer = (bearerOf(init) ?? '').replace('Bearer ', '')
      if (!alive.has(bearer)) return new Response('{}', { status: 401 })
      heartbeats += 1
      // 1.er latido (T1): llega el relevo T2, que ya esta muerto. 2.o (T1 por
      // respaldo): el servidor lo reentrega como T3. Despues, nada.
      const body =
        heartbeats === 1
          ? heartbeatResponse({ value: T2, expires_at: NEW_EXPIRES })
          : heartbeats === 2
            ? heartbeatResponse({ value: T3, expires_at: NEW_EXPIRES })
            : heartbeatResponse()
      return new Response(JSON.stringify(body), { status: 200 })
    })
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      ...deviceTokenApiOptions,
    })
    const { scheduler } = schedulerWith(client, {
      onAuthOutcome: (unauthorized) =>
        unauthorized ? watcher.reportUnauthorized() : watcher.reportAuthenticated(),
    })

    await scheduler.beat() // firmado con T1 -> adopta T2 (muerto)
    expect(readDeviceToken()).toBe(T2)

    await scheduler.beat() // firmado con T2 -> 401 -> respaldo T1 -> 200 con T3
    await vi.waitFor(() => expect(readDeviceToken()).toBe(T3))

    await scheduler.beat() // firmado con T3 -> 200: el primer uso borra el respaldo
    expect(readPreviousDeviceToken()).toBeNull()
    expect(onRevoked).not.toHaveBeenCalled()
    expect(await storedRosterOpensWith(storage, T3)).toBe(true)
  })
})
