// Cliente HTTP del quiosco.
//
// Los TIPOS se generan del contrato (`types.ts` -> `schema.d.ts`); esto es solo
// el transporte: cabeceras, tiempos de espera y traduccion de un fallo de red a
// un valor que el llamante pueda ramificar sin `try/catch`.
//
// Regla que gobierna este fichero: **nada de lo que pase aqui puede impedir
// fichar** (regla dura 19). Por eso no lanza excepciones hacia arriba; devuelve
// un `ApiResult` y quien llama decide. Un `reject` sin capturar en el camino del
// escaneo es una pantalla en blanco delante de una cola de gente.

import type {
  KioskHeartbeat,
  KioskHeartbeatRequest,
  KioskRoster,
  ScanBatchRequest,
  ScanBatchResponse,
  ScanOk,
  ScanRejected,
  ScanRequest,
} from './types'

/** Motivo por el que una llamada no llego a obtener respuesta util del servidor. */
export type ApiFailureCause =
  | 'offline' // el navegador dice que no hay red
  | 'network' // fetch fallo (DNS, TLS, conexion cortada a medias)
  | 'timeout' // el servidor no contesto a tiempo
  | 'unauthorized' // 401/403: token de dispositivo caducado o revocado
  | 'throttled' // 429
  | 'server' // 5xx u otro codigo inesperado
  | 'malformed' // 2xx con un cuerpo que no encaja con el contrato

export type ApiResult<TOk> =
  | { readonly outcome: 'ok'; readonly data: TOk }
  | { readonly outcome: 'rejected'; readonly problem: ScanRejected }
  | { readonly outcome: 'failed'; readonly cause: ApiFailureCause; readonly httpStatus?: number }

export interface ApiClientOptions {
  /** Origen de la API. Vacio significa mismo origen, que es lo normal en el quiosco. */
  readonly baseUrl?: string
  /** Token de dispositivo. Es una funcion porque rota (doc 02 §7.3) y el emparejamiento es de otra tarea. */
  readonly deviceToken?: () => string | null
  readonly fetchImpl?: typeof fetch
  /** Techo de espera. Corto a proposito: el quiosco ya ha confirmado en local. */
  readonly timeoutMs?: number
}

export interface ApiClient {
  recordScan(request: ScanRequest): Promise<ApiResult<ScanOk>>
  /**
   * Sincroniza la cola offline. `batchKey` es la `Idempotency-Key` **del lote**
   * (un UUID v7 propio, nunca un `scan_id`): identifica el ENVIO y sirve para
   * correlacionar reintentos en los registros. La deduplicacion real es
   * elemento a elemento, por el UNIQUE de `scan_events.scan_id`.
   */
  syncScanBatch(request: ScanBatchRequest, batchKey: string): Promise<ApiResult<ScanBatchResponse>>
  fetchRoster(): Promise<ApiResult<KioskRoster>>
  sendHeartbeat(body: KioskHeartbeatRequest): Promise<ApiResult<KioskHeartbeat>>
}

const DEFAULT_TIMEOUT_MS = 8_000

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

/** Un `200` de `/scan` es `ScanAccepted` o `ScanDebounced`, discriminados por `action`. */
function isScanOk(value: unknown): value is ScanOk {
  if (!isRecord(value)) return false
  const { action, scan_id: scanId, employee_display_name: name } = value
  return typeof action === 'string' && typeof scanId === 'string' && typeof name === 'string'
}

function isScanRejected(value: unknown): value is ScanRejected {
  return isRecord(value) && value['type'] === 'urn:kronoqr:problem:scan-rejected'
}

function isScanBatchResponse(value: unknown): value is ScanBatchResponse {
  return isRecord(value) && Array.isArray(value['results'])
}

function isKioskRoster(value: unknown): value is KioskRoster {
  return (
    isRecord(value) && typeof value['generated_at'] === 'string' && Array.isArray(value['entries'])
  )
}

function isKioskHeartbeat(value: unknown): value is KioskHeartbeat {
  return isRecord(value) && typeof value['server_time'] === 'string'
}

function causeForStatus(status: number): ApiFailureCause {
  if (status === 401 || status === 403) return 'unauthorized'
  if (status === 429) return 'throttled'
  return 'server'
}

