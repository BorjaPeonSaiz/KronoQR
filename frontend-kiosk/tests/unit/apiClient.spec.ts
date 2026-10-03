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

  it('manda un traceparent (W3C Trace Context) nuevo en cada peticion, incluso con el mismo scan_id', async () => {
    // Regla dura 8 (el `scan_id` es el mismo en todo reenvio) y tarea 3.1,
    // decision 7: la traceparent es del INTENTO, no del fichaje. Dos envios
    // del mismo `scan_id` -el reintento tipico de la cola offline- llevan la
    // misma `Idempotency-Key` y dos `traceparent` distintas.
    const fetchImpl = vi.fn<FetchLike>(async () => jsonResponse(200, ACCEPTED))
    const client = createApiClient({ fetchImpl: fetchImpl as unknown as typeof fetch })

    await client.recordScan(REQUEST)
    await client.recordScan(REQUEST)

    const firstHeaders = fetchImpl.mock.calls[0]?.[1]?.headers as Record<string, string>
    const secondHeaders = fetchImpl.mock.calls[1]?.[1]?.headers as Record<string, string>
    const traceparentPattern = /^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/

    expect(firstHeaders['traceparent']).toMatch(traceparentPattern)
    expect(secondHeaders['traceparent']).toMatch(traceparentPattern)
    expect(firstHeaders['traceparent']).not.toBe(secondHeaders['traceparent'])
    expect(firstHeaders['Idempotency-Key']).toBe(secondHeaders['Idempotency-Key'])
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

describe('marca de la instalacion (RF-PD-08)', () => {
  const PRODUCT_BODY = {
    application_name: 'KronoQR',
    accent_color: null,
    logo_url: null,
    locales: { default: 'es', available: ['es', 'en'] },
  }

  it('pide la marca sin Authorization, aunque haya un token de dispositivo', async () => {
    const fetchImpl = vi.fn<FetchLike>(async () => jsonResponse(200, PRODUCT_BODY))
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: () => 'tok-123',
    })

    const result = await client.fetchBranding()

    expect(result).toEqual({ outcome: 'ok', data: PRODUCT_BODY })
    const init = fetchImpl.mock.calls[0]?.[1]
    expect((init?.headers as Record<string, string>)['Authorization']).toBeUndefined()
  })

  it('devuelve la marca de un cliente con acento y logotipo', async () => {
    const hotel = {
      application_name: 'Hotel Marina',
      accent_color: '#0f5c8c',
      logo_url: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
      locales: { default: 'es', available: ['es'] },
    }
    const client = createApiClient({
      fetchImpl: (async () => jsonResponse(200, hotel)) as unknown as typeof fetch,
    })

    expect(await client.fetchBranding()).toEqual({ outcome: 'ok', data: hotel })
  })

  it('trata un cuerpo que no encaja con el contrato como fallo, nunca como marca vacia', async () => {
    const client = createApiClient({
      fetchImpl: (async () => jsonResponse(200, { nombre: 'algo' })) as unknown as typeof fetch,
    })

    expect(await client.fetchBranding()).toEqual({
      outcome: 'failed',
      cause: 'malformed',
      httpStatus: 200,
    })
  })

  it('un 429 (limitada por IP) es un fallo de transporte, no una marca', async () => {
    const client = createApiClient({
      fetchImpl: (async () => jsonResponse(429, {})) as unknown as typeof fetch,
    })

    expect(await client.fetchBranding()).toEqual({
      outcome: 'failed',
      cause: 'throttled',
      httpStatus: 429,
    })
  })

  it('un fallo de red no lanza: se ignora en silencio (regla dura 19)', async () => {
    const client = createApiClient({
      fetchImpl: (async () => {
        throw new TypeError('Failed to fetch')
      }) as unknown as typeof fetch,
    })

    expect(await client.fetchBranding()).toEqual({ outcome: 'failed', cause: 'network' })
  })
})

describe('fichajes que el servidor declara invalidos (PIN-08)', () => {
  const problem = { type: 'urn:kronoqr:problem:invalid-request', status: 400, errors: { x: ['y'] } }

  it('un 400 con cuerpo JSON es `invalid` en /scan y en /scan/pin', async () => {
    const client = createApiClient({
      fetchImpl: (async () => jsonResponse(400, problem)) as unknown as typeof fetch,
    })

    expect(await client.recordScan(REQUEST)).toEqual({
      outcome: 'failed',
      cause: 'invalid',
      httpStatus: 400,
      // RN-22: el `type` de ESTE producto viaja en el aviso de descartado.
      problemType: 'urn:kronoqr:problem:invalid-request',
    })
    expect(
      await client.recordPinScan({
        scan_id: REQUEST.scan_id,
        occurred_at: REQUEST.occurred_at,
        employee_code: 'E7QK2MXPR',
        pin_sealed: 'c2VhbGVk',
        intent: 'auto',
      }),
    ).toMatchObject({ outcome: 'failed', cause: 'invalid', httpStatus: 400 })
  })

  it('un 422 que no es el rechazo estandar tambien es `invalid`', async () => {
    const client = createApiClient({
      fetchImpl: (async () => jsonResponse(422, { message: 'x' })) as unknown as typeof fetch,
    })

    expect(await client.recordScan(REQUEST)).toMatchObject({ cause: 'invalid', httpStatus: 422 })
  })

  it('un 400 SIN cuerpo JSON (proxy) sigue siendo transitorio: no se descarta un fichaje por eso', async () => {
    const client = createApiClient({
      fetchImpl: (async () =>
        new Response('<html>Bad Request</html>', { status: 400 })) as unknown as typeof fetch,
    })

    expect(await client.recordScan(REQUEST)).toMatchObject({ cause: 'server', httpStatus: 400 })
  })

  it('un 5xx y un 401 no son `invalid`', async () => {
    const serverDown = createApiClient({
      fetchImpl: (async () => jsonResponse(500, problem)) as unknown as typeof fetch,
    })
    const unauthorized = createApiClient({
      fetchImpl: (async () => jsonResponse(401, problem)) as unknown as typeof fetch,
    })

    expect(await serverDown.recordScan(REQUEST)).toMatchObject({ cause: 'server' })
    expect(await unauthorized.recordScan(REQUEST)).toMatchObject({ cause: 'unauthorized' })
  })
})

