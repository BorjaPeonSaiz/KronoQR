// Movida de `frontend-admin/tests/unit/http.spec.ts` (ADR-036): el cliente base
// es identico para cualquier SPA que lo consuma, asi que su suite vive una sola
// vez, aqui.
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ApiError,
  isApiError,
  request,
  requestBlob,
  requestJson,
  setAuthTokenProvider,
  setLocaleProvider,
  setUnauthenticatedHandler,
} from '../../src/http'
import { jsonResponse, problemResponse, stubFetch } from './support/harness'

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthTokenProvider(() => null)
  setUnauthenticatedHandler(() => {})
  setLocaleProvider(() => null)
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

  it('pide al servidor el idioma activo de la SPA con Accept-Language', async () => {
    // Sin la cabecera, un `422` llegaba en el idioma de la instalacion aunque
    // el panel estuviera en otro. Se lee en cada peticion: el idioma cambia al
    // entrar, cuando pasa a ser el de la persona.
    let locale = 'es'

    setLocaleProvider(() => locale)
    const spy = stubFetch(() => jsonResponse({ ok: true }))

    await request('/api/v1/auth/me')
    locale = 'en'
    await request('/api/v1/auth/me')

    const first = (spy.mock.calls[0]?.[1] as RequestInit).headers as Headers
    const second = (spy.mock.calls[1]?.[1] as RequestInit).headers as Headers

    expect(first.get('Accept-Language')).toBe('es')
    expect(second.get('Accept-Language')).toBe('en')
  })

  it('no manda Accept-Language mientras la SPA no ha dicho su idioma', async () => {
    // Sin proveedor no se inventa nada: el servidor responde en el idioma de la
    // instalacion, que es lo que hacia antes.
    const spy = stubFetch(() => jsonResponse({ ok: true }))

    await request('/api/v1/auth/me')

    const headers = (spy.mock.calls[0]?.[1] as RequestInit).headers as Headers

    expect(headers.get('Accept-Language')).toBeNull()
  })

  it('no manda el token en las peticiones anonimas, que son solo el acceso', async () => {
    setAuthTokenProvider(() => 'un-token')
    const spy = stubFetch(() => jsonResponse({ ok: true }))

    await request('/api/v1/auth/login', { method: 'POST', body: {}, anonymous: true })

    const init = spy.mock.calls[0]?.[1] as RequestInit
    const headers = init.headers as Headers

    expect(headers.get('Authorization')).toBeNull()
  })

  it('manda el token explicito del reto de 2FA en vez del de la sesion', async () => {
    setAuthTokenProvider(() => 'token-de-sesion')
    const spy = stubFetch(() => jsonResponse({ ok: true }))

    await request('/api/v1/auth/2fa/verify', {
      method: 'POST',
      body: { code: '123456' },
      anonymous: true,
      token: 'challenge-token',
    })

    const init = spy.mock.calls[0]?.[1] as RequestInit
    const headers = init.headers as Headers

    expect(headers.get('Authorization')).toBe('Bearer challenge-token')
  })

  it('un codigo equivocado en el reto de 2FA no dispara el cierre de sesion global', async () => {
    // El `token` explicito no es el de la tienda de sesion: un 401 aqui es
    // «codigo invalido», no «la sesion ha caducado», y quien llama decide que
    // hacer sin que el manejador global redirija por su cuenta.
    const onUnauthenticated = vi.fn()

    setUnauthenticatedHandler(onUnauthenticated)
    stubFetch(() => problemResponse(401, 'urn:kronoqr:problem:invalid-credentials'))

    const error = await request('/api/v1/auth/2fa/verify', {
      method: 'POST',
      body: { code: '123456' },
      anonymous: true,
      token: 'challenge-token',
    }).catch((caught: unknown) => caught)

    expect((error as ApiError).kind).toBe('invalidCredentials')
    expect(onUnauthenticated).not.toHaveBeenCalled()
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
      accept: 'application/pdf, application/problem+json',
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

  it('manda un FormData tal cual, sin Content-Type propio y sin serializarlo a JSON', async () => {
    // La importacion de plantilla (multipart/form-data) necesita que el
    // navegador ponga su propio `boundary`: fijar `Content-Type` a mano aqui
    // lo dejaria sin el, y `JSON.stringify` de un `FormData` no produce el
    // fichero que el servidor espera leer en streaming.
    const spy = stubFetch(() => jsonResponse({ ok: true }))
    const form = new FormData()

    form.set('mode', 'validate')

    await request('/api/v1/employees/import', { method: 'POST', body: form })

    const init = spy.mock.calls[0]?.[1] as RequestInit
    const headers = init.headers as Headers

    expect(headers.get('Content-Type')).toBeNull()
    expect(init.body).toBe(form)
  })

  it('acepta PUT, que usa la marca de un paso del asistente como idempotente', async () => {
    const spy = stubFetch(() => jsonResponse({ ok: true }))

    await request('/api/v1/setup/steps/license', { method: 'PUT', body: { state: 'skipped' } })

    expect((spy.mock.calls[0]?.[1] as RequestInit).method).toBe('PUT')
  })
})
