// Estado de la presencia en vivo (RF-PA-01, RF-PA-02).
//
// Una foto (`GET /attendance/live`) y, a partir de ahi, dos formas de
// mantenerla al dia que el usuario tiene que poder distinguir a simple vista:
//
//  - **Tiempo real**: el canal privado de Reverb entrega un `presence.updated`
//    por cada cambio y la fila de esa persona se sustituye sin volver a pedir
//    nada (ADR-011).
//  - **Sondeo**: si el canal no se establece, se cae, o la instalacion lo tiene
//    apagado (`meta.realtime.enabled: false`, la unica degradacion parcial de
//    ADR-023), se vuelve a pedir la foto cada `poll_interval_seconds` y la
//    pantalla **lo anuncia** (RNF-D-03). Sin aviso, quien mira cree que no entra
//    nadie.
//
// EL RELOJ ES EL DEL SERVIDOR. El tiempo transcurrido se calcula contra
// `meta.generated_at` y no contra el reloj del navegador: un portatil con la
// hora desfasada mostraria minutos que nadie ha trabajado (regla dura 3, y el
// mismo motivo por el que RF-AT-10 mide el desfase de los quioscos).
import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type {
  LivePresenceEntry,
  LivePresenceMeta,
  PresenceUpdatedMessage,
} from '@/shared/api/types'
import { authorizeChannel, listLivePresence } from './live.api'
import type { LivePresenceQuery } from './live.api'
import { RealtimeClient } from './realtime/pusherClient'
import type { RealtimeState, SocketFactory } from './realtime/pusherClient'

/** Por que via se mantiene la foto al dia. `idle` = todavia no se ha pedido. */
export type PresenceTransport = 'idle' | 'realtime' | 'polling'

/** Como comparar dos nombres del mismo modo en el que ordena el servidor: apellidos, nombre y UUID de desempate. */
function compareEntries(left: LivePresenceEntry, right: LivePresenceEntry): number {
  const byName = left.full_name.localeCompare(right.full_name, undefined, { sensitivity: 'base' })

  return byName !== 0 ? byName : left.employee_uuid.localeCompare(right.employee_uuid)
}