describe('RN-22 / RN-21 — lote, descartes y avisos en el cliente HTTP (RF-KI-04, RF-AT-07)', () => {
  const BATCH = { scans: [REQUEST] }
  const REPORT = {
    reports: [
      {
        scan_id: REQUEST.scan_id,
        occurred_at: REQUEST.occurred_at,
        kind: 'qr' as const,
        qr_payload: REQUEST.qr_payload,
        http_status: 400,
        problem_type: null,
        discarded_at: '2026-08-14T09:30:00.000Z',
      },
    ],
  }

  it('un 400 con cuerpo JSON en el lote es `invalid` (se reparte de uno en uno), no `server`', async () => {
    const client = createApiClient({
      fetchImpl: (async () =>
        jsonResponse(400, {
          type: 'urn:kronoqr:problem:invalid-request',
          status: 400,
        })) as unknown as typeof fetch,
    })

    expect(await client.syncScanBatch(BATCH, 'k1')).toMatchObject({
      outcome: 'failed',
      cause: 'invalid',
      httpStatus: 400,
      problemType: 'urn:kronoqr:problem:invalid-request',
    })
  })

  it('un 400 sin cuerpo JSON en el lote (proxy) sigue siendo transitorio', async () => {
    const client = createApiClient({
      fetchImpl: (async () => new Response('<html/>', { status: 400 })) as unknown as typeof fetch,
    })

    expect(await client.syncScanBatch(BATCH, 'k1')).toMatchObject({ cause: 'server' })
  })

  it('el `type` de un problema ajeno a este producto no viaja: `problemType` es null', async () => {
    const client = createApiClient({
      fetchImpl: (async () =>
        jsonResponse(422, { type: 'https://evil.example/x' })) as unknown as typeof fetch,
    })

    expect(await client.recordScan(REQUEST)).toMatchObject({
      cause: 'invalid',
      httpStatus: 422,
      problemType: null,
    })
  })

  it.each([
    'urn:kronoqr:problem:Invalid-Request',
    'urn:kronoqr:problem:a b',
    'urn:other:problem:x',
    'urn:kronoqr:problem:',
  ])('F9: un type raro (%s) viaja como null y el aviso se acusa', async (type) => {
    const failing = createApiClient({
      fetchImpl: (async () => jsonResponse(400, { type })) as unknown as typeof fetch,
    })
    const failure = await failing.recordScan(REQUEST)
    expect(failure).toMatchObject({ cause: 'invalid', problemType: null })

    const reporting = createApiClient({
      fetchImpl: (async () =>
        jsonResponse(200, { acknowledged: [REQUEST.scan_id] })) as unknown as typeof fetch,
    })
    const report = {
      ...REPORT.reports.at(0),
      problem_type: null,
    } as (typeof REPORT.reports)[number]
    expect(await reporting.reportDiscardedScans({ reports: [report] })).toMatchObject({
      outcome: 'ok',
    })
  })

  it('`reportDiscardedScans` va a /scan/discarded, con token, y lee el acuse', async () => {
    const fetchImpl = vi.fn<FetchLike>(async () =>
      jsonResponse(200, { acknowledged: [REQUEST.scan_id] }),
    )
    const client = createApiClient({
      fetchImpl: fetchImpl as unknown as typeof fetch,
      deviceToken: () => 'tok',
    })

    const result = await client.reportDiscardedScans(REPORT)

    expect(result).toEqual({ outcome: 'ok', data: { acknowledged: [REQUEST.scan_id] } })
    expect(fetchImpl.mock.calls[0]?.[0]).toBe('/api/v1/scan/discarded')
    const init = fetchImpl.mock.calls[0]?.[1]
    expect((init?.headers as Record<string, string>)['Authorization']).toBe('Bearer tok')
    expect(JSON.parse(String(init?.body))).toEqual(REPORT)
  })

  it('un acuse mal formado es `malformed`, no un acuse vacio', async () => {
    const client = createApiClient({
      fetchImpl: (async () => jsonResponse(200, { acknowledged: [1] })) as unknown as typeof fetch,
    })

    expect(await client.reportDiscardedScans(REPORT)).toMatchObject({ cause: 'malformed' })
  })

  it('un 400 con cuerpo es `invalid`; un 5xx, un 401 y un 429 son transitorios', async () => {
    const respond = (status: number, body: unknown = { type: 'x' }) =>
      createApiClient({
        fetchImpl: (async () => jsonResponse(status, body)) as unknown as typeof fetch,
      }).reportDiscardedScans(REPORT)

    expect(await respond(400)).toMatchObject({ cause: 'invalid' })
    expect(await respond(500)).toMatchObject({ cause: 'server' })
    expect(await respond(401)).toMatchObject({ cause: 'unauthorized' })
    expect(await respond(429)).toMatchObject({ cause: 'throttled' })
  })
})
