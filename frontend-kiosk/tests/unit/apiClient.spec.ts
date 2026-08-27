import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApiClient } from '@/shared/api/client'
import type { ScanRequest } from '@/shared/api/types'

const REQUEST: ScanRequest = {
  scan_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
  occurred_at: '2026-08-14T05:58:31.000Z',
  qr_payload: 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
  intent: 'auto',
}

const ACCEPTED = {
  scan_id: REQUEST.scan_id,
  action: 'clock_in',
  employee_display_name: 'Lucia G.',
  work_date: '2026-08-14',
  occurred_at: REQUEST.occurred_at,
  recorded_at: '2026-08-14T05:58:31.412Z',
  worked_minutes: 0,
}

/** Firma minima de `fetch` que usa el cliente. Da tipos a `mock.calls`. */
type FetchLike = (url: string, init?: RequestInit) => Promise<Response>

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status })
}

function setOnline(value: boolean): void {
  Object.defineProperty(navigator, 'onLine', { value, configurable: true })
}

afterEach(() => setOnline(true))

describe('cliente HTTP del quiosco', () => {
  it('manda el scan_id como Idempotency-Key (regla dura 8)', async () => {
    const fetchImpl = vi.fn<FetchLike>(async () => jsonResponse(200, ACCEPTED))
    const client = createApiClient({ fetchImpl: fetchImpl as unknown as typeof fetch })

    await client.recordScan(REQUEST)

    const init = fetchImpl.mock.calls[0]?.[1]
    const headers = init?.headers as Record<string, string>
    expect(headers['Idempotency-Key']).toBe(REQUEST.scan_id)
  })

  it('devuelve la accion que decidio el servidor, sin interpretarla', async () => {
    const client = createApiClient({
      fetchImpl: (async () => jsonResponse(200, ACCEPTED)) as unknown as typeof fetch,
    })

    const result = await client.recordScan(REQUEST)

    expect(result).toEqual({ outcome: 'ok', data: ACCEPTED })
  })

  it('distingue el anti-rebote, que tambien es un 200', async () => {
    const client = createApiClient({
      fetchImpl: (async () =>
        jsonResponse(200, {
          scan_id: REQUEST.scan_id,
          action: 'debounced',
          employee_display_name: 'Lucia G.',
          occurred_at: REQUEST.occurred_at,
          recorded_at: '2026-08-14T05:58:51.208Z',
          worked_minutes: 240,
          last_accepted_at: '2026-08-14T05:58:31Z',
        })) as unknown as typeof fetch,
    })

    const result = await client.recordScan(REQUEST)

    expect(result.outcome).toBe('ok')
  })

  it('reconoce el rechazo generico del 422', async () => {
    const client = createApiClient({
      fetchImpl: (async () =>
        jsonResponse(422, {
          type: 'urn:kronoqr:problem:scan-rejected',
          title: 'Escaneo no valido',
          status: 422,
          detail: 'El escaneo no se ha podido registrar.',
          scan_id: REQUEST.scan_id,
        })) as unknown as typeof fetch,
    })

    expect((await client.recordScan(REQUEST)).outcome).toBe('rejected')
  })

  it('NUNCA lanza: un fallo de red es un valor, no una excepcion', async () => {
    const client = createApiClient({
      fetchImpl: (async () => {
        throw new TypeError('Failed to fetch')
      }) as unknown as typeof fetch,
    })

    expect(await client.recordScan(REQUEST)).toEqual({ outcome: 'failed', cause: 'network' })
  })

  it('ni siquiera lo intenta cuando el navegador dice que no hay red', async () => {
    setOnline(false)
    const fetchImpl = vi.fn()
    const client = createApiClient({ fetchImpl: fetchImpl as unknown as typeof fetch })

    expect(await client.recordScan(REQUEST)).toEqual({ outcome: 'failed', cause: 'offline' })
    expect(fetchImpl).not.toHaveBeenCalled()
  })

  it('trata un 200 con cuerpo imposible como fallo, no como fichaje', async () => {
    const client = createApiClient({
      fetchImpl: (async () => jsonResponse(200, { unexpected: true })) as unknown as typeof fetch,
    })

    expect(await client.recordScan(REQUEST)).toMatchObject({
      outcome: 'failed',
      cause: 'malformed',
    })
  })

  it('separa el token caducado del servidor caido', async () => {
    const unauthorized = createApiClient({
      fetchImpl: (async () => jsonResponse(401, {})) as unknown as typeof fetch,
    })
    const broken = createApiClient({
      fetchImpl: (async () => jsonResponse(503, {})) as unknown as typeof fetch,
    })

    expect(await unauthorized.fetchRoster()).toMatchObject({ cause: 'unauthorized' })
    expect(await broken.fetchRoster()).toMatchObject({ cause: 'server' })
  })

  it('aborta la peticion si el servidor no contesta a tiempo', async () => {
    const client = createApiClient({
      timeoutMs: 10,
      fetchImpl: ((_url: string, init?: RequestInit) =>
        new Promise((_resolve, reject) => {
          init?.signal?.addEventListener('abort', () =>
            reject(new DOMException('aborted', 'AbortError')),
          )
        })) as unknown as typeof fetch,
    })

    expect(await client.sendHeartbeat({ app_version: '1.4.2', pending_queue_size: 0 })).toEqual({
      outcome: 'failed',
      cause: 'timeout',
    })
  })

  it('firma con el token del dispositivo cuando lo hay', async () => {
    const fetchImpl = vi.fn<FetchLike>(async () =>
      jsonResponse(200, { generated_at: 'x', entries: [] }),
    )
    const client = createApiClient({
      deviceToken: () => 'tok-123',
      fetchImpl: fetchImpl as unknown as typeof fetch,
    })

    await client.fetchRoster()

    const init = fetchImpl.mock.calls[0]?.[1]
    expect((init?.headers as Record<string, string>)['Authorization']).toBe('Bearer tok-123')
  })
})
