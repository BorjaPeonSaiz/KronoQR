import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  generateDiagnosticsBundle,
  grantSupportAccess,
  listSupportGrants,
  revokeSupportAccess,
} from '@/features/support/support.api'
import { jsonResponse, stubFetch } from './support/harness'

// `support.api.ts` (RF-PD-09, RF-PD-11, ADR-020): sin cliente generado propio,
// lo que se comprueba aqui es que cada funcion pide la ruta y el metodo que
// dice el contrato, y que el paquete de diagnostico se descarga con el nombre
// que trae `Content-Disposition` -nunca uno inventado en el cliente.

function bundleResponse(filename: string): Response {
  return new Response(JSON.stringify({ manifest: { schema_version: 1 } }), {
    status: 200,
    headers: {
      'Content-Type': 'application/json',
      'Content-Disposition': `attachment; filename=${filename}`,
    },
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('generateDiagnosticsBundle', () => {
  it('lee el nombre del fichero de Content-Disposition, no lo inventa', async () => {
    stubFetch(() => bundleResponse('kronoqr-diagnostics-2.2.0-20260908T101500Z.json'))

    const document_ = await generateDiagnosticsBundle({
      include_personal_data: false,
      period_days: 7,
    })

    expect(document_.filename).toBe('kronoqr-diagnostics-2.2.0-20260908T101500Z.json')
  })

  it('manda include_personal_data y period_days tal cual se piden', async () => {
    const spy = stubFetch(() => bundleResponse('kronoqr-diagnostics-2.2.0-20260908T101500Z.json'))

    await generateDiagnosticsBundle({ include_personal_data: true, period_days: 14 })

    const [url, init] = spy.mock.calls[0] as [string, RequestInit]

    expect(url).toBe('/api/v1/diagnostics/bundle')
    expect(init.method).toBe('POST')
    expect(JSON.parse(String(init.body))).toEqual({
      include_personal_data: true,
      period_days: 14,
    })
  })

  it('sin argumentos, pide el paquete anonimizado por defecto', async () => {
    const spy = stubFetch(() => bundleResponse('kronoqr-diagnostics-2.2.0-20260908T101500Z.json'))

    await generateDiagnosticsBundle()

    const [, init] = spy.mock.calls[0] as [string, RequestInit]

    expect(JSON.parse(String(init.body))).toEqual({
      include_personal_data: false,
      period_days: 7,
    })
  })
})

describe('accesos de soporte', () => {
  it('listSupportGrants pide GET /api/v1/support/grants', async () => {
    const spy = stubFetch(() => jsonResponse({ data: [] }))

    await listSupportGrants()

    const [url, init] = spy.mock.calls[0] as [string, RequestInit | undefined]

    expect(url).toBe('/api/v1/support/grants')
    expect(init?.method ?? 'GET').toBe('GET')
  })

  it('grantSupportAccess manda el motivo, el alcance y las horas por POST', async () => {
    const spy = stubFetch(() =>
      jsonResponse({
        data: {
          uuid: '0199f000-0000-7000-8000-000000000001',
          status: 'active',
          scope: 'diagnostics',
          reason: 'Incidencia #123',
          granted_by: { uuid: '0199f000-0000-7000-8000-000000000002', name: 'Dirección' },
          granted_at: '2026-09-08T09:00:00.000000Z',
          expires_at: '2026-09-09T09:00:00.000000Z',
          revoked_at: null,
          accessed_at: null,
          token: '23|token-de-prueba',
        },
      }),
    )

    const issued = await grantSupportAccess({
      reason: 'Incidencia #123',
      scope: 'diagnostics',
      hours: 24,
    })

    const [url, init] = spy.mock.calls[0] as [string, RequestInit]

    expect(url).toBe('/api/v1/support/grants')
    expect(init.method).toBe('POST')
    expect(JSON.parse(String(init.body))).toEqual({
      reason: 'Incidencia #123',
      scope: 'diagnostics',
      hours: 24,
    })
    expect(issued.data.token).toBe('23|token-de-prueba')
  })

  it('revokeSupportAccess pide DELETE sobre la concesion', async () => {
    const spy = stubFetch(() => new Response(null, { status: 204 }))

    await revokeSupportAccess('0199f000-0000-7000-8000-000000000001')

    const [url, init] = spy.mock.calls[0] as [string, RequestInit]

    expect(url).toBe('/api/v1/support/grants/0199f000-0000-7000-8000-000000000001')
    expect(init.method).toBe('DELETE')
  })
})
