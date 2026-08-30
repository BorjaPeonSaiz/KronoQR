// Cliente minimo del protocolo Pusher (ADR-011). Se prueba contra un socket
// falso que registra lo enviado y deja inyectar lo recibido: lo que importa es
// el dialogo (saludo, firma, suscripcion, latidos, reconexion), no el transporte.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RealtimeClient } from '@/features/live/realtime/pusherClient'
import type { RealtimeState, SocketLike } from '@/features/live/realtime/pusherClient'

class FakeSocket implements SocketLike {
  public onopen: ((event: unknown) => void) | null = null

  public onmessage: ((event: { data: unknown }) => void) | null = null

  public onclose: ((event: unknown) => void) | null = null

  public onerror: ((event: unknown) => void) | null = null

  public readonly sent: { event: string; data?: unknown }[] = []

  public closed = false

  public constructor(public readonly url: string) {}

  public send(data: string): void {
    this.sent.push(JSON.parse(data) as { event: string; data?: unknown })
  }

  public close(): void {
    this.closed = true
    this.onclose?.({})
  }

  /** Lo que diria el servidor. `data` viaja como cadena JSON, como en el cable real. */
  public receive(event: string, data: unknown = {}, channel?: string): void {
    this.onmessage?.({
      data: JSON.stringify({
        event,
        ...(channel === undefined ? {} : { channel }),
        data: JSON.stringify(data),
      }),
    })
  }

  public established(socketId = '123.456'): void {
    this.receive('pusher:connection_established', { socket_id: socketId, activity_timeout: 120 })
  }
}

function harness(channels: string[] = ['presence.all']) {
  const sockets: FakeSocket[] = []
  const states: RealtimeState[] = []
  const events: { channel: string; payload: unknown }[] = []
  const authorize = vi.fn(
    async (socketId: string, channel: string) => `kronoqr:${socketId}:${channel}`,
  )

  const client = new RealtimeClient({
    key: 'kronoqr',
    path: '/app',
    channels,
    event: 'presence.updated',
    authorize,
    onEvent: (channel, payload) => events.push({ channel, payload }),
    onStateChange: (state) => states.push(state),
    origin: 'wss://panel.example',
    createSocket: (url) => {
      const socket = new FakeSocket(url)
      sockets.push(socket)

      return socket
    },
    clientVersion: '2.0.0',
  })

  return { client, sockets, states, events, authorize }
}

async function flush(): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
}

describe('RealtimeClient', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('abre el socket por el mismo origen, con la clave publica y la version del panel', () => {
    const { client, sockets, states } = harness()

    client.connect()

    expect(sockets).toHaveLength(1)
    expect(sockets[0]?.url).toBe(
      'wss://panel.example/app/kronoqr?protocol=7&client=kronoqr-admin&version=2.0.0&flash=false',
    )
    expect(states).toEqual(['connecting'])
  })

  it('firma cada canal privado con el socket_id del saludo y se suscribe', async () => {
    const { client, sockets, states, authorize } = harness([
      'presence.department.3',
      'presence.department.4',
    ])

    client.connect()
    sockets[0]?.established('9.8')
    await flush()

    expect(authorize).toHaveBeenCalledWith('9.8', 'private-presence.department.3')
    expect(authorize).toHaveBeenCalledWith('9.8', 'private-presence.department.4')
    expect(sockets[0]?.sent).toEqual([
      {
        event: 'pusher:subscribe',
        data: {
          channel: 'private-presence.department.3',
          auth: 'kronoqr:9.8:private-presence.department.3',
        },
      },
      {
        event: 'pusher:subscribe',
        data: {
          channel: 'private-presence.department.4',
          auth: 'kronoqr:9.8:private-presence.department.4',
        },
      },
    ])

    // No esta «en vivo» hasta que TODOS los canales confirman.
    sockets[0]?.receive(
      'pusher_internal:subscription_succeeded',
      {},
      'private-presence.department.3',
    )
    expect(states).toEqual(['connecting'])
    sockets[0]?.receive(
      'pusher_internal:subscription_succeeded',
      {},
      'private-presence.department.4',
    )
    expect(states).toEqual(['connecting', 'live'])
  })

  it('entrega el evento del canal sin el prefijo del cable y con el JSON ya abierto', async () => {
    const { client, sockets, events } = harness()

    client.connect()
    sockets[0]?.established()
    await flush()
    sockets[0]?.receive('pusher_internal:subscription_succeeded', {}, 'private-presence.all')
    sockets[0]?.receive(
      'presence.updated',
      { entry: { employee_uuid: 'u-1' }, occurred_at: 'x' },
      'private-presence.all',
    )
    // Otro evento cualquiera del canal no se entrega.
    sockets[0]?.receive('pusher_internal:member_added', {}, 'private-presence.all')

    expect(events).toEqual([
      { channel: 'presence.all', payload: { entry: { employee_uuid: 'u-1' }, occurred_at: 'x' } },
    ])
  })

  it('contesta al ping del servidor y envia el suyo tras el tiempo de actividad', async () => {
    const { client, sockets } = harness()

    client.connect()
    sockets[0]?.established()
    await flush()
    sockets[0]?.receive('pusher:ping')

    expect(sockets[0]?.sent.at(-1)).toEqual({ event: 'pusher:pong', data: {} })

    vi.advanceTimersByTime(120_000)

    expect(sockets[0]?.sent.at(-1)).toEqual({ event: 'pusher:ping', data: {} })

    // Sin pong en 30 s, el socket esta muerto aunque el navegador no lo sepa.
    vi.advanceTimersByTime(30_000)

    expect(sockets[0]?.closed).toBe(true)
  })

  it('reconecta con espera creciente cuando el servidor cierra, y avisa de la caida', async () => {
    const { client, sockets, states } = harness()

    client.connect()
    sockets[0]?.established()
    await flush()
    sockets[0]?.receive('pusher_internal:subscription_succeeded', {}, 'private-presence.all')

    sockets[0]?.onclose?.({})

    expect(states.at(-1)).toBe('down')
    expect(sockets).toHaveLength(1)

    vi.advanceTimersByTime(1_000)

    expect(sockets).toHaveLength(2)
    expect(states.at(-1)).toBe('connecting')

    // Segundo fallo seguido: el doble de espera.
    sockets[1]?.onclose?.({})
    vi.advanceTimersByTime(1_999)
    expect(sockets).toHaveLength(2)
    vi.advanceTimersByTime(1)
    expect(sockets).toHaveLength(3)
  })

  it('una firma denegada cierra y reintenta: el sondeo cubre mientras tanto', async () => {
    const { client, sockets, states, authorize } = harness()

    authorize.mockRejectedValueOnce(new Error('403'))
    client.connect()
    sockets[0]?.established()
    await flush()

    expect(sockets[0]?.closed).toBe(true)
    expect(states.at(-1)).toBe('down')
  })

  it('close() cierra sin reintentar nunca mas', async () => {
    const { client, sockets, states } = harness()

    client.connect()
    sockets[0]?.established()
    await flush()
    client.close()

    vi.advanceTimersByTime(60_000)

    expect(sockets).toHaveLength(1)
    expect(sockets[0]?.closed).toBe(true)
    expect(states.at(-1)).toBe('down')
  })

  it('una cuenta sin canales no tiene nada que oir: se queda en sondeo', async () => {
    const { client, sockets, states } = harness([])

    client.connect()
    sockets[0]?.established()
    await flush()

    expect(states.at(-1)).toBe('down')
    expect(sockets[0]?.closed).toBe(true)
  })
})
