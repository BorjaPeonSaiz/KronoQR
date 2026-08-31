// Presencia en vivo (RF-PA-01, RF-PA-02, RNF-D-03, RNF-P-04).
//
// Reverb se simula con `routeWebSocket`: el doble habla el protocolo Pusher
// justo lo suficiente (saludo, confirmacion de suscripcion, pong) para que el
// cliente del panel se crea en vivo, y deja empujar un `presence.updated` a
// todas las pestañas conectadas. Lo que se prueba es lo que ve la persona: que
// la lista cambia sin recargar, y que cuando el canal no esta, la pantalla lo
// dice y sigue actualizandose por sondeo.
import type { BrowserContext, Page, WebSocketRoute } from '@playwright/test'
import { expect, test } from '@playwright/test'
import type { LivePresenceBoard, LivePresenceEntry } from '@/shared/api/types'
import { LIVE_BOARD, LIVE_ENTRY, logIn, stubManagementApi } from './support/admin'

interface FakeReverb {
  readonly sockets: WebSocketRoute[]
  /** Empuja un evento a todas las pestañas suscritas. */
  push(entry: LivePresenceEntry, occurredAt: string): void
}

/** Un Reverb de mentira que acepta cualquier suscripcion firmada. */
async function fakeReverb(
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

async function openLive(page: Page, board?: LivePresenceBoard) {
  const api = await stubManagementApi(page, board === undefined ? {} : { liveBoard: board })
  await logIn(page)
  await page.goto('/live')
  await expect(page.getByRole('heading', { level: 1, name: 'Presencia en vivo' })).toBeVisible()

  return api
}

const NEWCOMER: LivePresenceEntry = {
  employee_uuid: '0199f0c2-3333-7c3e-9b21-4d5e6f7a8b92',
  full_name: 'Ana Pérez Soler',
  department: { id: 4, name: 'Pisos' },
  status: 'present',
  shift_entry_uuid: '0199f2c1-3333-7b40-9c50-6d7e8f9a0b13',
  clocked_in_at: '2026-03-14T09:15:00.000000Z',
  origin: 'pin_kiosk',
  device: { uuid: '0199f0d3-3c71-7e52-9a13-6f7a8b9c0d12', name: 'Entrada de personal' },
}

test(
  'dos pestañas abiertas: alguien ficha y las dos listas cambian sin recargar',
  { tag: ['@RF-PA-01'] },
  async ({ context }) => {
    const reverb = await fakeReverb(context)
    const first = await context.newPage()
    const second = await context.newPage()
    const firstApi = await openLive(first)
    const secondApi = await openLive(second)

    for (const page of [first, second]) {
      await expect(page.getByTestId('transport')).toHaveAttribute('data-degraded', 'false')
      await expect(page.getByTestId('transport')).toHaveText('En tiempo real')
      await expect(page.getByTestId('present-count')).toHaveText(
        String(LIVE_BOARD.meta.present_count),
      )
    }
    expect(reverb.sockets).toHaveLength(2)

    const liveRequestsBefore = [firstApi, secondApi].map(
      (api) => api.requests.filter((request) => request.path === '/api/v1/attendance/live').length,
    )

    reverb.push(NEWCOMER, '2026-03-14T09:15:00.000000Z')

    for (const page of [first, second]) {
      await expect(
        page.getByTestId('presence-entry').filter({ hasText: 'Ana Pérez Soler' }),
      ).toBeVisible()
      await expect(page.getByTestId('present-count')).toHaveText(
        String(LIVE_BOARD.meta.present_count + 1),
      )
    }

    // Y sale: la fila desaparece de la lista de presentes en las dos pestañas.
    reverb.push(
      {
        ...NEWCOMER,
        status: 'absent',
        shift_entry_uuid: null,
        clocked_in_at: null,
        origin: null,
        device: null,
      },
      '2026-03-14T09:40:00.000000Z',
    )

    for (const page of [first, second]) {
      await expect(
        page.getByTestId('presence-entry').filter({ hasText: 'Ana Pérez Soler' }),
      ).toHaveCount(0)
      await expect(page.getByTestId('present-count')).toHaveText(
        String(LIVE_BOARD.meta.present_count),
      )
    }

    // Nada de esto volvio a consultar la API.
    const liveRequestsAfter = [firstApi, secondApi].map(
      (api) => api.requests.filter((request) => request.path === '/api/v1/attendance/live').length,
    )
    expect(liveRequestsAfter).toEqual(liveRequestsBefore)
  },
)

// RNF-D-03, la mitad del navegador: «si cae el WebSocket, el panel hace fallback
// a sondeo». El socket se cierra con un 1006 —caida, no rechazo—, que es lo que
// pasa cuando Reverb se para o el proxy corta, y se comprueba que el panel PIDE
// la foto por su cuenta varias veces y que lo ANUNCIA: sin aviso, quien mira
// cree que no ha entrado nadie en quince segundos.
//
// El intervalo se baja a 1 s desde la respuesta del servidor para no tener una
// prueba de 45 s. No es una trampa: lo que se comprueba aqui es que el panel
// obedece al intervalo que el servidor le dice, y que ese intervalo son 15 s lo
// afirma `LivePresenceTest` en el backend, donde vive la cifra, mas la prueba de
// aqui abajo que lee los 15 s en pantalla.
test(
  'con Reverb caido, la vista lo dice y sigue actualizandose por sondeo',
  { tag: ['@RF-PA-01', '@RNF-D-03'] },
  async ({ context, page }) => {
    await fakeReverb(context, 'down')
    const board: LivePresenceBoard = {
      ...LIVE_BOARD,
      meta: {
        ...LIVE_BOARD.meta,
        realtime: { ...LIVE_BOARD.meta.realtime, poll_interval_seconds: 1 },
      },
    }
    const api = await openLive(page, board)

    const transport = page.getByTestId('transport')
    await expect(transport).toHaveAttribute('data-degraded', 'true')
    await expect(transport).toHaveText('Tiempo real no disponible: la lista se actualiza cada 1 s')
    await expect(transport).toHaveAttribute('role', 'status')

    await expect
      .poll(
        () => api.requests.filter((request) => request.path === '/api/v1/attendance/live').length,
        {
          timeout: 8_000,
        },
      )
      .toBeGreaterThanOrEqual(3)
  },
)

// La cifra de RNF-D-03 leida donde la lee una persona: «la lista se actualiza
// cada 15 s», con el intervalo por omision del servidor y sin retocarlo.
test(
  'con el tiempo real desactivado en la instalacion, lo anuncia como tal',
  { tag: ['@RF-PA-01', '@RNF-D-03'] },
  async ({ page }) => {
    const board: LivePresenceBoard = {
      ...LIVE_BOARD,
      meta: {
        ...LIVE_BOARD.meta,
        realtime: { ...LIVE_BOARD.meta.realtime, enabled: false, key: null },
      },
    }
    await openLive(page, board)

    await expect(page.getByTestId('transport')).toHaveText(
      'Tiempo real desactivado en esta instalación: la lista se actualiza cada 15 s',
    )
    await expect(page.getByTestId('transport')).toHaveAttribute('data-degraded', 'true')
  },
)

test(
  'los filtros van al servidor, y la hora de entrada se enseña en la zona del centro',
  { tag: ['@RF-PA-02'] },
  async ({ context, page }) => {
    await fakeReverb(context)
    const api = await openLive(page)

    // 05:00 UTC son las 06:00 en Europe/Madrid; el navegador esta en Atlantic/Canary (05:00).
    const row = page.getByTestId('presence-entry').filter({ hasText: LIVE_ENTRY.full_name })
    await expect(row.getByTestId('entry-since')).toHaveText('06:00')
    await expect(page.getByText('Europe/Madrid').first()).toBeVisible()

    await page.getByLabel('Situación').selectOption('absent')
    await expect
      .poll(() =>
        api.requests.some(
          (request) =>
            request.path === '/api/v1/attendance/live' && request.query.includes('status=absent'),
        ),
      )
      .toBe(true)

    await page.getByLabel('Departamento').selectOption('3')
    await expect
      .poll(() => api.requests.some((request) => request.query.includes('department_id=3')))
      .toBe(true)

    await page.getByLabel('Buscar por nombre').fill('amrani')
    await expect
      .poll(() => api.requests.some((request) => request.query.includes('q=amrani')))
      .toBe(true)
  },
)

test(
  'con 500 personas la pantalla pinta rapido y no mete las 500 filas en el DOM',
  { tag: ['@RF-PA-01', '@RNF-P-04'] },
  async ({ context, page }) => {
    await fakeReverb(context)
    const data: LivePresenceEntry[] = Array.from({ length: 500 }, (_, index) => ({
      ...LIVE_ENTRY,
      employee_uuid: `0199f0c2-1f4a-7c3e-9b21-${String(index).padStart(12, '0')}`,
      full_name: `Persona ${String(index).padStart(3, '0')} Apellido`,
      department: { id: (index % 10) + 1, name: `Departamento ${(index % 10) + 1}` },
    }))
    const board: LivePresenceBoard = {
      data,
      meta: { ...LIVE_BOARD.meta, present_count: 500, absent_count: 0, total: 500 },
    }
    await openLive(page, board)

    await expect(page.getByTestId('present-count')).toHaveText('500')
    const painted = await page.getByTestId('presence-entry').count()
    expect(painted).toBeGreaterThan(0)
    expect(painted).toBeLessThan(120)

    // LCP de la navegacion a /live, medido en el propio navegador (RNF-P-04: < 1,5 s).
    const lcp = await page.evaluate(
      () =>
        new Promise<number>((resolve) => {
          let last = 0
          const observer = new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
              last = entry.startTime
            }
          })
          observer.observe({ type: 'largest-contentful-paint', buffered: true })
          setTimeout(() => {
            observer.disconnect()
            resolve(last)
          }, 500)
        }),
    )
    expect(lcp).toBeGreaterThan(0)
    expect(lcp).toBeLessThan(1_500)
  },
)
