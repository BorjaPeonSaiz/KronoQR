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

export interface BatchCall {
  readonly idempotencyKey: string | undefined
  readonly scans: Array<{ scan_id: string; occurred_at: string; intent?: string }>
}

export interface BatchRecorder {
  readonly calls: BatchCall[]
  /** Lo que devuelve el servidor simulado para cada `scan_id`. */
  status: 200 | 422 | 503
}

/**
 * Simula `POST /api/v1/scan/batch`. Devuelve `207` con un resultado por
 * elemento, **en el orden en que llegaron**, para que la prueba pueda afirmar
 * que quien ordena por `occurred_at` es el cliente y no el servidor simulado.
 */
export async function stubBatchApi(
  page: Page,
  status: 200 | 422 | 503 = 200,
): Promise<BatchRecorder> {
  const recorder: BatchRecorder = { calls: [], status }

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
        results: body.scans.map((item) => ({
          scan_id: item.scan_id,
          status: recorder.status,
          outcome:
            recorder.status === 200
              ? {
                  scan_id: item.scan_id,
                  action: 'clock_in',
                  employee_display_name: 'Lucia G.',
                  work_date: item.occurred_at.slice(0, 10),
                  occurred_at: item.occurred_at,
                  recorded_at: new Date().toISOString(),
                  worked_minutes: 0,
                }
              : recorder.status === 422
                ? {
                    type: 'urn:kronoqr:problem:scan-rejected',
                    title: 'Escaneo no valido',
                    status: 422,
                    detail: 'El escaneo no se ha podido registrar.',
                    scan_id: item.scan_id,
                  }
                : {
                    type: 'urn:kronoqr:problem:scan-not-processed',
                    title: 'Escaneo no procesado',
                    status: 503,
                    detail: 'El escaneo no se ha podido procesar. Reintenta mas tarde.',
                    scan_id: item.scan_id,
                  },
        })),
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
