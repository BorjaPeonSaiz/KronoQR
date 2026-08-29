// Cliente minimo del protocolo Pusher (version 7), que es el que habla Reverb
// (ADR-011).
//
// POR QUE NO `laravel-echo` + `pusher-js`. Lo que el panel necesita cabe en
// este fichero: abrir el socket por el mismo origen, firmar la suscripcion a
// uno o varios canales privados con el token Bearer, recibir un evento y
// contestar a los latidos. Las dos librerias traen ademas presencia, canales
// cifrados, transportes alternativos y su propia gestion de credenciales, y
// ninguna de esas cosas es del producto. Un cliente propio es tambien un
// cliente que el E2E puede simular fielmente con `page.routeWebSocket`, sin
// depender de como reintente una libreria por dentro.
//
// LO QUE ESTE CLIENTE NO HACE. No decide que enseñar: entrega estado y
// mensajes a quien lo crea (`presence.store.ts`), que es quien sabe que la
// caida del canal significa sondear cada N segundos y anunciarlo (RNF-D-03).
//
// LOS DETALLES DE CONEXION LLEGAN EN LA RESPUESTA DE LA API (`meta.realtime`),
// no en una variable de compilacion: el producto se instala en el servidor de
// cada cliente y una clave dentro de la build obligaria a recompilar la SPA por
// instalacion (ADR-017, regla dura 13). Host y puerto se toman del propio
// origen del panel.

/** Como esta el canal desde el punto de vista de quien pinta la pantalla. */
export type RealtimeState = 'connecting' | 'live' | 'down'

/** Lo minimo de un WebSocket que se usa aqui, para poder sustituirlo en pruebas. */
export interface SocketLike {
  onopen: ((event: unknown) => void) | null
  onmessage: ((event: { data: unknown }) => void) | null
  onclose: ((event: unknown) => void) | null
  onerror: ((event: unknown) => void) | null
  send: (data: string) => void
  close: () => void
}

export type SocketFactory = (url: string) => SocketLike

export interface RealtimeClientOptions {
  /** Clave publica de aplicacion (`meta.realtime.key`). No es un secreto. */
  key: string
  /** Ruta del WebSocket en el origen del panel (`meta.realtime.path`). */
  path: string
  /** Canales de negocio (`presence.all`, `presence.department.3`); el prefijo `private-` lo pone el cliente. */
  channels: readonly string[]
  /** Nombre del evento que transporta cada cambio (`meta.realtime.event`). */
  event: string
  /** Firma de la suscripcion: devuelve el campo `auth` que espera el servidor. */
  authorize: (socketId: string, channelName: string) => Promise<string>
  onEvent: (channel: string, payload: unknown) => void
  onStateChange: (state: RealtimeState) => void
  /** Origen `protocolo://host[:puerto]`. Por omision, el del documento. */
  origin?: string
  createSocket?: SocketFactory
  /** Version del panel, que viaja en el saludo para saber que paneles no se han actualizado. */
  clientVersion?: string
  /** Espera maxima de reconexion. Por omision 30 s. */
  maxBackoffMs?: number
  /** Tiempo que se concede al servidor para contestar un `ping`. Por omision 30 s. */
  pongTimeoutMs?: number
}

interface WireMessage {
  event: string
  channel?: string
  data?: unknown
}

const DEFAULT_ACTIVITY_TIMEOUT_S = 120
const DEFAULT_MAX_BACKOFF_MS = 30_000
const DEFAULT_PONG_TIMEOUT_MS = 30_000
const FIRST_BACKOFF_MS = 1_000

function defaultSocketFactory(url: string): SocketLike {
  return new WebSocket(url) as unknown as SocketLike
}

function defaultOrigin(): string {
  const { protocol, host } = globalThis.location
  const scheme = protocol === 'https:' ? 'wss:' : 'ws:'

  return `${scheme}//${host}`
}

/** El JSON del cable trae `data` como cadena JSON; en el E2E puede venir ya como objeto. */
function parseData(data: unknown): unknown {
  if (typeof data !== 'string') {
    return data
  }

  try {
    return JSON.parse(data)
  } catch {
    return data
  }
}

function parseWire(raw: unknown): WireMessage | null {
  if (typeof raw !== 'string') {
    return null
  }

  try {
    const parsed: unknown = JSON.parse(raw)

    if (
      typeof parsed === 'object' &&
      parsed !== null &&
      typeof (parsed as WireMessage).event === 'string'
    ) {
      return parsed as WireMessage
    }
  } catch {
    // Un mensaje que no es JSON no es del protocolo: se ignora.
  }

  return null
}

export class RealtimeClient {
  private socket: SocketLike | null = null

  private closed = false

  private attempts = 0

  private subscribed = new Set<string>()

  private reconnectTimer: ReturnType<typeof setTimeout> | undefined

  private activityTimer: ReturnType<typeof setTimeout> | undefined

  private pongTimer: ReturnType<typeof setTimeout> | undefined

  private activityTimeoutMs = DEFAULT_ACTIVITY_TIMEOUT_S * 1_000

  private state: RealtimeState = 'down'

  private readonly createSocket: SocketFactory

