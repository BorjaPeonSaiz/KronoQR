// Ayudantes de la presencia en vivo compartidos por la prueba funcional
// (`live-presence.spec.ts`, en cada push) y la de rendimiento
// (`perf-live-presence.spec.ts`, nocturna, RNF-P-04).
import type { BrowserContext, WebSocketRoute } from '@playwright/test'
import type { LivePresenceBoard, LivePresenceEntry } from '@/shared/api/types'
import { LIVE_BOARD, LIVE_ENTRY } from './admin'

export interface FakeReverb {
  readonly sockets: WebSocketRoute[]
  /** Empuja un evento a todas las pestañas suscritas. */
  push(entry: LivePresenceEntry, occurredAt: string): void
}

/** Un Reverb de mentira que acepta cualquier suscripcion firmada. */
export async function fakeReverb(
  context: BrowserContext,
  behaviour: 'up' | 'down' = 'up',
): Promise<FakeReverb> {
  const sockets: WebSocketRoute[] = []

  await context.routeWebSocket(/\/app\/kronoqr/, (ws) => {
    if (behaviour === 'down') {
      ws.close({ code: 1006, reason: 'reverb caido' })

      return
    }

    sockets.push(ws)
    ws.send(
      JSON.stringify({
        event: 'pusher:connection_established',
        data: JSON.stringify({ socket_id: `${sockets.length}.1`, activity_timeout: 120 }),
      }),
    )
    ws.onMessage((raw) => {
      const message = JSON.parse(String(raw)) as { event: string; data?: { channel?: string } }

      if (message.event === 'pusher:subscribe') {
        ws.send(
          JSON.stringify({
            event: 'pusher_internal:subscription_succeeded',
            channel: message.data?.channel,
            data: '{}',
          }),
        )
      }

      if (message.event === 'pusher:ping') {
        ws.send(JSON.stringify({ event: 'pusher:pong', data: '{}' }))
      }
    })
  })

  return {
    sockets,
    push(entry, occurredAt) {
      for (const ws of sockets) {
        ws.send(
          JSON.stringify({
            event: 'presence.updated',
            channel: 'private-presence.all',
            data: JSON.stringify({ entry, occurred_at: occurredAt }),
          }),
        )
      }
    },
  }
}

/** El tablero con `count` personas presentes, repartidas en diez departamentos. */
export function boardOf(count: number): LivePresenceBoard {
  const data: LivePresenceEntry[] = Array.from({ length: count }, (_, index) => ({
    ...LIVE_ENTRY,
    employee_uuid: `0199f0c2-1f4a-7c3e-9b21-${String(index).padStart(12, '0')}`,
    full_name: `Persona ${String(index).padStart(3, '0')} Apellido`,
    department: { id: (index % 10) + 1, name: `Departamento ${(index % 10) + 1}` },
  }))

  return {
    data,
    meta: { ...LIVE_BOARD.meta, present_count: count, absent_count: 0, total: count },
  }
}
