// Utilidades del E2E de la cola offline.
//
// Aqui se abre IndexedDB DESDE FUERA de la aplicacion, con la API cruda del
// navegador. Es deliberado: el criterio de terminado del plan es «verificar la
// cola en IndexedDB», y comprobarlo llamando al propio codigo de la cola no
// probaria que los fichajes estan escritos, solo que la cola se cree a si misma.

import type { Page, Route } from '@playwright/test'

export const QUEUE_DATABASE = 'kronoqr-kiosk'
export const QUEUE_STORE = 'scans'
/** La lista de fichajes descartados pendientes de aviso (RN-22, ADR-047). */
export const DISCARDED_STORE = 'discarded'

export interface QueuedRow {
  readonly scan_id: string
  readonly occurred_at: string
  /** Ausente en las filas de PIN. */
  readonly qr_payload?: string
  /** Ausente en las filas escritas antes de la tarea 1.12: son QR. */
  readonly kind?: 'qr' | 'pin'
  readonly intent: string
  readonly attempts: number
  readonly next_attempt_at: number
}

export interface DiscardedRow {
  readonly scan_id: string
  readonly occurred_at: string
  readonly kind: 'qr' | 'pin'
  readonly http_status: number
  readonly attempts: number
}

/** Todas las filas de un almacen de la base de la cola. `[]` si aun no existe. */
async function readStore<TRow>(page: Page, storeName: string): Promise<TRow[]> {
  return page.evaluate(
    async ([databaseName, store]) => {
      const open = (): Promise<IDBDatabase | null> =>
        new Promise((resolve) => {
          const request = indexedDB.open(databaseName)
          request.onsuccess = () => resolve(request.result)
          request.onerror = () => resolve(null)
          request.onblocked = () => resolve(null)
        })

      const db = await open()
      if (db === null || !db.objectStoreNames.contains(store)) return []

      return new Promise<unknown[]>((resolve) => {
        const transaction = db.transaction(store, 'readonly')
        const request = transaction.objectStore(store).getAll()
        request.onsuccess = () => {
          db.close()
          resolve(request.result as unknown[])
        }
        request.onerror = () => {
          db.close()
          resolve([])
        }
      })
    },
    [QUEUE_DATABASE, storeName] as const,
  ) as Promise<TRow[]>
}

/** Filas de la cola tal y como estan en disco. `[]` si todavia no hay base de datos. */
export async function readQueue(page: Page): Promise<QueuedRow[]> {
  return readStore<QueuedRow>(page, QUEUE_STORE)
}

/** La lista de descartados tal y como esta en disco (RN-22). */
export async function readDiscarded(page: Page): Promise<DiscardedRow[]> {
  return readStore<DiscardedRow>(page, DISCARDED_STORE)
}

/**
 * `true` en cuanto la base de datos y el almacen de la cola existen (el
 * controlador de la cola -Dexie- ya arranco). `seedQueue` da por hecho que el
 * almacen ya existe -su transaccion lanza `NotFoundError` si no-, y esta es
 * la condicion que lo garantiza sin adivinar cuanto tarda el arranque con una
 * espera fija.
 */
export async function queueStoreReady(page: Page): Promise<boolean> {
  return page.evaluate(
    async ([databaseName, storeName]) => {
      const db = await new Promise<IDBDatabase | null>((resolve) => {
        const request = indexedDB.open(databaseName)
        request.onsuccess = () => resolve(request.result)
        request.onerror = () => resolve(null)
        request.onblocked = () => resolve(null)
      })
      if (db === null) return false
      const exists = db.objectStoreNames.contains(storeName)
      db.close()
      return exists
    },
    [QUEUE_DATABASE, QUEUE_STORE] as const,
  )
}

export interface BatchCall {
  readonly idempotencyKey: string | undefined
  /** `Authorization` tal y como llego: lo que prueba con QUE token se sincronizo (RF-ID-04). */
  readonly authorization: string | undefined
  readonly scans: Array<{ scan_id: string; occurred_at: string; intent?: string }>
}