  public constructor(private readonly options: RealtimeClientOptions) {
    this.createSocket = options.createSocket ?? defaultSocketFactory
  }

  /** Abre el socket. Reintenta sola con espera creciente hasta que se llame a `close()`. */
  public connect(): void {
    if (this.closed) {
      return
    }

    this.setState('connecting')
    this.subscribed = new Set()

    const origin = this.options.origin ?? defaultOrigin()
    const version = encodeURIComponent(this.options.clientVersion ?? 'dev')
    const url = `${origin}${this.options.path}/${this.options.key}?protocol=7&client=kronoqr-admin&version=${version}&flash=false`

    let socket: SocketLike

    try {
      socket = this.createSocket(url)
    } catch {
      this.scheduleReconnect()

      return
    }

    this.socket = socket

    socket.onmessage = (event) => {
      this.touch()
      void this.handle(parseWire(event.data))
    }
    socket.onclose = () => {
      if (this.socket === socket) {
        this.socket = null
        this.scheduleReconnect()
      }
    }
    socket.onerror = () => {
      // El `close` que sigue a cada error es el que reprograma. Aqui nada.
    }
  }

  /** Cierra sin reintentar. Se llama al salir de la pantalla. */
  public close(): void {
    this.closed = true
    this.clearTimers()

    const socket = this.socket
    this.socket = null
    socket?.close()
    this.setState('down')
  }

  private async handle(message: WireMessage | null): Promise<void> {
    if (message === null) {
      return
    }

    switch (message.event) {
      case 'pusher:connection_established': {
        const data = parseData(message.data) as { socket_id?: unknown; activity_timeout?: unknown }
        const socketId = typeof data?.socket_id === 'string' ? data.socket_id : null

        if (socketId === null) {
          this.socket?.close()

          return
        }

        this.activityTimeoutMs =
          (typeof data.activity_timeout === 'number'
            ? data.activity_timeout
            : DEFAULT_ACTIVITY_TIMEOUT_S) * 1_000
        this.touch()
        await this.subscribeAll(socketId)

        return
      }
      case 'pusher_internal:subscription_succeeded': {
        if (typeof message.channel === 'string') {
          this.subscribed.add(message.channel)
        }

        if (this.subscribed.size === this.options.channels.length && this.subscribed.size > 0) {
          this.attempts = 0
          this.setState('live')
        }

        return
      }
      case 'pusher:ping':
        this.send({ event: 'pusher:pong', data: {} })

        return
      case 'pusher:pong':
        return
      case 'pusher:error':
        // Un error del protocolo (clave desconocida, cuota) no se arregla
        // reintentando en bucle rapido, pero tampoco para siempre: el cierre que
        // provoca entra en la espera creciente como cualquier otro.
        this.socket?.close()

        return
      default:
        if (message.event === this.options.event && typeof message.channel === 'string') {
          this.options.onEvent(message.channel.replace(/^private-/, ''), parseData(message.data))
        }
    }
  }

  private async subscribeAll(socketId: string): Promise<void> {
    if (this.options.channels.length === 0) {
      // Una cuenta sin canales no tiene nada que oir: el sondeo es su unica via.
      this.setState('down')
      this.socket?.close()

      return
    }

    for (const channel of this.options.channels) {
      const wire = `private-${channel}`

      try {
        const auth = await this.options.authorize(socketId, wire)

        this.send({ event: 'pusher:subscribe', data: { channel: wire, auth } })
      } catch {
        // Una firma denegada (403) o una red caida durante la firma: se cierra y
        // se reintenta con espera. El sondeo sigue mientras tanto.
        this.socket?.close()

        return
      }
    }
  }

  private send(message: WireMessage): void {
    try {
      this.socket?.send(JSON.stringify(message))
    } catch {
      this.socket?.close()
    }
  }

  /** Cualquier mensaje del servidor rearma el latido. */
  private touch(): void {
    clearTimeout(this.activityTimer)
    clearTimeout(this.pongTimer)
    this.pongTimer = undefined

    this.activityTimer = setTimeout(() => {
      this.send({ event: 'pusher:ping', data: {} })
      this.pongTimer = setTimeout(() => {
        // Sin respuesta al ping: el socket esta muerto aunque el navegador no lo sepa.
        this.socket?.close()
      }, this.options.pongTimeoutMs ?? DEFAULT_PONG_TIMEOUT_MS)
    }, this.activityTimeoutMs)
  }

  private scheduleReconnect(): void {
    this.clearTimers()
    this.setState('down')

    if (this.closed) {
      return
    }

    const delay = Math.min(
      FIRST_BACKOFF_MS * 2 ** this.attempts,
      this.options.maxBackoffMs ?? DEFAULT_MAX_BACKOFF_MS,
    )
    this.attempts += 1
    this.reconnectTimer = setTimeout(() => this.connect(), delay)
  }

  private clearTimers(): void {
    clearTimeout(this.reconnectTimer)
    clearTimeout(this.activityTimer)
    clearTimeout(this.pongTimer)
    this.reconnectTimer = undefined
    this.activityTimer = undefined
    this.pongTimer = undefined
  }

  private setState(state: RealtimeState): void {
    if (this.state !== state) {
      this.state = state
      this.options.onStateChange(state)
    }
  }
}
