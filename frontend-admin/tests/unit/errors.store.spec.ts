// Store del historico de errores (RF-PD-15, tarea 5.12).
//
// Lo que se afirma: que los filtros van al servidor y reinician la pagina,
// que resolver retira la fila de la vista de pendientes sin volver a pedir la
// pagina entera, que en la vista de resueltos la sustituye en su sitio, y que
// la antiguedad se calcula con el reloj del servidor y no con el del
// navegador.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useErrorEventsStore } from '@/features/errors/errors.store'
import type { ErrorEvent, ErrorEventCollection } from '@/shared/api/types'
import { createTestPinia, jsonResponse, stubFetch } from './support/harness'

function errorEvent(overrides: Partial<ErrorEvent> = {}): ErrorEvent {
  return {
    id: 87,
    level: 'error',
    source: 'api',
    module: 'attendance',
    code: null,
    message: "SQLSTATE[08006] connection to server at '…' failed",
    exception_class: 'PDOException',
    file: 'app/Modules/Attendance/Infrastructure/ScanRepository.php',
    line: 42,
    context: { route: '/api/v1/scan', method: 'POST' },
    trace_id: '4bf92f3577b34da6a3ce929d0e0e4736',
    device_id: null,
    employee_uuid: null,
    app_version: '2.2.0',
    occurrences: 3,
    first_seen_at: '2026-09-01T00:00:00.000000Z',
    last_seen_at: '2026-09-08T08:00:00.000000Z',
    resolved_at: null,
    resolved_by: null,
    ...overrides,
  }
}

function errorEventCollection(
  data: ErrorEvent[] = [errorEvent()],
  overrides: Partial<ErrorEventCollection['meta']> = {},
): ErrorEventCollection {
  return {
    data,
    meta: {
      page: 1,
      per_page: 25,
      total: data.length,
      total_pages: 1,
      open_errors: data.filter((row) => row.level === 'error' && row.resolved_at === null).length,
      open_critical: data.filter((row) => row.level === 'critical' && row.resolved_at === null)
        .length,
      time_zone: 'Europe/Madrid',
      generated_at: '2026-09-09T08:00:00.000000Z',
      ...overrides,
    },
  }
}

describe('useErrorEventsStore', () => {
  beforeEach(() => {
    createTestPinia()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('pide lo pendiente por omision y guarda la meta', async () => {
    const spy = stubFetch((url) => {
      expect(url).toContain('/api/v1/diagnostics/errors')
      expect(url).toContain('status=open')

      return jsonResponse(errorEventCollection())
    })
    const store = useErrorEventsStore()

    await store.load()

    expect(store.entries).toHaveLength(1)
    expect(store.meta?.time_zone).toBe('Europe/Madrid')
    expect(spy).toHaveBeenCalledTimes(1)
  })

  it('un filtro nuevo vuelve a la primera pagina y se lo pide al servidor', async () => {
    const urls: string[] = []
    stubFetch((url) => {
      urls.push(url)

      return jsonResponse(errorEventCollection())
    })
    const store = useErrorEventsStore()
    await store.load()

    await store.goToPage(3)
    expect(store.filters.page).toBe(3)

    await store.applyFilters({ level: 'critical' })

    expect(store.filters.page).toBe(1)
    expect(urls.at(-1)).toContain('level=critical')
    expect(urls.at(-1)).toContain('page=1')
  })

  it('resolver en la vista de pendientes retira la fila y ajusta los recuentos', async () => {
    let resolveCalls = 0
    stubFetch((url, init) => {
      if (url.includes('/resolve')) {
        resolveCalls += 1
        expect(init?.method).toBe('POST')

        return jsonResponse(
          errorEvent({
            resolved_at: '2026-09-09T09:00:00.000000Z',
            resolved_by: { uuid: 'u-1', name: 'Dirección' },
          }),
        )
      }

      return jsonResponse(errorEventCollection())
    })
    const store = useErrorEventsStore()
    await store.load()
    expect(store.meta?.total).toBe(1)
    expect(store.meta?.open_errors).toBe(1)

    await store.resolve(87)

    expect(store.entries).toHaveLength(0)
    expect(store.meta?.total).toBe(0)
    expect(store.meta?.open_errors).toBe(0)
    expect(resolveCalls).toBe(1)
  })

  it('resolver en la vista de resueltos sustituye la fila en su sitio', async () => {
    stubFetch((url) => {
      if (url.includes('/resolve')) {
        return jsonResponse(
          errorEvent({
            resolved_at: '2026-09-09T09:00:00.000000Z',
            resolved_by: { uuid: 'u-1', name: 'Dirección' },
          }),
        )
      }

      return jsonResponse(errorEventCollection([errorEvent()], { open_errors: 0 }))
    })
    const store = useErrorEventsStore()
    await store.applyFilters({ status: 'all' })

    await store.resolve(87)

    expect(store.entries).toHaveLength(1)
    expect(store.entries[0]?.resolved_at).not.toBeNull()
  })

  it('un fallo al resolver (403 de un acceso de soporte) no toca la fila', async () => {
    stubFetch((url) => {
      if (url.includes('/resolve')) {
        return new Response(
          JSON.stringify({
            type: 'urn:kronoqr:problem:forbidden',
            title: 'Sin permiso',
            status: 403,
          }),
          { status: 403, headers: { 'Content-Type': 'application/problem+json' } },
        )
      }

      return jsonResponse(errorEventCollection())
    })
    const store = useErrorEventsStore()
    await store.load()

    await expect(store.resolve(87)).rejects.toThrow()

    expect(store.entries).toHaveLength(1)
    expect(store.entries[0]?.resolved_at).toBeNull()
  })

  it('el «ahora» de la antiguedad es el del servidor, no el del navegador', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2030-01-01T00:00:00Z'))
    stubFetch(() => jsonResponse(errorEventCollection()))
    const store = useErrorEventsStore()

    await store.load()

    expect(new Date(store.serverNowMs()).toISOString()).toBe('2026-09-09T08:00:00.000Z')

    vi.setSystemTime(new Date('2030-01-01T00:05:00Z'))
    expect(new Date(store.serverNowMs()).toISOString()).toBe('2026-09-09T08:05:00.000Z')
  })
})
