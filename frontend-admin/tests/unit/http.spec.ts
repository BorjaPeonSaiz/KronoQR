import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ApiError,
  isApiError,
  request,
  requestBlob,
  requestJson,
  setAuthTokenProvider,
  setUnauthenticatedHandler,
} from '@/shared/api/http'
import { jsonResponse, problemResponse, stubFetch } from './support/harness'

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthTokenProvider(() => null)
  setUnauthenticatedHandler(() => {})
})

describe('cliente HTTP', () => {
  it('lleva el token de la sesion en cada peticion', async () => {
    setAuthTokenProvider(() => 'un-token')
    const spy = stubFetch(() => jsonResponse({ ok: true }))

    await request('/api/v1/auth/me')

    const init = spy.mock.calls[0]?.[1] as RequestInit
    const headers = init.headers as Headers

    expect(headers.get('Authorization')).toBe('Bearer un-token')
  })

  it('no manda el token en las peticiones anonimas, que son solo el acceso', async () => {
    setAuthTokenProvider(() => 'un-token')
    const spy = stubFetch(() => jsonResponse({ ok: true }))

    await request('/api/v1/auth/login', { method: 'POST', body: {}, anonymous: true })

    const init = spy.mock.calls[0]?.[1] as RequestInit
    const headers = init.headers as Headers

    expect(headers.get('Authorization')).toBeNull()
  })

  it('serializa la cadena de consulta y omite lo que no se ha pedido', async () => {
    const spy = stubFetch(() => jsonResponse({ ok: true }))

    await request('/api/v1/employees', { query: { page: 2, status: undefined, site_id: 1 } })

    expect(spy.mock.calls[0]?.[0]).toBe('/api/v1/employees?page=2&site_id=1')
  })

  it('convierte un corte de red en un error con causa propia', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const error = await request('/api/v1/employees').catch((caught: unknown) => caught)

    expect(isApiError(error)).toBe(true)
    expect((error as ApiError).kind).toBe('network')
    expect((error as ApiError).titleKey).toBe('errors.network.title')
    expect((error as ApiError).adviceKey).toBe('errors.network.advice')
  })

  it('distingue las credenciales no validas de una sesion caducada', async () => {
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:invalid-credentials'))

    const error = await request('/api/v1/auth/login', { anonymous: true }).catch(
      (caught: unknown) => caught,
    )

    expect((error as ApiError).kind).toBe('invalidCredentials')
  })

  it('avisa de que la sesion ya no vale, pero no en el propio acceso', async () => {
    const onUnauthenticated = vi.fn()

    setUnauthenticatedHandler(onUnauthenticated)
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:unauthenticated'))

    await request('/api/v1/employees').catch(() => null)
    expect(onUnauthenticated).toHaveBeenCalledTimes(1)

    await request('/api/v1/auth/login', { anonymous: true }).catch(() => null)
    expect(onUnauthenticated).toHaveBeenCalledTimes(1)
  })

  it('recoge los errores por campo de un 422 para pintarlos junto al formulario', async () => {
    stubFetch(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { hired_at: ['La fecha de alta es obligatoria.'], ignored: 'no es una lista' },
      }),
    )

    const error = (await requestJson('/api/v1/employees', { method: 'POST' }).catch(
      (caught: unknown) => caught,
    )) as ApiError

    expect(error.kind).toBe('validation')
    expect(error.fieldErrors['hired_at']).toEqual(['La fecha de alta es obligatoria.'])
    expect(error.fieldErrors['ignored']).toBeUndefined()
  })

  it('lee Retry-After para poder decir cuanto hay que esperar', async () => {
    stubFetch(
      () =>
        new Response(JSON.stringify({ type: 'urn:x', title: 'x', status: 429 }), {
          status: 429,
          headers: { 'Content-Type': 'application/problem+json', 'Retry-After': '7' },
        }),
    )

    const error = (await request('/api/v1/employees').catch(
      (caught: unknown) => caught,
    )) as ApiError

    expect(error.kind).toBe('rateLimited')
    expect(error.retryAfterSeconds).toBe(7)
  })

  it('traduce cada codigo a la causa que decide el texto', async () => {
    const cases: [number, string][] = [
      [403, 'forbidden'],
      [404, 'notFound'],
      [409, 'conflict'],
      [400, 'validation'],
      [503, 'unavailable'],
      [500, 'unexpected'],
    ]

    for (const [status, kind] of cases) {
      stubFetch(() => problemResponse(status, 'urn:kronoqr:problem:x'))

      const error = (await request('/x').catch((caught: unknown) => caught)) as ApiError

      expect(error.kind).toBe(kind)
    }
  })

  it('trata un 204 como ausencia de cuerpo y no como un fallo', async () => {
    stubFetch(() => new Response(null, { status: 204 }))

    await expect(request('/x', { method: 'POST' })).resolves.toBeNull()
  })

  it('descarga un documento con su nombre y su recuento de tarjetas', async () => {
    stubFetch(
      () =>
        new Response('%PDF-1.7', {
          status: 200,
          headers: {
            'Content-Type': 'application/pdf',
            'Content-Disposition': 'attachment; filename="credenciales.pdf"',
            'X-Kronoqr-Printed-Count': '40',
          },
        }),
    )

    const document_ = await requestBlob('/api/v1/credentials/print-batch', 'x.pdf', {
      method: 'POST',
    })

    expect(document_?.filename).toBe('credenciales.pdf')
    expect(document_?.printedCount).toBe(40)
  })

  it('devuelve null cuando el lote no tiene nada pendiente, que no es un error', async () => {
    stubFetch(() => new Response(null, { status: 204 }))

    await expect(
      requestBlob('/api/v1/credentials/print-batch', 'x.pdf', { method: 'POST' }),
    ).resolves.toBeNull()
  })
})
