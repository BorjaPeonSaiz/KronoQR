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
//
// CADA PETICION LLEVA `traceparent` (doc 02 §8.1: la cabecera es
// `traceparent`, en el borde, en la aplicacion y en los tres frontends). Se
// genera aqui, en el unico punto de salida, para que el panel y el portal no
// tengan que acordarse de hacerlo cada uno por su lado. El backend
// (`PropagateTraceContext`) abre el span `SERVER` de la peticion a partir de
// esta cabecera: la raiz de la traza es este `fetch`.

import { createTraceparent } from './traceparent'

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
  | 'timeout'
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
    return this.isPortalNetworkRestricted
      ? 'errors.portalNetworkRestricted.title'
      : `errors.${this.kind}.title`
  }

  /** El `403` de «esta red no puede abrir el portal»: tiene su propio texto. */
  get isPortalNetworkRestricted(): boolean {
    return this.status === 403 && this.problem?.type === PORTAL_NETWORK_RESTRICTED_TYPE
  }

  /** Clave de i18n de la accion siguiente. Un error sin salida no es un error util. */
  get adviceKey(): string {
    return this.isPortalNetworkRestricted
      ? 'errors.portalNetworkRestricted.advice'
      : `errors.${this.kind}.advice`
  }
}

export function isApiError(value: unknown): value is ApiError {
  return value instanceof ApiError
}

/**
 * `type` del `403` del portal cuando la red de origen no esta permitida (RF-ID-08, RS-12): el
 * servidor distingue el motivo y el portal solo aconseja «conectate a la red del hotel» con este
 * tipo; un `403` cualquiera lleva el consejo general.
 */
export const PORTAL_NETWORK_RESTRICTED_TYPE = 'urn:kronoqr:problem:portal-network-restricted'

/** `type` del `503` de las exportaciones en PDF cuando hay demasiados PDF simultaneos (con `Retry-After`). */
export const PDF_RENDERING_BUSY_TYPE = 'urn:kronoqr:problem:pdf-rendering-busy'

/**
 * Si el fallo es el `503` «el motor de PDF esta ocupado». Se distingue del motor
 * ausente (`service-unavailable`), que no se arregla esperando: aqui basta
 * reintentar tras `retryAfterSeconds` o llevarse el CSV.
 */
export function isPdfRenderingBusy(error: unknown): boolean {
  return (
    isApiError(error) && error.status === 503 && error.problem?.type === PDF_RENDERING_BUSY_TYPE
  )
}

/** Valor admitido en una cadena de consulta. `undefined` no se serializa. */
export type QueryValue = string | number | boolean | undefined

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

export interface RequestOptions {
  method?: Method
  query?: Readonly<Record<string, QueryValue>>
  body?: unknown
  signal?: AbortSignal
  /**
   * Sin el token de la sesion y sin disparar `unauthenticatedHandler` ante un
   * `401`: es lo que corresponde al propio acceso, donde un `401` significa
   * «credenciales no validas», no «la sesion ha caducado».
   */
  anonymous?: boolean
  /**
   * Cabecera `Authorization` explicita, en vez de la del proveedor de la
   * sesion. Existe para el reto de segundo factor (RS-06): el
   * `challenge_token` de `POST /auth/login` no es la sesion —vive en el
   * estado del propio flujo, nunca en la tienda de sesion— y hay que poder
   * mandarlo sin que un `401` (codigo equivocado) dispare el manejador global
   * de sesion caducada, que asumiria que el token de la SESION ya no vale.
   * Se combina con `anonymous: true` para conseguir las dos cosas a la vez.
   */
  token?: string
  /**
   * Tipos aceptados en la respuesta. Sin valor por omision especifico de un
   * formato: cada llamada dice si espera un PDF, un CSV o solo el problema de
   * error, porque el formato es propio del endpoint, no de este cliente.
   */
  accept?: string
  /**
   * `fetch(..., { keepalive: true })`: la peticion sigue viva aunque la
   * pagina se descargue mientras esta en vuelo. Existe para
   * `clientErrorTransport.ts`, que vacia el buffer de errores en
   * `pagehide`/`visibilitychange: hidden` sin poder esperar a que termine.
   */
  keepalive?: boolean
  /**
   * Tiempo maximo de espera hasta tener la respuesta, en milisegundos; `0` lo
   * desactiva. Por omision, `DEFAULT_READ_TIMEOUT_MS` en las LECTURAS JSON (`GET`) y
   * ninguno en lo demas: una escritura que se corta a los 30 s puede haberse
   * aplicado igualmente, y un documento binario puede tardar legitimamente. Una
   * lectura que se agota falla como `network` («no se ha podido contactar con el
   * servidor… reintenta») en vez de dejar «Cargando…» para siempre.
   */
  timeoutMs?: number
  /**
   * Como leer el cuerpo de una respuesta `2xx` en vez de `response.json()`. Existe para
   * los cuerpos de varios MB (el informe de 20 000 filas): `JSON.parse` de ~12 MB es una
   * unica tarea de cientos de ms que el navegador no puede partir, y quien conoce el
   * endpoint puede leerlo fuera del hilo principal. Lo que devuelva es el resultado de
   * la peticion; el plazo (`timeoutMs`) sigue cubriendo la lectura.
   */
  readBody?: (response: Response) => Promise<unknown>
}