export interface BatchRecorder {
  readonly calls: BatchCall[]
  /**
   * Lo que devuelve el servidor simulado para cada `scan_id`. `'per-scan'`
   * cuando `stubBatchApi` recibio una funcion en vez de un codigo unico (el
   * lote mixto de RN-18): decir aqui `200` seria mentir sobre el `422` que
   * tambien viaja en la misma respuesta.
   */
  status: 200 | 422 | 503 | 'per-scan'
}

/** `outcome` de un elemento aplazado por uno anterior del mismo lote (RN-21, servidor 2.2.0). */
function heldBackOutcome(item: { scan_id: string }): unknown {
  return {
    type: 'urn:kronoqr:problem:scan-held-back',
    title: 'Escaneo aplazado',
    status: 503,
    detail:
      'El escaneo no se ha procesado porque uno anterior del lote sigue pendiente. Reintenta mas tarde.',
    scan_id: item.scan_id,
  }
}

/** Construye el `outcome` de un elemento del `207` para el estado dado. */
function outcomeFor(
  status: 200 | 422 | 503,
  item: { scan_id: string; occurred_at: string },
): unknown {
  if (status === 200) {
    return {
      scan_id: item.scan_id,
      action: 'clock_in',
      employee_display_name: 'Lucia G.',
      work_date: item.occurred_at.slice(0, 10),
      occurred_at: item.occurred_at,
      recorded_at: new Date().toISOString(),
      worked_minutes: 0,
    }
  }
  if (status === 422) {
    return {
      type: 'urn:kronoqr:problem:scan-rejected',
      title: 'Escaneo no valido',
      status: 422,
      detail: 'El escaneo no se ha podido registrar.',
      scan_id: item.scan_id,
    }
  }
  return {
    type: 'urn:kronoqr:problem:scan-not-processed',
    title: 'Escaneo no procesado',
    status: 503,
    detail: 'El escaneo no se ha podido procesar. Reintenta mas tarde.',
    scan_id: item.scan_id,
  }
}

/**
 * Simula `POST /api/v1/scan/batch`. Devuelve `207` con un resultado por
 * elemento, **en el orden en que llegaron**, para que la prueba pueda afirmar
 * que quien ordena por `occurred_at` es el cliente y no el servidor simulado.
 *
 * `status` puede ser un unico codigo para TODO el lote (el caso de siempre) o
 * una funcion `scan_id -> codigo` para el escenario del fichaje irreconciliable
 * (RN-18): un elemento del lote sale en `422` -genuinamente irreconciliable,
 * `ScanRejected` generico- y el resto en `200`, en la MISMA respuesta.
 */
export async function stubBatchApi(
  page: Page,
  status: 200 | 422 | 503 | ((scanId: string) => 200 | 422 | 503) = 200,
): Promise<BatchRecorder> {
  const statusFor = typeof status === 'function' ? status : (): 200 | 422 | 503 => status
  const recorder: BatchRecorder = {
    calls: [],
    status: typeof status === 'function' ? 'per-scan' : status,
  }

  await page.route('**/api/v1/scan/batch', async (route: Route) => {
    const body = route.request().postDataJSON() as {
      scans: Array<{ scan_id: string; occurred_at: string; intent?: string }>
    }
    recorder.calls.push({
      idempotencyKey: route.request().headers()['idempotency-key'],
      authorization: route.request().headers()['authorization'],
      scans: body.scans,
    })

    await route.fulfill({
      status: 207,
      contentType: 'application/json',
      body: JSON.stringify({
        results: body.scans.map((item) => {
          const itemStatus = statusFor(item.scan_id)
          return {
            scan_id: item.scan_id,
            status: itemStatus,
            outcome: outcomeFor(itemStatus, item),
          }
        }),
      }),
    })
  })

  return recorder
}

/**
 * Lo que hace el servidor simulado con una peticion de lote (`POST /scan/batch`):
 * - `abort`: la conexion se corta (WiFi que se va) y no llega ninguna respuesta;
 * - `first-not-processed-legacy`: `207` con el PRIMER elemento `503`
 *   (`scan-not-processed`) y el resto `200`, como un servidor anterior a la 2.2.0
 *   que seguia procesando tras un elemento sin procesar;
 * - `first-not-processed-held-back`: `207` con el primero `503` y el resto `503`
 *   `scan-held-back`, como el servidor 2.2.0 (RN-21);
 * - `ok`: `207` con todos en `200`.
 */
