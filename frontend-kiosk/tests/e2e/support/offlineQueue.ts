// Utilidades del E2E de la cola offline.
//
// Aqui se abre IndexedDB DESDE FUERA de la aplicacion, con la API cruda del
// navegador. Es deliberado: el criterio de terminado del plan es «verificar la
// cola en IndexedDB», y comprobarlo llamando al propio codigo de la cola no
// probaria que los fichajes estan escritos, solo que la cola se cree a si misma.

import type { Page, Route } from '@playwright/test'

export const QUEUE_DATABASE = 'kronoqr-kiosk'
export const QUEUE_STORE = 'scans'

export interface QueuedRow {
  readonly scan_id: string
  readonly occurred_at: string
  readonly qr_payload: string
  readonly intent: string
  readonly attempts: number
}

/** Filas de la cola tal y como estan en disco. `[]` si todavia no hay base de datos. */
export async function readQueue(page: Page): Promise<QueuedRow[]> {
  return page.evaluate(
    async ([databaseName, storeName]) => {
      const open = (): Promise<IDBDatabase | null> =>
        new Promise((resolve) => {
          const request = indexedDB.open(databaseName)
          request.onsuccess = () => resolve(request.result)
          request.onerror = () => resolve(null)
          request.onblocked = () => resolve(null)
        })

      const db = await open()
      if (db === null || !db.objectStoreNames.contains(storeName)) return []

      return new Promise<QueuedRow[]>((resolve) => {
        const transaction = db.transaction(storeName, 'readonly')
        const request = transaction.objectStore(storeName).getAll()
        request.onsuccess = () => resolve(request.result as QueuedRow[])
        request.onerror = () => resolve([])
      })
    },
    [QUEUE_DATABASE, QUEUE_STORE] as const,
  )
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

/** Siembra la cola antes de cargar la aplicacion, para probar un orden concreto. */
export async function seedQueue(
  page: Page,
  rows: ReadonlyArray<{ scan_id: string; occurred_at: string; qr_payload: string }>,
): Promise<void> {
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
            ...row,
            intent: 'auto',
            device_id: 'e2e',
            attempts: 0,
            next_attempt_at: 0,
            enqueued_at: Date.now(),
          })
        }
        transaction.oncomplete = () => resolve()
        transaction.onerror = () => reject(transaction.error)
      })
    },
    [QUEUE_DATABASE, QUEUE_STORE, rows] as const,
  )
}

/** Despierta al drenaje como lo haria el navegador al recuperar la red. */
export async function announceOnline(page: Page): Promise<void> {
  await page.evaluate(() => window.dispatchEvent(new Event('online')))
}

export interface HeartbeatQueueCall {
  readonly pendingQueueSize: number
  readonly oldestPendingAt: string | undefined
}

export interface HeartbeatQueueRecorder {
  readonly calls: HeartbeatQueueCall[]
}

/**
 * Intercepta `POST /api/v1/kiosk/heartbeat` SOLO para leer lo que declara de
 * la cola (`pending_queue_size`/`oldest_pending_at`, RF-KI-04) — no el canal de
 * errores de cliente, que ya tiene su propio doble en `support/kiosk.ts`
 * (`stubHeartbeatWithErrorCapture`). Llamar SIEMPRE despues de `stubKioskApi`
 * (Playwright ejecuta el manejador de ruta mas reciente).
 */
export async function stubHeartbeatQueueCapture(page: Page): Promise<HeartbeatQueueRecorder> {
  const recorder: HeartbeatQueueRecorder = { calls: [] }

  await page.route('**/api/v1/kiosk/heartbeat', async (route: Route) => {
    const body = route.request().postDataJSON() as {
      pending_queue_size: number
      oldest_pending_at?: string
    }
    recorder.calls.push({
      pendingQueueSize: body.pending_queue_size,
      oldestPendingAt: body.oldest_pending_at,
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
