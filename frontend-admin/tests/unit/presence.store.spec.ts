// Store de la presencia en vivo (RF-PA-01, RF-PA-02, ADR-011, RNF-D-03).
//
// Lo que se afirma: que un mensaje del canal sustituye la fila sin volver a
// consultar, que los recuentos cuadran, que un mensaje reordenado no pisa a uno
// mas reciente, que sin canal se sondea y con canal se deja de sondear, y que
// el tiempo transcurrido se calcula con el reloj del servidor.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { overrideSocketFactory, useLivePresenceStore } from '@/features/live/presence.store'
import type { SocketLike } from '@/features/live/realtime/pusherClient'
import type { LivePresenceBoard, LivePresenceEntry } from '@/shared/api/types'
import { createTestPinia, jsonResponse, stubFetch } from './support/harness'

const YOUSSEF: LivePresenceEntry = {
  employee_uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
  full_name: 'Youssef Amrani',
  department: { id: 3, name: 'Cocina' },
  status: 'present',
  shift_entry_uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
  clocked_in_at: '2026-03-14T05:00:00.000000Z',
  origin: 'qr_kiosk',
  device: { uuid: '0199f0d3-3c71-7e52-9a13-6f7a8b9c0d12', name: 'Entrada de personal' },
}

const LUCIA: LivePresenceEntry = {
  employee_uuid: '0199f0c2-2222-7c3e-9b21-4d5e6f7a8b91',
  full_name: 'Lucía Martínez',
  department: { id: 4, name: 'Recepción' },
  status: 'present',
  shift_entry_uuid: '0199f2c1-9b21-7b40-9c50-6d7e8f9a0b12',
  clocked_in_at: '2026-03-14T06:30:00.000000Z',
  origin: 'pin_kiosk',
  device: null,
}

function board(
  overrides: Partial<LivePresenceBoard['meta']> = {},
  data = [LUCIA, YOUSSEF],
): LivePresenceBoard {
  return {
    data,
    meta: {
      generated_at: '2026-03-14T09:00:00.000000Z',
      time_zone: 'Europe/Madrid',
      present_count: 2,
      absent_count: 5,
      total: 7,
      realtime: {
        enabled: false,
        key: null,
        path: '/app',
        auth_endpoint: '/api/v1/broadcasting/auth',
        event: 'presence.updated',
        channels: ['presence.all'],
        poll_interval_seconds: 15,
      },
      ...overrides,
    },
  }
}

class FakeSocket implements SocketLike {
  public onopen = null

  public onmessage: ((event: { data: unknown }) => void) | null = null

  public onclose: ((event: unknown) => void) | null = null

  public onerror = null

  public readonly sent: string[] = []

  public send(data: string): void {
    this.sent.push(data)
  }

  public close(): void {
    this.onclose?.({})
  }

  public receive(event: string, data: unknown, channel?: string): void {
    this.onmessage?.({
      data: JSON.stringify({
        event,
        ...(channel === undefined ? {} : { channel }),
        data: JSON.stringify(data),
      }),
    })
  }
}

async function flush(times = 4): Promise<void> {
  for (let index = 0; index < times; index += 1) {
    await Promise.resolve()
  }
}

