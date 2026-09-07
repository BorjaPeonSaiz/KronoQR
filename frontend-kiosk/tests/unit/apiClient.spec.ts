import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApiClient } from '@/shared/api/client'
import type { PairingClaimRequest, PairingRequestBody, ScanRequest } from '@/shared/api/types'

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

describe('emparejamiento (RF-PD-06)', () => {
  const PAIR_BODY: PairingRequestBody = { app_version: '1.4.2' }
  const CLAIM_BODY: PairingClaimRequest = {
    pairing_id: '0199f3c1-4a2b-7e55-9c10-8d7e6f5a4b32',
    pairing_secret: '9x2Kd4pQ7vLmN8tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ',
  }

  it('pide un codigo sin mandar Authorization aunque haya un token viejo', async () => {
    const fetchImpl = vi.fn<FetchLike>(async () =>
      jsonResponse(201, {
        pairing_id: CLAIM_BODY.pairing_id,
        pairing_secret: CLAIM_BODY.pairing_secret,
        code: '483921',
        expires_at: '2026-09-07T10:12:00Z',
        poll_interval_seconds: 5,
      }),
    )
    // A PROPOSITO se le da un `deviceToken` que SI devuelve algo: el punto de
    // esta prueba es que la ruta publica lo ignora, no que nadie se lo pase.
    // Es el escenario real de una tablet recien revocada que vuelve a `/pair`
    // con el token viejo todavia en `localStorage` (`clearDeviceToken` puede
    // no haberse podido escribir, o simplemente no haber corrido todavia).
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: () => 'token-viejo-de-antes-de-desvincular',
    })

    const result = await client.requestPairing(PAIR_BODY)

    expect(result).toEqual({
      outcome: 'ok',
      data: {
        pairing_id: CLAIM_BODY.pairing_id,
        pairing_secret: CLAIM_BODY.pairing_secret,
        code: '483921',
        expires_at: '2026-09-07T10:12:00Z',
        poll_interval_seconds: 5,
      },
    })
    const init = fetchImpl.mock.calls[0]?.[1]
    expect((init?.headers as Record<string, string>)['Authorization']).toBeUndefined()
  })

  it('sondea sin mandar Authorization aunque haya un token viejo', async () => {
    const fetchImpl = vi.fn<FetchLike>(async () => jsonResponse(200, { status: 'pending' }))
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: () => 'token-viejo-de-antes-de-desvincular',
    })

    await client.claimPairing(CLAIM_BODY)

    const init = fetchImpl.mock.calls[0]?.[1]
    expect((init?.headers as Record<string, string>)['Authorization']).toBeUndefined()
  })

  it('reconoce «pending» y «paired» en el mismo 200, discriminados por status', async () => {
    const pendingClient = createApiClient({
      fetchImpl: (async () => jsonResponse(200, { status: 'pending' })) as unknown as typeof fetch,
    })
    const pairedClient = createApiClient({
      fetchImpl: (async () =>
        jsonResponse(200, {
          status: 'paired',
          device: { uuid: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81', name: 'Recepcion' },
          token: {
            value: '92|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ',
            expires_at: '2026-12-06T10:07:00Z',
          },
        })) as unknown as typeof fetch,
    })

    expect(await pendingClient.claimPairing(CLAIM_BODY)).toEqual({
      outcome: 'ok',
      data: { status: 'pending' },
    })
    const paired = await pairedClient.claimPairing(CLAIM_BODY)
    expect(paired.outcome).toBe('ok')
    expect(paired.outcome === 'ok' && paired.data.status).toBe('paired')
  })

  it('el rechazo del claim es su propia forma, no la de un escaneo', async () => {
    const client = createApiClient({
      fetchImpl: (async () =>
        jsonResponse(422, {
          type: 'urn:kronoqr:problem:pairing-rejected',
          title: 'Emparejamiento no valido',
          status: 422,
          detail: 'La solicitud de emparejamiento no se ha podido completar.',
        })) as unknown as typeof fetch,
    })

    const result = await client.claimPairing(CLAIM_BODY)

    expect(result.outcome).toBe('rejected')
    expect(result.outcome === 'rejected' && result.problem.type).toBe(
      'urn:kronoqr:problem:pairing-rejected',
    )
  })

  it('un 429 al pedir codigo es un fallo de transporte, no un rechazo', async () => {
    const client = createApiClient({
      fetchImpl: (async () => jsonResponse(429, {})) as unknown as typeof fetch,
    })

    expect(await client.requestPairing(PAIR_BODY)).toEqual({
      outcome: 'failed',
      cause: 'throttled',
      httpStatus: 429,
    })
  })

  it('un 503 (ServiceUnavailable: limite de solicitudes vivas o codigos agotados) es un fallo de transporte', async () => {
    const client = createApiClient({
      fetchImpl: (async () =>
        jsonResponse(503, {
          type: 'urn:kronoqr:problem:service-unavailable',
          title: 'Servicio no disponible',
          status: 503,
        })) as unknown as typeof fetch,
    })

    expect(await client.requestPairing(PAIR_BODY)).toEqual({
      outcome: 'failed',
      cause: 'server',
      httpStatus: 503,
    })
  })
})
