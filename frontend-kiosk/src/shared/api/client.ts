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
//
// Cada peticion lleva un `traceparent` (W3C Trace Context) nuevo, generado con
// `@kronoqr/web-kit/traceparent` (tarea 3.1): es la raiz de la traza que el
// backend sigue hasta la consulta SQL. Se genera por INTENTO, no por fichaje:
// ver el comentario en `send()`.

import { parseBranding } from '@kronoqr/web-kit/branding'
import { createTraceparent } from '@kronoqr/web-kit/traceparent'
import type {
  Branding,
  DiscardedScanReceipt,
  DiscardedScanReportBatch,
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
  // 400 o 422 con cuerpo JSON: el servidor decidio que ESTA peticion no vale y
  // reenviarla no lo cambia (PIN-08). Un 400 sin cuerpo JSON (proxy, WAF) sigue
  // siendo `server`: eso si puede ser transitorio.
  | 'invalid'
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
      /**
       * Solo en `cause: 'invalid'` de un fichaje (RN-22): el `type` del problema
       * recibido si era de este producto (`urn:kronoqr:problem:*`), o `null`.
       * Es lo que viaja en el aviso de descartado; el `detail` no se transporta.
       */
      readonly problemType?: string | null
    }

export interface ApiClientOptions {
  /** Origen de la API. Vacio significa mismo origen, que es lo normal en el quiosco. */
  readonly baseUrl?: string
  /** Token de dispositivo. Es una funcion porque rota (doc 02 §7.3) y el emparejamiento es de otra tarea. */
  readonly deviceToken?: () => string | null
  readonly fetchImpl?: typeof fetch
  /** Techo de espera. Corto a proposito: el quiosco ya ha confirmado en local. */
  readonly timeoutMs?: number
  /**
   * Token ANTERIOR conservado tras un relevo (RF-ID-04, ADR-044), o `null`. Si
   * el vigente recibe `401` y hay respaldo, se repite UNA vez con el.
   */
  readonly fallbackDeviceToken?: () => string | null
  /** El token `token` ha obtenido una respuesta autenticada (cualquier estado que no sea 401/403). */
  readonly onDeviceTokenConfirmed?: (token: string) => void
  /** El servidor ha aceptado el RESPALDO cuando el vigente daba 401: el vigente esta muerto. */
  readonly onFallbackTokenConfirmed?: () => void
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
  /**
   * Aviso de fichajes descartados (RN-22, `POST /api/v1/scan/discarded`). Sin
   * `Idempotency-Key`: es idempotente por `scan_id` en el servidor. Un `400`
   * (`cause: 'invalid'`) significa que ALGUN aviso del lote no vale.
   */
  reportDiscardedScans(body: DiscardedScanReportBatch): Promise<ApiResult<DiscardedScanReceipt>>
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

function isDiscardedScanReceipt(value: unknown): value is DiscardedScanReceipt {
  return (
    isRecord(value) &&
    Array.isArray(value['acknowledged']) &&
    value['acknowledged'].every((item) => typeof item === 'string')
  )
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

/**
 * Un estado que prueba que el servidor ha AUTENTICADO la peticion: la respuesta
 * es de la aplicacion, no de un proxy (5xx) ni de un limitador de borde (429),
 * y no es un rechazo de credencial (401/403).
 */
function confirmsAuthentication(status: number): boolean {
  return status < 500 && status !== 401 && status !== 403 && status !== 429
}

function causeForStatus(status: number): ApiFailureCause {
  if (status === 401 || status === 403) return 'unauthorized'
  if (status === 429) return 'throttled'
  return 'server'
}

/** `type` de un problema de ESTE producto; `null` si no trae uno reconocible. */
function problemTypeOf(body: Record<string, unknown>): string | null {
  const type = body['type']
  return typeof type === 'string' && type.startsWith('urn:kronoqr:problem:')
    ? type.slice(0, 120)
    : null
}

/**
 * Fallo de un envio de fichaje (`/scan`, `/scan/pin`). Un `400` o un `422` que
 * no es el rechazo estandar, pero con cuerpo JSON (`ValidationProblem`), es
 * TERMINAL: el mismo `scan_id` con los mismos datos dara lo mismo siempre, y si
 * se reintentara, al respetar el orden de la cola bloquearia todo lo que viene
 * detras (PIN-08). Sin cuerpo JSON (pagina de error de un proxy) no se puede
 * afirmar que lo haya decidido la aplicacion, asi que sigue siendo transitorio.
 */
function scanFailureFor(status: number, body: unknown): ApiResult<never> {
  if ((status === 400 || status === 422) && isRecord(body)) {
    return {
      outcome: 'failed',
      cause: 'invalid',
      httpStatus: status,
      problemType: problemTypeOf(body),
    }
  }
  return { outcome: 'failed', cause: causeForStatus(status), httpStatus: status }
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

  interface SendInit {
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
  }

  type SendOutcome = { status: number; body: unknown } | { failure: ApiFailureCause }

  /**
   * RELEVO DEL TOKEN (RF-ID-04, ADR-044). Un `401` no siempre es una
   * revocacion, y no debe contarse como tal ni dejar el fichaje sin enviar:
   *
   * 1. Una peticion firmada con el token anterior que llega DESPUES del primer
   *    uso del nuevo recibe `401` (el solape termina ahi). Si el token vigente
   *    YA no es el que la firmo, se repite UNA vez con el vigente.
   * 2. Con el vigente sin cambios, si hay un respaldo (el token que sustituyo
   *    el relevo, aun no retirado) se repite UNA vez con el: el relevo puede ser
   *    un token muerto (dos relevos cruzados). Si el servidor lo acepta, se
   *    avisa para que la tablet vuelva a el.
   * 3. En cualquier otro caso un `401` es un `401`.
   *
   * Siempre la misma `Idempotency-Key` (regla dura 8) y como mucho DOS intentos.
   */
  async function send(path: string, init: SendInit): Promise<SendOutcome> {
    if (init.authenticated === false) return sendOnce(path, init, null)

    const signedWith = deviceToken()
    const first = await sendOnce(path, init, signedWith)
    if ('failure' in first) return first
    if (first.status !== 401) {
      if (signedWith !== null && signedWith !== '' && confirmsAuthentication(first.status)) {
        options.onDeviceTokenConfirmed?.(signedWith)
      }
      return first
    }

    const current = deviceToken()
    if (current !== null && current !== '' && current !== signedWith) {
      const retried = await sendOnce(path, init, current)
      if (!('failure' in retried) && confirmsAuthentication(retried.status)) {
        options.onDeviceTokenConfirmed?.(current)
      }
      return retried
    }

    const fallback = options.fallbackDeviceToken?.() ?? null
    if (fallback !== null && fallback !== '' && fallback !== signedWith) {
      const retried = await sendOnce(path, init, fallback)
      if (!('failure' in retried) && confirmsAuthentication(retried.status)) {
        options.onFallbackTokenConfirmed?.()
        return retried
      }
    }
    return first
  }

  async function sendOnce(
    path: string,
    init: SendInit,
    token: string | null,
  ): Promise<SendOutcome> {
    // `navigator.onLine` en `false` es informacion fiable (en `true` no lo es):
    // ahorra un fetch condenado y deja claro por que no se ha enviado.
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      return { failure: 'offline' }
    }

    // `traceparent` (W3C Trace Context) nuevo por intento, no por fichaje
    // (tarea 3.1, decision 7): un reenvio de la cola offline con el MISMO
    // `scan_id` genera una traza distinta cada vez. Es la traza del intento;
    // el `scan_id` sigue siendo la correlacion entre intentos (doc 01 §9.4).
    const headers: Record<string, string> = {
      Accept: 'application/json',
      traceparent: createTraceparent(),
    }
    if (init.authenticated !== false && token !== null && token !== '') {
      headers['Authorization'] = `Bearer ${token}`
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
      return scanFailureFor(result.status, result.body)
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
      return scanFailureFor(result.status, result.body)
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
      // Un `400` con cuerpo JSON es el lote entero rechazado por mal formado
      // (PIN-08): `cause: 'invalid'` para que el drenaje lo reparta de uno en
      // uno. Antes caia en `server` y se reintentaba el mismo lote para siempre.
      return scanFailureFor(result.status, result.body)
    },

    async reportDiscardedScans(body) {
      const result = await send('/api/v1/scan/discarded', { method: 'POST', body })
      if ('failure' in result) return { outcome: 'failed', cause: result.failure }
      if (result.status === 200) {
        return isDiscardedScanReceipt(result.body)
          ? { outcome: 'ok', data: result.body }
          : { outcome: 'failed', cause: 'malformed', httpStatus: 200 }
      }
      // Solo el `400` con cuerpo JSON es «este aviso no vale»; el resto (proxy,
      // 401, 429, 5xx) es transitorio y se reintenta tal cual.
      if (result.status === 400 && isRecord(result.body)) {
        return { outcome: 'failed', cause: 'invalid', httpStatus: 400 }
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
