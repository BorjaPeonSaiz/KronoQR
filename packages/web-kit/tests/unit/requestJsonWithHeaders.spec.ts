// Peticion JSON que devuelve tambien las cabeceras (hora del servidor, V21-3).
import { afterEach, describe, expect, it, vi } from 'vitest'
import { isApiError, requestJsonWithHeaders } from '../../src/http'
import { jsonResponse, problemResponse, stubFetch } from './support/harness'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('requestJsonWithHeaders', () => {
  it('devuelve el cuerpo y las cabeceras de la respuesta', async () => {
    stubFetch(
      () =>
        new Response(JSON.stringify({ ok: true }), {
          status: 200,
          headers: { 'Content-Type': 'application/json', Date: 'Fri, 20 Mar 2026 10:00:00 GMT' },
        }),
    )

    const { body, headers } = await requestJsonWithHeaders<{ ok: boolean }>('/api/v1/x')

    expect(body).toEqual({ ok: true })
    expect(headers.get('Date')).toBe('Fri, 20 Mar 2026 10:00:00 GMT')
  })

  it('un error de la API sigue siendo un ApiError', async () => {
    stubFetch(() => problemResponse(401, 'urn:x'))

    await expect(requestJsonWithHeaders('/api/v1/x', { anonymous: true })).rejects.toSatisfy(
      isApiError,
    )
  })

  it('un 204 no es una respuesta con cuerpo', async () => {
    stubFetch(() => new Response(null, { status: 204 }))

    await expect(requestJsonWithHeaders('/api/v1/x')).rejects.toMatchObject({ status: 204 })
  })

  it('sigue valiendo jsonResponse del harness', async () => {
    stubFetch(() => jsonResponse({ a: 1 }))

    expect((await requestJsonWithHeaders<{ a: number }>('/api/v1/x')).body.a).toBe(1)
  })
})

describe('readBody', () => {
  it('lee el cuerpo con la funcion del llamante en vez de response.json()', async () => {
    stubFetch(() => jsonResponse({ a: 1 }))
    const readBody = vi.fn(() => Promise.resolve({ leido: 'aparte' }))

    const { body } = await requestJsonWithHeaders<{ leido: string }>('/api/v1/x', { readBody })

    expect(body).toEqual({ leido: 'aparte' })
    expect(readBody).toHaveBeenCalledOnce()
  })

  it('un error de la API no pasa por readBody', async () => {
    stubFetch(() => problemResponse(403, 'urn:x'))
    const readBody = vi.fn(() => Promise.resolve({}))

    await expect(requestJsonWithHeaders('/api/v1/x', { readBody })).rejects.toSatisfy(isApiError)
    expect(readBody).not.toHaveBeenCalled()
  })
})