function normalise(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

/** Si una fila pertenece al conjunto que describe el filtro actual. */
export function matchesQuery(entry: LivePresenceEntry, query: LivePresenceQuery): boolean {
  if ((query.status ?? 'present') !== entry.status) {
    return false
  }

  if (query.departmentId !== undefined && entry.department?.id !== query.departmentId) {
    return false
  }

  if (query.q !== undefined && query.q !== '') {
    return normalise(entry.full_name).includes(normalise(query.q))
  }

  return true
}

export interface PresenceStoreOptions {
  /** Solo para pruebas: sustituye el `WebSocket` del navegador. */
  createSocket?: SocketFactory
}

let socketFactoryOverride: SocketFactory | undefined

/** Solo para pruebas unitarias: el E2E simula el `WebSocket` real con Playwright. */
export function overrideSocketFactory(factory: SocketFactory | undefined): void {
  socketFactoryOverride = factory
}

export const useLivePresenceStore = defineStore('livePresence', () => {
  const entries = shallowRef<LivePresenceEntry[]>([])
  const meta = ref<LivePresenceMeta | null>(null)
  const filters = ref<LivePresenceQuery>({ status: 'present' })
  const loading = ref(false)
  const error = ref<unknown>(null)
  const transport = ref<PresenceTransport>('idle')
  const realtimeState = ref<RealtimeState>('down')
  /** `Date.now()` del navegador en el momento en que llego `meta.generated_at`. */
  const receivedAt = ref(0)

  let client: RealtimeClient | null = null
  let pollTimer: ReturnType<typeof setTimeout> | undefined
  /** Ultimo `occurred_at` aplicado por persona, para descartar mensajes reordenados. */
  const latestByEmployee = new Map<string, string>()

  const pollIntervalMs = computed(() => (meta.value?.realtime.poll_interval_seconds ?? 15) * 1_000)
  const realtimeAvailable = computed(
    () => meta.value !== null && meta.value.realtime.enabled && meta.value.realtime.key !== null,
  )
  const timeZone = computed(() => meta.value?.time_zone ?? 'UTC')

  /** El «ahora» del servidor, extrapolado desde la ultima foto con el reloj monotono local. */
  function serverNowMs(now: number = Date.now()): number {
    if (meta.value === null) {
      return now
    }

    // Nunca hacia atras: el reloj local solo aporta lo transcurrido desde la foto.
    return Date.parse(meta.value.generated_at) + Math.max(now - receivedAt.value, 0)
  }

  function applyBoard(data: LivePresenceEntry[], boardMeta: LivePresenceMeta): void {
    entries.value = [...data].sort(compareEntries)
    meta.value = boardMeta
    receivedAt.value = Date.now()
    latestByEmployee.clear()
  }

  async function load(): Promise<void> {
    loading.value = entries.value.length === 0 && meta.value === null
    error.value = null

    try {
      const board = await listLivePresence(filters.value)

      applyBoard(board.data, board.meta)
    } catch (caught) {
      error.value = caught
    } finally {
      loading.value = false
    }
  }

  /**
   * Un `presence.updated`: sustituye la fila de esa persona, la mete o la saca
   * segun el filtro, y ajusta los recuentos. Nunca vuelve a consultar.
   */
  function applyMessage(message: PresenceUpdatedMessage): void {
    const { entry, occurred_at: occurredAt } = message
    const previous = latestByEmployee.get(entry.employee_uuid)

    if (previous !== undefined && previous >= occurredAt) {
      return
    }

    latestByEmployee.set(entry.employee_uuid, occurredAt)

    const index = entries.value.findIndex((item) => item.employee_uuid === entry.employee_uuid)
    const before = index >= 0 ? entries.value[index] : undefined
    const inScopeOfCounts =
      (filters.value.departmentId === undefined ||
        entry.department?.id === filters.value.departmentId) &&
      (filters.value.q === undefined ||
        filters.value.q === '' ||
        normalise(entry.full_name).includes(normalise(filters.value.q)))

    if (meta.value !== null && inScopeOfCounts) {
      const previousStatus =
        before?.status ?? ((filters.value.status ?? 'present') === 'present' ? 'absent' : 'present')

      if (previousStatus !== entry.status) {
        const next = { ...meta.value }

        if (entry.status === 'present') {
          next.present_count += 1
          next.absent_count = Math.max(next.absent_count - 1, 0)
        } else {
          next.absent_count += 1
          next.present_count = Math.max(next.present_count - 1, 0)
        }

        next.total = next.present_count + next.absent_count
        meta.value = next
      }
    }

    const next = entries.value.filter((item) => item.employee_uuid !== entry.employee_uuid)

    if (matchesQuery(entry, filters.value)) {
      next.push(entry)
      next.sort(compareEntries)
    }

    entries.value = next
  }

  function stopPolling(): void {
    clearTimeout(pollTimer)
    pollTimer = undefined
  }

  function schedulePoll(): void {
    stopPolling()
    pollTimer = setTimeout(() => {
      void load().finally(() => {
        if (transport.value === 'polling') {
          schedulePoll()
        }
      })
    }, pollIntervalMs.value)
  }

  function startPolling(): void {
    transport.value = 'polling'
    schedulePoll()
  }

  function stopRealtime(): void {
    client?.close()
    client = null
    realtimeState.value = 'down'
  }

  function startRealtime(): void {
    const current = meta.value

    if (current === null || current.realtime.key === null) {
      startPolling()

      return
    }

    const realtime = current.realtime

    stopRealtime()
    client = new RealtimeClient({
      key: realtime.key ?? '',
      path: realtime.path,
      channels: realtime.channels,
      event: realtime.event,
      clientVersion: typeof __APP_VERSION__ !== 'undefined' ? __APP_VERSION__ : 'dev',
      authorize: async (socketId, channelName) =>
        (await authorizeChannel(realtime.auth_endpoint, socketId, channelName)).auth,
      onEvent: (_channel, payload) => {
        if (isPresenceMessage(payload)) {
          applyMessage(payload)
        }
      },
      onStateChange: (state) => {
        realtimeState.value = state

        if (state === 'live') {
          // Lo que haya pasado mientras el canal estaba caido no ha llegado por
          // el: una foto nueva y se deja de sondear.
          stopPolling()
          transport.value = 'realtime'
          void load()
        } else if (transport.value !== 'polling') {
          startPolling()
        }
      },
      ...(socketFactoryOverride === undefined ? {} : { createSocket: socketFactoryOverride }),
    })
    client.connect()
  }

  /** Pide la foto y, segun lo que diga `meta.realtime`, se suscribe o sondea. */
  async function connect(): Promise<void> {
    await load()

    if (meta.value === null) {
      // Sin foto (error de red): se sigue intentando por sondeo con el intervalo por omision.
      startPolling()

      return
    }

    if (realtimeAvailable.value) {
      startRealtime()
    } else {
      startPolling()
    }
  }

  /** Cambia los filtros y vuelve a pedir la foto. El canal no depende de los filtros. */
  async function applyFilters(next: LivePresenceQuery): Promise<void> {
    filters.value = { ...next }
    await load()
  }

  /** Cierra canal y sondeo. Al salir de la pantalla. */
  function disconnect(): void {
    stopPolling()
    stopRealtime()
    transport.value = 'idle'
  }

  return {
    entries,
    meta,
    filters,
    loading,
    error,
    transport,
    realtimeState,
    realtimeAvailable,
    timeZone,
    pollIntervalMs,
    serverNowMs,
    load,
    connect,
    disconnect,
    applyFilters,
    applyMessage,
  }
})

function isPresenceMessage(payload: unknown): payload is PresenceUpdatedMessage {
  if (typeof payload !== 'object' || payload === null) {
    return false
  }

  const candidate = payload as { entry?: unknown; occurred_at?: unknown }

  return (
    typeof candidate.occurred_at === 'string' &&
    typeof candidate.entry === 'object' &&
    candidate.entry !== null &&
    typeof (candidate.entry as { employee_uuid?: unknown }).employee_uuid === 'string'
  )
}