/** Espera maxima por omision de una lectura JSON. */
export const DEFAULT_READ_TIMEOUT_MS = 30_000

let authTokenProvider: () => string | null = () => null
let unauthenticatedHandler: () => void = () => {}
let localeProvider: () => string | null = () => null

/** Lo llama la tienda de sesion al arrancar. Evita el ciclo tienda ↔ cliente. */
export function setAuthTokenProvider(provider: () => string | null): void {
  authTokenProvider = provider
}

/** Que hacer cuando el servidor dice que la sesion ya no vale. */
export function setUnauthenticatedHandler(handler: () => void): void {
  unauthenticatedHandler = handler
}

/**
 * El idioma activo de la SPA, para la cabecera `Accept-Language`.
 *
 * El servidor escribe en ese idioma lo que lee una persona —los mensajes de un
 * `422`, los criterios de un informe— y sin la cabecera respondia en el idioma
 * de la instalacion, fuera cual fuera el del panel. Se lee en cada peticion, no
 * al arrancar, porque el idioma cambia al entrar (es el de la persona, no el
 * del navegador). Los documentos que se descargan no lo siguen: salen en el
 * idioma de la instalacion por decision del servidor.
 */
export function setLocaleProvider(provider: () => string | null): void {
  localeProvider = provider
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

async function send(
  path: string,
  options: RequestOptions,
  accept: string,
  deadline: Deadline | null = null,
): Promise<Response> {
  const headers = new Headers({ Accept: accept, traceparent: createTraceparent() })
  const token = options.token ?? (options.anonymous === true ? null : authTokenProvider())

  if (token !== null) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const locale = localeProvider()

  if (locale !== null && locale !== '') {
    headers.set('Accept-Language', locale)
  }

  // `FormData` (la importacion de plantilla, multipart) NO lleva
  // `Content-Type` explicito: el navegador fija `multipart/form-data` con el
  // `boundary` que genera al construir el cuerpo, y ponerlo a mano aqui lo
  // dejaria sin el, con un cuerpo que el servidor no sabe partir en campos.
  const isMultipart = options.body instanceof FormData

  if (options.body !== undefined && !isMultipart) {
    headers.set('Content-Type', 'application/json')
  }

  let response: Response
  const signal = deadline?.signal ?? options.signal

  try {
    response = await fetch(buildUrl(path, options.query), {
      method: options.method ?? 'GET',
      headers,
      // Ni el PDF de una tarjeta ni el historico de una persona tienen por que
      // quedarse en la cache del navegador.
      cache: 'no-store',
      credentials: 'omit',
      ...(options.body === undefined
        ? {}
        : { body: isMultipart ? (options.body as FormData) : JSON.stringify(options.body) }),
      ...(signal === undefined ? {} : { signal }),
      ...(options.keepalive === true ? { keepalive: true } : {}),
    })
  } catch {
    throw new ApiError({ kind: deadline?.timedOut === true ? 'timeout' : 'network', status: 0 })
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

/**
 * El plazo de una lectura: corta la peticion a los `timeoutMs` y cubre TAMBIEN la lectura del
 * cuerpo, no solo la llegada de las cabeceras (un servidor que envia la mitad y se calla
 * dejaba la pantalla en «Cargando…»). Un controlador propio encadenado al del llamante, y no
 * `AbortSignal.any`: que tambien exista en el navegador de la tablet mas vieja de un cliente.
 */
interface Deadline {
  readonly signal: AbortSignal
  readonly timedOut: boolean
  clear: () => void
}

function startDeadline(options: RequestOptions): Deadline | null {
  const timeoutMs =
    options.timeoutMs ?? ((options.method ?? 'GET') === 'GET' ? DEFAULT_READ_TIMEOUT_MS : 0)

  if (timeoutMs <= 0) {
    return null
  }

  const controller = new AbortController()
  const caller = options.signal
  let timedOut = false
  const onCallerAbort = (): void => controller.abort()
  const timer = setTimeout(() => {
    timedOut = true
    controller.abort()
  }, timeoutMs)

  if (caller?.aborted === true) {
    controller.abort()
  } else {
    caller?.addEventListener('abort', onCallerAbort, { once: true })
  }

  return {
    signal: controller.signal,
    get timedOut() {
      return timedOut
    },
    clear: () => {
      clearTimeout(timer)
      caller?.removeEventListener('abort', onCallerAbort)
    },
  }
}

/** Lee el cuerpo JSON dentro del plazo; si el plazo vence a medias, es un `timeout`. */
async function readJson<T>(
  response: Response,
  deadline: Deadline | null,
  readBody?: (response: Response) => Promise<unknown>,
): Promise<T> {
  // Un `readBody` que cede el cuerpo a otro hilo (un Worker) no se entera del aborto de la
  // peticion: el plazo se hace valer aqui, compitiendo con la lectura, para que cubra de verdad
  // la lectura y no solo las cabeceras.
  let stopWatching = (): void => {}

  try {
    const reading = readBody === undefined ? response.json() : readBody(response)

    if (deadline === null || readBody === undefined) {
      return (await reading) as T
    }

    const expired = new Promise<never>((_, reject) => {
      const onAbort = (): void => {
        // Si fue el plazo, el `catch` de abajo lo convierte en `timeout`; si fue el llamante, queda aborto.
        reject(new DOMException('aborted', 'AbortError'))
      }

      if (deadline.signal.aborted) {
        onAbort()
      } else {
        deadline.signal.addEventListener('abort', onAbort, { once: true })
        stopWatching = () => {
          deadline.signal.removeEventListener('abort', onAbort)
        }
      }
    })

    // La lectura abandonada puede fallar despues: no es un rechazo sin atender.
    reading.catch(() => {})

    return (await Promise.race([reading, expired])) as T
  } catch (failure) {
    if (deadline?.timedOut === true) {
      throw new ApiError({ kind: 'timeout', status: 0 })
    }

    throw failure
  } finally {
    stopWatching()
  }
}

/** Peticion JSON. `204` se representa como `null`. */
export async function request<T>(path: string, options: RequestOptions = {}): Promise<T | null> {
  const deadline = startDeadline(options)

  try {
    const response = await send(path, options, 'application/json', deadline)

    if (response.status === 204) {
      return null
    }

    return await readJson<T>(response, deadline, options.readBody)
  } finally {
    deadline?.clear()
  }
}

/**
 * Peticion JSON que devuelve ademas las cabeceras de la respuesta. Para lo que
 * necesita leer algo de ellas -la hora del servidor (`Date`)- sin pedirselo al
 * reloj del dispositivo.
 */
export async function requestJsonWithHeaders<T>(
  path: string,
  options: RequestOptions = {},
): Promise<{ body: T; headers: Headers }> {
  const deadline = startDeadline(options)

  try {
    const response = await send(path, options, 'application/json', deadline)

    if (response.status === 204) {
      throw new ApiError({ kind: 'unexpected', status: 204 })
    }

    return {
      body: await readJson<T>(response, deadline, options.readBody),
      headers: response.headers,
    }
  } finally {
    deadline?.clear()
  }
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
