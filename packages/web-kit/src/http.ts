// Cliente HTTP base, compartido por las SPA del panel y del portal (ADR-036).
//
// Una sola puerta de salida hacia la API, por tres motivos que no son de estilo:
//
//  1. El token de sesion se pone en un unico sitio. Un `fetch` suelto en un
//     componente es un dia un `fetch` sin `Authorization`.
//  2. Los fallos se traducen a un tipo cerrado (`ApiErrorKind`). La interfaz
//     tiene que decir QUE ha pasado y QUE hacer, y eso solo se puede escribir
//     una vez por causa, no una vez por pantalla.
//  3. Los documentos binarios (PDF de credenciales, CSV del historico) son
//     instrumentos al portador o datos personales: se descargan por
//     `requestBlob`, que no los cachea ni los deja en ningun sitio.
//
// **Lo que NO vive aqui**, a proposito (ADR-036): ningun endpoint concreto.
// `requestJson('/api/v1/employees', ...)` es de `frontend-admin`;
// `requestJson('/api/v1/me/workdays', ...)` sera de `frontend-portal`. Este
// fichero es la fontaneria, no el mapa de la API.
//
// `Problem`/`ValidationProblem` reproducen la forma RFC 9457 del contrato
// (`docs/api/openapi.yaml`, esquemas `Problem`/`ValidationProblem`). Se
// declaran aqui y no se importan del cliente generado de cada SPA: son
// identicas para cualquier endpoint y mantenerlas locales evita que este
// paquete dependa del `schema.d.ts` particular de una aplicacion.

/** Un problema `application/problem+json` (RFC 9457), tal y como lo define el contrato. */
export interface Problem {
  type: string
  title: string
  status: number
  /** Explicacion para quien depura. El cliente no la muestra al usuario. */
  detail?: string
  instance?: string
}

/** Un `Problem` de validacion (`422`), con los mensajes por campo. */
export interface ValidationProblem extends Problem {
  errors: Record<string, string[]>
}

/** Causa del fallo, ya normalizada. Es lo que elige el texto que se muestra. */
export type ApiErrorKind =
  | 'network'
  | 'unauthenticated'
  | 'invalidCredentials'
  | 'forbidden'
  | 'notFound'
  | 'conflict'
  | 'validation'
  | 'rateLimited'
  | 'unavailable'
  | 'unexpected'

/** Errores por campo de un `422`, tal y como los pinta un formulario. */
export type FieldErrors = Readonly<Record<string, readonly string[]>>

/**
 * Un fallo de la API con la informacion que la interfaz necesita para explicarlo.
 *
 * `kind` es la causa normalizada; `problem` es el `application/problem+json` tal
 * cual lo mando el servidor. **`problem.detail` no se enseña**: el contrato dice
 * que es explicacion para quien depura, no texto de usuario. Lo que ve una
 * persona sale siempre de `i18n`, y ademas dice que hacer a continuacion.
 */
export class ApiError extends Error {
  readonly kind: ApiErrorKind
  readonly status: number
  readonly problem: Problem | null
  readonly fieldErrors: FieldErrors
  readonly retryAfterSeconds: number | null

  constructor(init: {
    kind: ApiErrorKind
    status: number
    problem?: Problem | null
    fieldErrors?: FieldErrors
    retryAfterSeconds?: number | null
  }) {
    super(init.problem?.title ?? init.kind)
    this.name = 'ApiError'
    this.kind = init.kind
    this.status = init.status
    this.problem = init.problem ?? null
    this.fieldErrors = init.fieldErrors ?? {}
    this.retryAfterSeconds = init.retryAfterSeconds ?? null
  }

  /** Clave de i18n del titulo. Los textos viven en `locales/*.json` de cada SPA. */
  get titleKey(): string {
    return `errors.${this.kind}.title`
  }

  /** Clave de i18n de la accion siguiente. Un error sin salida no es un error util. */
  get adviceKey(): string {
    return `errors.${this.kind}.advice`
  }
}

export function isApiError(value: unknown): value is ApiError {
  return value instanceof ApiError
}

/** Valor admitido en una cadena de consulta. `undefined` no se serializa. */
export type QueryValue = string | number | boolean | undefined

type Method = 'GET' | 'POST' | 'PATCH'

export interface RequestOptions {
  method?: Method
  query?: Readonly<Record<string, QueryValue>>
  body?: unknown
  signal?: AbortSignal
  /** Sin token y sin redirigir al acceso ante un `401`: solo el propio acceso. */
  anonymous?: boolean
  /**
   * Tipos aceptados en la respuesta. Sin valor por omision especifico de un
   * formato: cada llamada dice si espera un PDF, un CSV o solo el problema de
   * error, porque el formato es propio del endpoint, no de este cliente.
   */
  accept?: string
}

let authTokenProvider: () => string | null = () => null
let unauthenticatedHandler: () => void = () => {}

/** Lo llama la tienda de sesion al arrancar. Evita el ciclo tienda ↔ cliente. */
export function setAuthTokenProvider(provider: () => string | null): void {
  authTokenProvider = provider
}

/** Que hacer cuando el servidor dice que la sesion ya no vale. */
export function setUnauthenticatedHandler(handler: () => void): void {
  unauthenticatedHandler = handler
}

export function apiBaseUrl(): string {
  return import.meta.env.VITE_API_BASE_URL ?? ''
}

function buildUrl(path: string, query?: Readonly<Record<string, QueryValue>>): string {
  const search = new URLSearchParams()

  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined) {
      search.set(key, String(value))
    }
  }

  const suffix = search.size > 0 ? `?${search.toString()}` : ''

  return `${apiBaseUrl()}${path}${suffix}`
}