export type BatchStep =
  'abort' | 'first-not-processed-legacy' | 'first-not-processed-held-back' | 'ok'

export type ServerEndpoint = 'scan' | 'pin' | 'batch'

export interface ServerRequest {
  readonly endpoint: ServerEndpoint
  /** `scan_id` en el orden en que viajaron. */
  readonly scanIds: readonly string[]
  /** `occurred_at` en el orden en que viajaron. */
  readonly occurredAts: readonly string[]
}

/**
 * Una peticion que llego mientras un fichaje ANTERIOR (por `occurred_at`) seguia
 * sin decidir —en vuelo, cortado o `503`— y no lo llevaba consigo: es justo lo
 * que RN-21 prohibe.
 */
export interface Overtake {
  readonly request: number
  readonly endpoint: ServerEndpoint
  /** El primer `scan_id` de la peticion que adelanto. */
  readonly sent: string
  /** El fichaje anterior que seguia sin desenlace. */
  readonly overtaken: string
}

export interface HeldScan {
  readonly scanId: string
  readonly occurredAt: string
}

export interface OrderingServer {
  readonly requests: ServerRequest[]
  /** `scan_id` a los que el servidor contesto `200`, en el orden en que los registro. */
  readonly registered: string[]
  readonly overtakes: Overtake[]
  /** Pasos del plan de lote que aun no ha consumido ninguna peticion. */
  stepsLeft(): number
  /** El envio individual retenido (`holdSingleScan`), en cuanto llega. */
  heldScan(): HeldScan | null
  /** Contesta al envio retenido: `200` lo registra, `503` lo deja sin decidir. */
  releaseHeldScan(status: 200 | 503): void
}

export interface OrderingServerOptions {
  /** Un paso por peticion de lote; agotado el plan, `ok`. */
  readonly batchPlan?: readonly BatchStep[]
  /** El primer `POST /scan` se queda sin respuesta hasta `releaseHeldScan`. */
  readonly holdSingleScan?: boolean
}

interface WireScan {
  readonly scan_id: string
  readonly occurred_at: string
}

function scanOk(item: WireScan): unknown {
  return outcomeFor(200, item)
}

/**
 * El servidor de fichaje entero (`/scan`, `/scan/pin`, `/scan/batch`) como un
 * registro: cada `scan_id` queda SIN DECIDIR desde que llega hasta que recibe un
 * `200`/`422`, y cada peticion que llega con un fichaje anterior sin decidir que
 * no viaja en ella se anota en `overtakes`. Es la comprobacion de RN-21 hecha
 * por el lado del servidor, que es donde el orden tiene consecuencias: no cuenta
 * peticiones ni mira el codigo del cliente, mira que se registra y cuando.
 */
