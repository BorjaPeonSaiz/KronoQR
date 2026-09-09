// `errors.api.ts` (RF-PD-15, tarea 5.12): lo que se comprueba es que cada
// funcion pide la ruta, el metodo y los filtros que dice el contrato -en
// snake_case, nunca los nombres en camelCase que usa el panel-.
import { afterEach, describe, expect, it, vi } from 'vitest'
import { listErrorEvents, resolveErrorEvent } from '@/features/errors/errors.api'
import { jsonResponse, stubFetch } from './support/harness'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('listErrorEvents', () => {
  it('sin filtros, pide la ruta sin cadena de consulta', async () => {
    const spy = stubFetch(() =>
      jsonResponse({
        data: [],
        meta: {
          page: 1,
          per_page: 25,
          total: 0,
          total_pages: 1,
          open_errors: 0,
          open_critical: 0,
          time_zone: 'Europe/Madrid',
          generated_at: '2026-09-09T08:00:00.000000Z',
        },
      }),
    )

    await listErrorEvents()

    const [url] = spy.mock.calls[0] as [string]

    expect(url).toBe('/api/v1/diagnostics/errors')
  })

  it('traduce los filtros de camelCase a los nombres del contrato', async () => {
    const spy = stubFetch(() =>
      jsonResponse({
        data: [],
        meta: {
          page: 2,
          per_page: 10,
          total: 0,
          total_pages: 1,
          open_errors: 0,
          open_critical: 0,
          time_zone: 'Europe/Madrid',
          generated_at: '2026-09-09T08:00:00.000000Z',
        },
      }),
    )

    await listErrorEvents({
      source: 'kiosk',
      level: 'critical',
      status: 'resolved',
      from: '2026-09-01T00:00:00.000Z',
      to: '2026-09-08T23:59:59.999Z',
      page: 2,
      perPage: 10,
    })

    const [url] = spy.mock.calls[0] as [string]

    expect(url).toContain('source=kiosk')
    expect(url).toContain('level=critical')
    expect(url).toContain('status=resolved')
    expect(url).toContain('from=2026-09-01T00%3A00%3A00.000Z')
    expect(url).toContain('to=2026-09-08T23%3A59%3A59.999Z')
    expect(url).toContain('page=2')
    expect(url).toContain('per_page=10')
  })
})

describe('resolveErrorEvent', () => {
  it('pide POST sobre el grupo, sin cuerpo', async () => {
    const spy = stubFetch(() =>
      jsonResponse({
        id: 87,
        level: 'error',
        source: 'api',
        module: null,
        code: null,
        message: 'boom',
        exception_class: null,
        file: null,
        line: null,
        context: {},
        trace_id: null,
        device_id: null,
        employee_uuid: null,
        app_version: '2.2.0',
        occurrences: 3,
        first_seen_at: '2026-09-01T00:00:00.000000Z',
        last_seen_at: '2026-09-08T00:00:00.000000Z',
        resolved_at: '2026-09-09T08:00:00.000000Z',
        resolved_by: { uuid: 'u-1', name: 'Dirección del hotel' },
      }),
    )

    const resolved = await resolveErrorEvent(87)

    const [url, init] = spy.mock.calls[0] as [string, RequestInit]

    expect(url).toBe('/api/v1/diagnostics/errors/87/resolve')
    expect(init.method).toBe('POST')
    expect(init.body).toBeUndefined()
    expect(resolved.resolved_at).not.toBeNull()
  })
})