export function createApiClient(options: ApiClientOptions = {}): ApiClient {
  const baseUrl = (options.baseUrl ?? '').replace(/\/$/, '')
  const doFetch = options.fetchImpl ?? globalThis.fetch.bind(globalThis)
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS
  const deviceToken = options.deviceToken ?? (() => null)

  async function send(
    path: string,
    init: { method: 'GET' | 'POST'; body?: unknown; idempotencyKey?: string },
  ): Promise<{ status: number; body: unknown } | { failure: ApiFailureCause }> {
    // `navigator.onLine` en `false` es informacion fiable (en `true` no lo es):
    // ahorra un fetch condenado y deja claro por que no se ha enviado.
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      return { failure: 'offline' }
    }

    const headers: Record<string, string> = { Accept: 'application/json' }
    const token = deviceToken()
    if (token !== null && token !== '') headers['Authorization'] = `Bearer ${token}`
    if (init.body !== undefined) headers['Content-Type'] = 'application/json'
    if (init.idempotencyKey !== undefined) headers['Idempotency-Key'] = init.idempotencyKey

    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), timeoutMs)

    try {
      const response = await doFetch(`${baseUrl}${path}`, {
        method: init.method,
        headers,
        signal: controller.signal,
        ...(init.body === undefined ? {} : { body: JSON.stringify(init.body) }),
      })

      const text = await response.text()
      let parsed: unknown = null
      if (text !== '') {
        try {
          parsed = JSON.parse(text)
        } catch {
          parsed = null
        }
      }

      return { status: response.status, body: parsed }
    } catch (error) {
      const aborted = error instanceof DOMException && error.name === 'AbortError'
      return { failure: aborted ? 'timeout' : 'network' }
    } finally {
      clearTimeout(timer)
    }
  }

  return {
    async recordScan(request) {
      const result = await send('/api/v1/scan', {
        method: 'POST',
        body: request,
        // Regla dura 8: el mismo `scan_id` en el envio y en todos los reintentos.
        idempotencyKey: request.scan_id,
      })
      if ('failure' in result) return { outcome: 'failed', cause: result.failure }

      if (result.status === 200) {
        return isScanOk(result.body)
          ? { outcome: 'ok', data: result.body }
          : { outcome: 'failed', cause: 'malformed', httpStatus: 200 }
      }
      if (result.status === 422 && isScanRejected(result.body)) {
        return { outcome: 'rejected', problem: result.body }
      }
      return { outcome: 'failed', cause: causeForStatus(result.status), httpStatus: result.status }
    },

    async syncScanBatch(request, batchKey) {
      const result = await send('/api/v1/scan/batch', {
        method: 'POST',
        body: request,
        idempotencyKey: batchKey,
      })
      if ('failure' in result) return { outcome: 'failed', cause: result.failure }

      // 207 SIEMPRE, aunque todos los elementos se acepten o todos se rechacen:
      // el codigo describe la forma de la respuesta, no el desenlace agregado.
      if (result.status === 207) {
        return isScanBatchResponse(result.body)
          ? { outcome: 'ok', data: result.body }
          : { outcome: 'failed', cause: 'malformed', httpStatus: 207 }
      }
      return { outcome: 'failed', cause: causeForStatus(result.status), httpStatus: result.status }
    },

    async fetchRoster() {
      const result = await send('/api/v1/kiosk/roster', { method: 'GET' })
      if ('failure' in result) return { outcome: 'failed', cause: result.failure }
      if (result.status === 200) {
        return isKioskRoster(result.body)
          ? { outcome: 'ok', data: result.body }
          : { outcome: 'failed', cause: 'malformed', httpStatus: 200 }
      }
      return { outcome: 'failed', cause: causeForStatus(result.status), httpStatus: result.status }
    },

    async sendHeartbeat(body) {
      const result = await send('/api/v1/kiosk/heartbeat', { method: 'POST', body })
      if ('failure' in result) return { outcome: 'failed', cause: result.failure }
      if (result.status === 200) {
        return isKioskHeartbeat(result.body)
          ? { outcome: 'ok', data: result.body }
          : { outcome: 'failed', cause: 'malformed', httpStatus: 200 }
      }
      return { outcome: 'failed', cause: causeForStatus(result.status), httpStatus: result.status }
    },
  }
}