export async function stubOrderingServer(
  page: Page,
  options: OrderingServerOptions = {},
): Promise<OrderingServer> {
  const steps = [...(options.batchPlan ?? [])]
  const undecided = new Map<string, string>()
  const requests: ServerRequest[] = []
  const registered: string[] = []
  const overtakes: Overtake[] = []
  let held: HeldScan | null = null
  let release: ((status: 200 | 503) => void) | null = null
  const releasedWith = new Promise<200 | 503>((resolve) => {
    release = resolve
  })

  function arrive(endpoint: ServerEndpoint, scans: readonly WireScan[]): void {
    const index = requests.length
    const travelling = new Set(scans.map((item) => item.scan_id))
    const earliest = [...scans.map((item) => item.occurred_at)].sort()[0] ?? ''
    for (const [scanId, occurredAt] of undecided) {
      if (!travelling.has(scanId) && occurredAt < earliest) {
        overtakes.push({
          request: index,
          endpoint,
          sent: scans[0]?.scan_id ?? '',
          overtaken: scanId,
        })
      }
    }
    for (const item of scans) undecided.set(item.scan_id, item.occurred_at)
    requests.push({
      endpoint,
      scanIds: scans.map((item) => item.scan_id),
      occurredAts: scans.map((item) => item.occurred_at),
    })
  }

  function decide(item: WireScan, status: 200 | 503): void {
    if (status !== 200) return
    undecided.delete(item.scan_id)
    registered.push(item.scan_id)
  }

  async function fulfillSingle(route: Route, item: WireScan, status: 200 | 503): Promise<void> {
    decide(item, status)
    await route.fulfill({
      status,
      contentType: status === 200 ? 'application/json' : 'application/problem+json',
      body: JSON.stringify(status === 200 ? scanOk(item) : outcomeFor(503, item)),
    })
  }

  await page.route('**/api/v1/scan', async (route: Route) => {
    const item = route.request().postDataJSON() as WireScan
    arrive('scan', [item])
    if (options.holdSingleScan === true && held === null) {
      held = { scanId: item.scan_id, occurredAt: item.occurred_at }
      await fulfillSingle(route, item, await releasedWith)
      return
    }
    await fulfillSingle(route, item, 200)
  })

  await page.route('**/api/v1/scan/pin', async (route: Route) => {
    const item = route.request().postDataJSON() as WireScan
    arrive('pin', [item])
    await fulfillSingle(route, item, 200)
  })

  await page.route('**/api/v1/scan/batch', async (route: Route) => {
    const body = route.request().postDataJSON() as { scans: WireScan[] }
    arrive('batch', body.scans)
    const step = steps.shift() ?? 'ok'
    if (step === 'abort') {
      await route.abort('failed')
      return
    }

    const results = body.scans.map((item, index) => {
      if (step === 'ok' || (index > 0 && step === 'first-not-processed-legacy')) {
        decide(item, 200)
        return { scan_id: item.scan_id, status: 200, outcome: scanOk(item) }
      }
      const outcome = index === 0 ? outcomeFor(503, item) : heldBackOutcome(item)
      return { scan_id: item.scan_id, status: 503, outcome }
    })
    await route.fulfill({
      status: 207,
      contentType: 'application/json',
      body: JSON.stringify({ results }),
    })
  })

  return {
    requests,
    registered,
    overtakes,
    stepsLeft: () => steps.length,
    heldScan: () => held,
    releaseHeldScan(status) {
      release?.(status)
    },
  }
}

/** Cuerpo de un `400` de validacion (`ValidationProblem`, RFC 9457). */
function invalidRequestBody(): string {
  return JSON.stringify({
    type: 'urn:kronoqr:problem:invalid-request',
    title: 'Peticion invalida',
    status: 400,
    detail: 'La peticion no cumple el contrato.',
    errors: { device_model: ['Campo no admitido.'] },
  })
}

export interface InvalidScanServer {
  /** Peticiones a `/scan/batch` (todas contestadas `400`). */
  readonly batchCalls: string[][]
  /** `scan_id` enviados uno a uno a `/scan` (todos contestados `400`). */
  readonly singleCalls: string[]
}

/**
 * Un servidor que ya no entiende a esta PWA: `400` con cuerpo JSON a todo envio
 * de fichaje, por lote y uno a uno. Es el caso de R4-SC-03: la tablet vieja
 * vaciando su cola contra una API nueva tras una actualizacion.
 */
export async function stubInvalidScanApi(page: Page): Promise<InvalidScanServer> {
  const server: InvalidScanServer = { batchCalls: [], singleCalls: [] }

  await page.route('**/api/v1/scan/batch', async (route: Route) => {
    const body = route.request().postDataJSON() as { scans: WireScan[] }
    server.batchCalls.push(body.scans.map((item) => item.scan_id))
    await route.fulfill({
      status: 400,
      contentType: 'application/problem+json',
      body: invalidRequestBody(),
    })
  })

  await page.route('**/api/v1/scan', async (route: Route) => {
    const body = route.request().postDataJSON() as WireScan
    server.singleCalls.push(body.scan_id)
    await route.fulfill({
      status: 400,
      contentType: 'application/problem+json',
      body: invalidRequestBody(),
    })
  })

  return server
}

export interface DiscardReport {
  readonly scan_id: string
  readonly occurred_at: string
  readonly kind: 'qr' | 'pin'
  readonly http_status: number
}

export interface DiscardReportServer {
  /** Cada peticion de aviso, con sus avisos en el orden en que viajaron. */
  readonly calls: DiscardReport[][]
  /** `scan_id` devueltos en `acknowledged`, en orden. */
  readonly acknowledged: string[]
}

