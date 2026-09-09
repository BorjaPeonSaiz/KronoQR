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

import { parseBranding } from '@kronoqr/web-kit/branding'
import type {
  Branding,
  KioskHeartbeat,
  KioskHeartbeatRequest,
  KioskRoster,
  PairingClaim,
  PairingClaimRequest,
  PairingRejected,
  PairingRequestBody,
  PairingRequested,
  PinScanRequest,
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

/**
 * `TProblem` por defecto es `ScanRejected` porque es, con diferencia, el caso
 * mas comun: casi todos los metodos de este cliente no tienen una forma de
 * rechazo propia y jamas construyen la rama `rejected` (igual que
 * `sendHeartbeat` o `fetchRoster` hoy). El emparejamiento SI tiene la suya
 * (`PairingRejected`, regla dura 17) y por eso `claimPairing` la sobrescribe.
 */
export type ApiResult<TOk, TProblem = ScanRejected> =
  | { readonly outcome: 'ok'; readonly data: TOk }
  | { readonly outcome: 'rejected'; readonly problem: TProblem }
  | {
      readonly outcome: 'failed'
      readonly cause: ApiFailureCause
      readonly httpStatus?: number
      /**
       * Nombres de campo de un `400` con forma `ValidationProblem`
       * (`errors`, RFC 9457). `undefined` salvo que quien llama lo rellene:
       * hoy solo `sendHeartbeat`, para distinguir un `client_errors` invalido
       * de un `app_version`/`pending_queue_size` invalidos sin adivinar por
       * el codigo de estado (tarea 5.12, RF-PD-15).
       */
      readonly invalidFields?: readonly string[]
    }

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
   * Fichaje de respaldo por PIN (RF-AT-11, tarea 1.12). Misma forma de
   * respuesta que `recordScan` y a proposito: para el empleado no hay dos
   * caminos, hay uno con dos formas de identificarse.
   */
  recordPinScan(request: PinScanRequest): Promise<ApiResult<ScanOk>>
  /**
   * Sincroniza la cola offline. `batchKey` es la `Idempotency-Key` **del lote**
   * (un UUID v7 propio, nunca un `scan_id`): identifica el ENVIO y sirve para
   * correlacionar reintentos en los registros. La deduplicacion real es
   * elemento a elemento, por el UNIQUE de `scan_events.scan_id`.
   */
  syncScanBatch(request: ScanBatchRequest, batchKey: string): Promise<ApiResult<ScanBatchResponse>>
  fetchRoster(): Promise<ApiResult<KioskRoster>>
  sendHeartbeat(body: KioskHeartbeatRequest): Promise<ApiResult<KioskHeartbeat>>
  /**
   * Paso 1 de RF-PD-06 (tarea 5.6): la tablet pide emparejarse. **Publica**,
   * nunca lleva `Authorization` (quien la llama todavia no tiene token) y no
   * tiene forma de rechazo propia: solo puede fallar por transporte, limite de
   * borde o cuerpo mal formado, igual que `sendHeartbeat`.
   */
  requestPairing(body: PairingRequestBody): Promise<ApiResult<PairingRequested>>
  /**
   * Paso 2: el sondeo de la tablet. Tambien publica. El rechazo SI tiene forma
   * propia (`PairingRejected`, regla dura 17): las tres causas —solicitud
   * desconocida, secreto incorrecto, caducada o consumida— son indistinguibles
   * desde aqui a proposito.
   */
  claimPairing(body: PairingClaimRequest): Promise<ApiResult<PairingClaim, PairingRejected>>
  /**
   * Marca de la instalacion (RF-PD-08, tarea 5.8). **Publica** (`authenticated:
   * false`): la pantalla de espera la necesita antes de que nadie se
   * identifique, igual que `fetchRoster` no necesitaria token si no fuera
   * ademas el padron. Nunca puede impedir fichar (regla dura 19): quien la
   * llama la trata siempre en segundo plano, sin `await` en el camino del
   * escaneo (ver `useBranding.ts`).
   */
  fetchBranding(): Promise<ApiResult<Branding>>
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

/**
 * Reutiliza el validador de `web-kit` en vez de duplicar sus reglas aqui: si
 * `parseBranding` lo acepta, la forma es la del contrato (RF-PD-08).
 */
function isBranding(value: unknown): value is Branding {
  return parseBranding(value) !== null
}

function isPairingRequested(value: unknown): value is PairingRequested {
  if (!isRecord(value)) return false
  const { pairing_id: pairingId, pairing_secret: pairingSecret, code } = value
  return (
    typeof pairingId === 'string' && typeof pairingSecret === 'string' && typeof code === 'string'
  )
}

/** Las dos ramas de `PairingClaim` comparten `status`; basta con comprobar ese campo. */
function isPairingClaim(value: unknown): value is PairingClaim {
  if (!isRecord(value)) return false
  return value['status'] === 'pending' || value['status'] === 'paired'
}

function isPairingRejected(value: unknown): value is PairingRejected {
  return isRecord(value) && value['type'] === 'urn:kronoqr:problem:pairing-rejected'
}

function causeForStatus(status: number): ApiFailureCause {
  if (status === 401 || status === 403) return 'unauthorized'
  if (status === 429) return 'throttled'
  return 'server'
}

/**
 * Nombres de campo de un `400` con forma `ValidationProblem`
 * (`{ errors: { <campo>: string[] } }`, RFC 9457). `undefined` si el cuerpo
 * no trae esa forma -otro tipo de `400`, o un cuerpo vacio-.
 */
function invalidFieldsOf(body: unknown): readonly string[] | undefined {
  if (!isRecord(body)) return undefined
  const { errors } = body
  if (!isRecord(errors)) return undefined
  const fields = Object.keys(errors)
  return fields.length > 0 ? fields : undefined
}

export function createApiClient(options: ApiClientOptions = {}): ApiClient {
  const baseUrl = (options.baseUrl ?? '').replace(/\/$/, '')
  const doFetch = options.fetchImpl ?? globalThis.fetch.bind(globalThis)
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS
  const deviceToken = options.deviceToken ?? (() => null)

  async function send(
    path: string,
    init: {
      method: 'GET' | 'POST'
      body?: unknown
      idempotencyKey?: string
      /**
       * `false` en las dos rutas publicas de emparejamiento (`requestPairing`,
       * `claimPairing`, RF-PD-06): quien las llama todavia no tiene token, y
       * adjuntar uno viejo de `localStorage` -el caso real de una tablet
       * recien revocada que vuelve a `/pair`- filtraria una credencial muerta
       * a un endpoint publico. Por defecto `true`: todo lo demas SI va
       * autenticado.
       */
      authenticated?: boolean
    },
  ): Promise<{ status: number; body: unknown } | { failure: ApiFailureCause }> {
    // `navigator.onLine` en `false` es informacion fiable (en `true` no lo es):
    // ahorra un fetch condenado y deja claro por que no se ha enviado.
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      return { failure: 'offline' }
    }

    const headers: Record<string, string> = { Accept: 'application/json' }
    if (init.authenticated !== false) {
      const token = deviceToken()
      if (token !== null && token !== '') headers['Authorization'] = `Bearer ${token}`
    }
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

    async recordPinScan(request) {
      const result = await send('/api/v1/scan/pin', {
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
      // `invalidFields` solo tiene sentido en un `400` (`ValidationProblem`):
      // en cualquier otro estado seria un cuerpo de otra forma, y el
      // planificador del latido (`heartbeat.ts`) solo lo mira cuando
      // `httpStatus === 400`, asi que no vale la pena parsear en los demas.
      // `exactOptionalPropertyTypes`: la clave se omite del todo cuando no
      // hay nada que ofrecer, en vez de escribirse con `undefined`.
      const invalidFields = result.status === 400 ? invalidFieldsOf(result.body) : undefined
      return {
        outcome: 'failed',
        cause: causeForStatus(result.status),
        httpStatus: result.status,
        ...(invalidFields === undefined ? {} : { invalidFields }),
      }
    },

    async requestPairing(body) {
      const result = await send('/api/v1/kiosk/pair', {
        method: 'POST',
        body,
        authenticated: false,
      })
      if ('failure' in result) return { outcome: 'failed', cause: result.failure }
      if (result.status === 201) {
        return isPairingRequested(result.body)
          ? { outcome: 'ok', data: result.body }
          : { outcome: 'failed', cause: 'malformed', httpStatus: 201 }
      }
      return { outcome: 'failed', cause: causeForStatus(result.status), httpStatus: result.status }
    },

    async claimPairing(body) {
      const result = await send('/api/v1/kiosk/pair/claim', {
        method: 'POST',
        body,
        authenticated: false,
      })
      if ('failure' in result) return { outcome: 'failed', cause: result.failure }
      if (result.status === 200) {
        return isPairingClaim(result.body)
          ? { outcome: 'ok', data: result.body }
          : { outcome: 'failed', cause: 'malformed', httpStatus: 200 }
      }
      if (result.status === 422 && isPairingRejected(result.body)) {
        return { outcome: 'rejected', problem: result.body }
      }
      return { outcome: 'failed', cause: causeForStatus(result.status), httpStatus: result.status }
    },

    async fetchBranding() {
      // Publica a proposito: se pide antes de identificar a nadie, y un token
      // viejo de una tablet revocada no tiene nada que hacer aqui (mismo
      // motivo que `requestPairing`/`claimPairing`).
      const result = await send('/api/v1/branding', { method: 'GET', authenticated: false })
      if ('failure' in result) return { outcome: 'failed', cause: result.failure }
      if (result.status === 200) {
        return isBranding(result.body)
          ? { outcome: 'ok', data: result.body }
          : { outcome: 'failed', cause: 'malformed', httpStatus: 200 }
      }
      return { outcome: 'failed', cause: causeForStatus(result.status), httpStatus: result.status }
    },
  }
}
