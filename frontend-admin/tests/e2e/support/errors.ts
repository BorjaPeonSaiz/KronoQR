// Dobles del historico de errores y del canal de errores de cliente (RF-PD-15,
// tarea 5.12) para el E2E del panel.
//
// Fichero NUEVO, deliberadamente separado de `admin.ts` (propiedad de ficheros
// de la tarea 5.12: este agente no toca `admin.ts`). Playwright invoca los
// manejadores de ruta en orden INVERSO de registro: si esta funcion se llama
// DESPUES de `stubManagementApi`, sus rutas (mas especificas,
// `/diagnostics/errors*` y `/client-errors`) interceptan la peticion antes de
// que el catch-all de `admin.ts` la vea -que respondería 404, «sin doble para
// esta ruta»-, sin que haga falta modificar ese fichero.
import type { Page, Route } from '@playwright/test'
import type { ErrorEvent, ErrorEventCollection } from '@/shared/api/types'

export const ERROR_EVENT_ID = 87

export const ADMIN_USER_FOR_ERRORS = {
  uuid: '0199f0aa-4444-7000-8000-0123456789ae',
  name: 'Dirección del hotel',
}

/** El ejemplo del runbook (§12, ficha 5.12): la tablet de recepción sin cámara. */
export const KIOSK_CRITICAL_ERROR: ErrorEvent = {
  id: ERROR_EVENT_ID,
  level: 'critical',
  source: 'kiosk',
  module: null,
  code: 'kiosk.camera.stream_lost',
  message: 'La cámara ha dejado de responder',
  exception_class: null,
  file: null,
  line: null,
  // `reason` es una de las dieciseis claves reales de
  // `ErrorContextAllowlist` (backend): el mismo patron que usa el quiosco de
  // verdad para `kiosk.camera.*` (`useCamera.ts`, `{ reason: 'no_media_devices' }`).
  context: { reason: 'stream_ended' },
  trace_id: '4bf92f3577b34da6a3ce929d0e0e4736',
  device_id: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81',
  employee_uuid: null,
  app_version: '1.4.2',
  occurrences: 12,
  first_seen_at: '2026-09-01T00:00:00.000000Z',
  last_seen_at: '2026-09-09T07:30:00.000000Z',
  resolved_at: null,
  resolved_by: null,
}

function collection(data: ErrorEvent[]): ErrorEventCollection {
  return {
    data,
    meta: {
      page: 1,
      per_page: 25,
      total: data.length,
      total_pages: Math.max(Math.ceil(data.length / 25), 1),
      open_errors: data.filter((row) => row.level === 'error' && row.resolved_at === null).length,
      open_critical: data.filter((row) => row.level === 'critical' && row.resolved_at === null)
        .length,
      time_zone: 'Europe/Madrid',
      generated_at: '2026-09-09T08:00:00.000000Z',
    },
  }
}

async function problem(route: Route, status: number, title: string): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/problem+json',
    body: JSON.stringify({ type: 'about:blank', title, status }),
  })
}

export interface ErrorEventsApiOptions {
  /** El histórico de partida. Por omisión, un único grupo: la tablet sin cámara. */
  readonly entries?: ErrorEvent[]
}

/**
 * Intercepta `GET /api/v1/diagnostics/errors` y
 * `POST /api/v1/diagnostics/errors/{id}/resolve`. Mutable: resolver deja el
 * grupo con `resolved_at` puesto, exactamente como lo haría el servidor, para
 * que el filtro `status=open` posterior ya no lo enseñe.
 */
export async function stubErrorEventsApi(
  page: Page,
  options: ErrorEventsApiOptions = {},
): Promise<void> {
  const entries: ErrorEvent[] = (options.entries ?? [KIOSK_CRITICAL_ERROR]).map((entry) => ({
    ...entry,
  }))

  await page.route(
    (url) => url.pathname.startsWith('/api/v1/diagnostics/errors'),
    async (route: Route) => {
      const request = route.request()
      const method = request.method()
      const url = new URL(request.url())
      const resolveMatch = /^\/api\/v1\/diagnostics\/errors\/(\d+)\/resolve$/.exec(url.pathname)

      if (method === 'POST' && resolveMatch !== null) {
        const id = Number(resolveMatch[1])
        const target = entries.find((entry) => entry.id === id)

        if (target === undefined) {
          await problem(route, 404, 'Grupo de errores no encontrado')

          return
        }

        target.resolved_at = '2026-09-09T09:00:00.000000Z'
        target.resolved_by = { ...ADMIN_USER_FOR_ERRORS }

        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(target),
        })

        return
      }

      if (method === 'GET' && url.pathname === '/api/v1/diagnostics/errors') {
        const status = url.searchParams.get('status') ?? 'open'
        const source = url.searchParams.get('source')
        const level = url.searchParams.get('level')
        const filtered = entries.filter((entry) => {
          if (status === 'open' && entry.resolved_at !== null) {
            return false
          }

          if (status === 'resolved' && entry.resolved_at === null) {
            return false
          }

          if (source !== null && entry.source !== source) {
            return false
          }

          if (level !== null && entry.level !== level) {
            return false
          }

          return true
        })

        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(collection(filtered)),
        })

        return
      }

      await problem(route, 404, 'Sin doble para esta ruta en el E2E')
    },
  )
}

export interface ClientErrorsEndpointStub {
  /** Cuantas peticiones ha recibido `POST /api/v1/client-errors`. */
  readonly count: () => number
}

/**
 * Intercepta `POST /api/v1/client-errors` (el canal del panel y del portal,
 * decision 7 de la ficha 5.12) y responde SIEMPRE `500`: sirve para comprobar
 * que un fallo al reportar un error de cliente no bloquea el panel y que el
 * transporte no reintenta en bucle (`clientErrorTransport.ts`).
 */
export function stubClientErrorsEndpoint(page: Page): ClientErrorsEndpointStub {
  let count = 0

  void page.route('**/api/v1/client-errors', async (route: Route) => {
    count += 1
    await route.fulfill({
      status: 500,
      contentType: 'application/problem+json',
      body: JSON.stringify({
        type: 'about:blank',
        title: 'Error interno',
        status: 500,
      }),
    })
  })

  return { count: () => count }
}