/**
 * `POST /api/v1/scan/discarded` (RN-22). Acusa todo lo que recibe, salvo que
 * `acknowledgeAtMost` ponga un tope al TOTAL de acuses: un servidor que solo
 * llega a guardar parte de los avisos.
 */
export async function stubDiscardedScanReports(
  page: Page,
  options: { readonly acknowledgeAtMost?: number } = {},
): Promise<DiscardReportServer> {
  const server: DiscardReportServer = { calls: [], acknowledged: [] }

  await page.route('**/api/v1/scan/discarded', async (route: Route) => {
    const body = route.request().postDataJSON() as { reports: DiscardReport[] }
    server.calls.push(body.reports)
    const budget =
      (options.acknowledgeAtMost ?? Number.POSITIVE_INFINITY) - server.acknowledged.length
    const acknowledged = body.reports.slice(0, Math.max(0, budget)).map((report) => report.scan_id)
    server.acknowledged.push(...acknowledged)
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ acknowledged }),
    })
  })

  return server
}

interface SeededRowBase {
  readonly scan_id: string
  readonly occurred_at: string
  /** Epoch ms del proximo intento. Por defecto `0`: elegible ya. */
  readonly next_attempt_at?: number
  /** Envios ya fallidos. Por defecto `0`. Gobierna el retroceso del siguiente fallo. */
  readonly attempts?: number
}

/** Sin `kind`, la fila es como las de antes de la tarea 1.12: QR. */
export interface SeededQrRow extends SeededRowBase {
  readonly kind?: 'qr'
  readonly qr_payload: string
}

export interface SeededPinRow extends SeededRowBase {
  readonly kind: 'pin'
  readonly employee_code: string
  readonly pin_sealed: string
}

export type SeededRow = SeededQrRow | SeededPinRow

/** Escribe filas en la cola por debajo de la aplicacion, para probar un estado concreto. */
export async function seedQueue(page: Page, rows: readonly SeededRow[]): Promise<void> {
  await page.evaluate(
    async ([databaseName, storeName, seeded]) => {
      const db = await new Promise<IDBDatabase>((resolve, reject) => {
        const request = indexedDB.open(databaseName)
        request.onsuccess = () => resolve(request.result)
        request.onerror = () => reject(request.error)
      })

      await new Promise<void>((resolve, reject) => {
        const transaction = db.transaction(storeName, 'readwrite')
        const store = transaction.objectStore(storeName)
        for (const row of seeded) {
          store.put({
            intent: 'auto',
            device_id: 'e2e',
            attempts: 0,
            next_attempt_at: 0,
            enqueued_at: Date.now(),
            ...row,
          })
        }
        transaction.oncomplete = () => resolve()
        transaction.onerror = () => reject(transaction.error)
      })
      db.close()
    },
    [QUEUE_DATABASE, QUEUE_STORE, rows] as const,
  )
}

interface OnlineSwitch {
  __kqSetOnline(value: boolean): void
}

/**
 * `navigator.onLine` bajo control de la prueba, como lo ve la aplicacion: es lo
 * que leen el cliente HTTP y el drenaje. No se usa `context.setOffline` porque
 * tambien cortaria la carga diferida del decodificador de QR, que en la tablet
 * real ya esta precacheada por el service worker.
 */
export async function controlNavigatorOnline(page: Page, online: boolean): Promise<void> {
  await page.addInitScript((initial: boolean) => {
    let current = initial
    Object.defineProperty(navigator, 'onLine', { get: () => current, configurable: true })
    ;(window as unknown as OnlineSwitch).__kqSetOnline = (value) => {
      current = value
    }
  }, online)
}

/**
 * La red vuelve como la ve la aplicacion en una tablet de verdad:
 * `navigator.onLine` pasa a `true` y el navegador emite `online`. Requiere
 * `controlNavigatorOnline`.
 */
export async function reconnect(page: Page): Promise<void> {
  await page.evaluate(() => {
    ;(window as unknown as OnlineSwitch).__kqSetOnline(true)
    window.dispatchEvent(new Event('online'))
  })
}

/**
 * La camara no arranca nunca (`getUserMedia` no resuelve): para las pruebas que
 * siembran la cola entera y no quieren que un escaneo de la camara —que ademas
 * despierta el drenaje— se cuele en mitad del escenario.
 */