function kindForStatus(status: number): ApiErrorKind {
  switch (status) {
    case 401:
      return 'unauthenticated'
    case 403:
      return 'forbidden'
    case 404:
      return 'notFound'
    case 409:
      return 'conflict'
    case 400:
    case 422:
      return 'validation'
    case 429:
      return 'rateLimited'
    case 503:
      return 'unavailable'
    default:
      return 'unexpected'
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function readProblem(payload: unknown): Problem | null {
  if (!isRecord(payload) || typeof payload['title'] !== 'string') {
    return null
  }

  return payload as unknown as Problem
}

function readFieldErrors(payload: unknown): FieldErrors {
  if (!isRecord(payload)) {
    return {}
  }

  const errors = (payload as unknown as ValidationProblem).errors

  if (!isRecord(errors)) {
    return {}
  }

  const result: Record<string, readonly string[]> = {}

  for (const [field, messages] of Object.entries(errors)) {
    if (Array.isArray(messages)) {
      result[field] = messages.filter((message): message is string => typeof message === 'string')
    }
  }

  return result
}

function retryAfter(response: Response): number | null {
  const raw = response.headers.get('Retry-After')
  const seconds = raw === null ? Number.NaN : Number.parseInt(raw, 10)

  return Number.isFinite(seconds) ? seconds : null
}

async function toApiError(response: Response): Promise<ApiError> {
  let payload: unknown = null

  try {
    payload = await response.json()
  } catch {
    payload = null
  }

  const problem = readProblem(payload)
  const kind =
    response.status === 401 && problem?.type === 'urn:kronoqr:problem:invalid-credentials'
      ? 'invalidCredentials'
      : kindForStatus(response.status)

  return new ApiError({
    kind,
    status: response.status,
    problem,
    fieldErrors: readFieldErrors(payload),
    retryAfterSeconds: retryAfter(response),
  })
}

async function send(path: string, options: RequestOptions, accept: string): Promise<Response> {
  const headers = new Headers({ Accept: accept })
  const token = options.anonymous === true ? null : authTokenProvider()

  if (token !== null) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  if (options.body !== undefined) {
    headers.set('Content-Type', 'application/json')
  }

  let response: Response

  try {
    response = await fetch(buildUrl(path, options.query), {
      method: options.method ?? 'GET',
      headers,
      // Ni el PDF de una tarjeta ni el historico de una persona tienen por que
      // quedarse en la cache del navegador.
      cache: 'no-store',
      credentials: 'omit',
      ...(options.body === undefined ? {} : { body: JSON.stringify(options.body) }),
      ...(options.signal === undefined ? {} : { signal: options.signal }),
    })
  } catch {
    throw new ApiError({ kind: 'network', status: 0 })
  }

  if (!response.ok) {
    const error = await toApiError(response)

    if (error.status === 401 && options.anonymous !== true) {
      unauthenticatedHandler()
    }

    throw error
  }

  return response
}

/** Peticion JSON. `204` se representa como `null`. */
export async function request<T>(path: string, options: RequestOptions = {}): Promise<T | null> {
  const response = await send(path, options, 'application/json')

  if (response.status === 204) {
    return null
  }

  return (await response.json()) as T
}

/** Igual que `request`, para respuestas que siempre traen cuerpo. */
export async function requestJson<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const body = await request<T>(path, options)

  if (body === null) {
    throw new ApiError({ kind: 'unexpected', status: 204 })
  }

  return body
}

/** Un documento binario. `null` cuando el servidor responde `204`. */
export interface BinaryDocument {
  blob: Blob
  filename: string
  /**
   * Cabecera `X-Kronoqr-Printed-Count`, cuando el endpoint la manda: cuantas
   * tarjetas lleva el PDF de un lote de impresion. `null` si el endpoint no
   * publica esta cabecera, que es lo normal fuera de credenciales.
   */
  printedCount: number | null
  /**
   * Las cabeceras de la respuesta, para lo que cada descarga necesite contar.
   *
   * La exportacion legal publica `X-Kronoqr-Export-Shift-Rows` y
   * `X-Kronoqr-Export-Correction-Rows`: son las mismas cifras que quedan en
   * `audit_log`, y sin ellas la pantalla no puede decir cuanto entrego. Se
   * exponen en crudo en vez de añadir un campo por descarga: ese endpoint es
   * de `frontend-admin` y no de este paquete, y una lista de contadores
   * especificos por informe crece con cada informe nuevo.
   */
  headers: Headers
}

const FILENAME_PATTERN = /filename="?([^";]+)"?/i

function filenameFrom(response: Response, fallback: string): string {
  const disposition = response.headers.get('Content-Disposition') ?? ''
  const match = FILENAME_PATTERN.exec(disposition)

  return match?.[1] ?? fallback
}

/**
 * Descarga un documento. Devuelve `null` en un `204`, que en la impresion por
 * lotes de credenciales NO es un error: es la forma que toma su idempotencia
 * (ADR-034). Otros endpoints binarios simplemente no responden nunca `204`.
 */
export async function requestBlob(
  path: string,
  fallbackFilename: string,
  options: RequestOptions = {},
): Promise<BinaryDocument | null> {
  const response = await send(path, options, options.accept ?? 'application/problem+json')

  if (response.status === 204) {
    return null
  }

  const rawCount = response.headers.get('X-Kronoqr-Printed-Count')
  const printedCount = rawCount === null ? Number.NaN : Number.parseInt(rawCount, 10)

  return {
    blob: await response.blob(),
    filename: filenameFrom(response, fallbackFilename),
    printedCount: Number.isFinite(printedCount) ? printedCount : null,
    headers: response.headers,
  }
}