describe('useLivePresenceStore', () => {
  let requests: string[]

  beforeEach(() => {
    createTestPinia()
    requests = []
  })

  afterEach(() => {
    overrideSocketFactory(undefined)
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  function serve(response: LivePresenceBoard): void {
    stubFetch((url) => {
      requests.push(url)

      if (url.includes('/api/v1/attendance/live')) {
        return jsonResponse(response)
      }

      if (url.includes('/api/v1/broadcasting/auth')) {
        return jsonResponse({ auth: 'kronoqr:firma' })
      }

      return jsonResponse({}, 404)
    })
  }

  it('pide la foto ordenada por nombre y guarda la meta', async () => {
    serve(board())
    const store = useLivePresenceStore()

    await store.load()

    expect(store.entries.map((entry) => entry.full_name)).toEqual([
      'Lucía Martínez',
      'Youssef Amrani',
    ])
    expect(store.meta?.present_count).toBe(2)
    expect(store.timeZone).toBe('Europe/Madrid')
    expect(requests[0]).toContain('status=present')
  })

  it('sustituye la fila de quien ficha sin volver a consultar y ajusta los recuentos', async () => {
    serve(board())
    const store = useLivePresenceStore()
    await store.load()
    const before = requests.length

    // Youssef sale: deja de estar en la lista de presentes.
    store.applyMessage({
      entry: {
        ...YOUSSEF,
        status: 'absent',
        shift_entry_uuid: null,
        clocked_in_at: null,
        origin: null,
        device: null,
      },
      occurred_at: '2026-03-14T13:05:00.000000Z',
    })

    expect(store.entries.map((entry) => entry.employee_uuid)).toEqual([LUCIA.employee_uuid])
    expect(store.meta?.present_count).toBe(1)
    expect(store.meta?.absent_count).toBe(6)
    expect(store.meta?.total).toBe(7)

    // Alguien nuevo entra: se inserta en su sitio alfabetico.
    store.applyMessage({
      entry: { ...YOUSSEF, employee_uuid: 'u-nuevo', full_name: 'Ana Pérez' },
      occurred_at: '2026-03-14T13:06:00.000000Z',
    })

    expect(store.entries.map((entry) => entry.full_name)).toEqual(['Ana Pérez', 'Lucía Martínez'])
    expect(store.meta?.present_count).toBe(2)
    expect(store.meta?.absent_count).toBe(5)
    expect(requests.length).toBe(before)
  })

  it('descarta un mensaje que llega tarde detras de otro mas reciente de la misma persona', async () => {
    serve(board())
    const store = useLivePresenceStore()
    await store.load()

    store.applyMessage({
      entry: {
        ...YOUSSEF,
        status: 'absent',
        shift_entry_uuid: null,
        clocked_in_at: null,
        origin: null,
        device: null,
      },
      occurred_at: '2026-03-14T13:05:00.000000Z',
    })
    // Su entrada de las 05:00 llega reordenada: ya no vale.
    store.applyMessage({ entry: YOUSSEF, occurred_at: '2026-03-14T05:00:00.000000Z' })

    expect(store.entries.some((entry) => entry.employee_uuid === YOUSSEF.employee_uuid)).toBe(false)
  })

  it('con el filtro de departamento, un cambio de otro departamento no toca ni la lista ni los recuentos', async () => {
    serve(board({ present_count: 1, absent_count: 2, total: 3 }, [YOUSSEF]))
    const store = useLivePresenceStore()
    await store.applyFilters({ status: 'present', departmentId: 3 })

    store.applyMessage({ entry: LUCIA, occurred_at: '2026-03-14T13:06:00.000000Z' })

    expect(store.entries).toHaveLength(1)
    expect(store.meta?.present_count).toBe(1)
  })

  it('sin tiempo real disponible, sondea con el intervalo que fija el servidor y lo dice', async () => {
    vi.useFakeTimers()
    serve(board({ realtime: { ...board().meta.realtime, poll_interval_seconds: 5 } }))
    const store = useLivePresenceStore()

    await store.connect()

    expect(store.transport).toBe('polling')
    expect(store.realtimeAvailable).toBe(false)
    expect(requests.filter((url) => url.includes('/attendance/live'))).toHaveLength(1)

    await vi.advanceTimersByTimeAsync(5_000)

    expect(requests.filter((url) => url.includes('/attendance/live'))).toHaveLength(2)

    store.disconnect()
    await vi.advanceTimersByTimeAsync(10_000)

    expect(requests.filter((url) => url.includes('/attendance/live'))).toHaveLength(2)
  })

  it('con el canal en vivo deja de sondear, y vuelve a sondear si el canal cae', async () => {
    vi.useFakeTimers()
    const sockets: FakeSocket[] = []
    overrideSocketFactory(() => {
      const socket = new FakeSocket()
      sockets.push(socket)

      return socket
    })
    serve(
      board({
        realtime: {
          ...board().meta.realtime,
          enabled: true,
          key: 'kronoqr',
          poll_interval_seconds: 5,
        },
      }),
    )
    const store = useLivePresenceStore()

    await store.connect()

    expect(store.realtimeAvailable).toBe(true)
    expect(sockets).toHaveLength(1)

    sockets[0]?.receive('pusher:connection_established', {
      socket_id: '1.1',
      activity_timeout: 120,
    })
    await flush(8)
    sockets[0]?.receive('pusher_internal:subscription_succeeded', {}, 'private-presence.all')
    await flush()

    expect(store.realtimeState).toBe('live')
    expect(store.transport).toBe('realtime')
    // Al entrar en vivo pide una foto nueva (lo que pasara mientras no habia canal) y ya no sondea.
    const liveRequests = () => requests.filter((url) => url.includes('/attendance/live')).length
    const afterLive = liveRequests()
    await vi.advanceTimersByTimeAsync(20_000)
    expect(liveRequests()).toBe(afterLive)

    // Un mensaje del canal actualiza la lista.
    sockets[0]?.receive(
      'presence.updated',
      {
        entry: { ...YOUSSEF, employee_uuid: 'u-3', full_name: 'Zoe Ruiz' },
        occurred_at: '2026-03-14T13:06:00.000000Z',
      },
      'private-presence.all',
    )
    expect(store.entries.some((entry) => entry.full_name === 'Zoe Ruiz')).toBe(true)

    // El canal cae: sondeo otra vez.
    sockets[0]?.close()
    await flush()
    expect(store.transport).toBe('polling')
    await vi.advanceTimersByTimeAsync(5_000)
    expect(liveRequests()).toBe(afterLive + 1)

    store.disconnect()
  })

  it('el «ahora» es el del servidor: la foto mas lo que ha pasado en el reloj local', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2030-01-01T00:00:00Z'))
    serve(board())
    const store = useLivePresenceStore()
    await store.load()

    // El navegador cree que es 2030; el servidor dijo 2026-03-14 09:00.
    expect(new Date(store.serverNowMs()).toISOString()).toBe('2026-03-14T09:00:00.000Z')

    vi.setSystemTime(new Date('2030-01-01T00:10:00Z'))

    expect(new Date(store.serverNowMs()).toISOString()).toBe('2026-03-14T09:10:00.000Z')
  })
})