export async function blockCamera(page: Page): Promise<void> {
  await page.addInitScript(() => {
    navigator.mediaDevices.getUserMedia = () => new Promise<MediaStream>(() => undefined)
  })
}

interface QueueReadCounter {
  __kqQueueListReads?: number
}

/**
 * Cuenta las LECTURAS de la cola que hace la aplicacion: cada `list()` de la cola
 * (en `claim()`, en el recuento, en el aplazamiento) lee el almacen `scans` por su
 * indice `occurred_at`. Las utilidades de esta prueba leen el almacen sin indice,
 * asi que no cuentan. Es la medida de G2: un drenaje que gira en vacio no hace
 * ninguna peticion, pero lee la cola sin parar.
 */
export async function countQueueListReads(page: Page): Promise<void> {
  await page.addInitScript((storeName: string) => {
    const counter = window as unknown as QueueReadCounter
    counter.__kqQueueListReads = 0
    const prototype = IDBIndex.prototype as unknown as Record<string, unknown>
    for (const method of ['getAll', 'getAllKeys', 'openCursor', 'openKeyCursor']) {
      const original = prototype[method] as (this: IDBIndex, ...args: unknown[]) => unknown
      prototype[method] = function (this: IDBIndex, ...args: unknown[]): unknown {
        if (this.objectStore.name === storeName) {
          counter.__kqQueueListReads = (counter.__kqQueueListReads ?? 0) + 1
        }
        return original.apply(this, args)
      }
    }
  }, QUEUE_STORE)
}

/** Lecturas de la cola desde que cargo la pagina. Requiere `countQueueListReads`. */
export async function queueListReads(page: Page): Promise<number> {
  return page.evaluate(() => (window as unknown as QueueReadCounter).__kqQueueListReads ?? 0)
}

/**
 * Ventana de observacion: espera a que el reloj de la PAGINA avance `ms`. Es la
 * unica forma de afirmar una AUSENCIA (que algo no ocurre durante un tiempo);
 * con `page.clock` instalado avanza al ritmo del reloj real.
 */
export async function pageTimePasses(page: Page, ms: number): Promise<void> {
  const until = (await page.evaluate(() => performance.now())) + ms
  await page.waitForFunction((deadline) => performance.now() >= deadline, until, { polling: 50 })
}

/** Despierta al drenaje como lo haria el navegador al recuperar la red. */
export async function announceOnline(page: Page): Promise<void> {
  await page.evaluate(() => window.dispatchEvent(new Event('online')))
}

export interface HeartbeatQueueCall {
  readonly pendingQueueSize: number | null
  readonly oldestPendingAt: string | undefined
  /** `unreported_discards` (RN-22): ausente = 0. */
  readonly unreportedDiscards: number | undefined
}

export interface HeartbeatQueueRecorder {
  readonly calls: HeartbeatQueueCall[]
}

/**
 * Intercepta `POST /api/v1/kiosk/heartbeat` SOLO para leer lo que declara de
 * la cola (`pending_queue_size`/`oldest_pending_at`, RF-KI-04; `unreported_discards`, RN-22) — no el canal de
 * errores de cliente, que ya tiene su propio doble en `support/kiosk.ts`
 * (`stubHeartbeatWithErrorCapture`). Llamar SIEMPRE despues de `stubKioskApi`
 * (Playwright ejecuta el manejador de ruta mas reciente).
 */
export async function stubHeartbeatQueueCapture(page: Page): Promise<HeartbeatQueueRecorder> {
  const recorder: HeartbeatQueueRecorder = { calls: [] }

  await page.route('**/api/v1/kiosk/heartbeat', async (route: Route) => {
    const body = route.request().postDataJSON() as {
      pending_queue_size: number | null
      oldest_pending_at?: string
      unreported_discards?: number
    }
    recorder.calls.push({
      pendingQueueSize: body.pending_queue_size,
      oldestPendingAt: body.oldest_pending_at,
      unreportedDiscards: body.unreported_discards,
    })

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        server_time: new Date().toISOString(),
        client_errors_accepted: 0,
        service_code_hash: null,
        break_clocking_enabled: false,
        clock_skew_tolerance_seconds: 900,
      }),
    })
  })

  return recorder
}
