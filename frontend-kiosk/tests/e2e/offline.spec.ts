// CICLO OFFLINE COMPLETO (RQ-05). Es el criterio de terminado de la tarea 1.9.
//
// Escenario del doc 01 §11:
//
//   Dado un quiosco sin conexion a internet
//   Cuando un empleado ficha a las 08:00
//   Entonces el quiosco confirma el fichaje localmente
//   Y encola el evento con su scan_id y occurred_at 08:00
//   Cuando se recupera la conexion a las 09:30
//   Entonces el evento se sincroniza con occurred_at 08:00 y recorded_at 09:30
//
// Aqui se comprueba la mitad de cliente: que la cola existe EN INDEXEDDB, que
// el `occurred_at` que viaja al reconectar es el del escaneo y no el de la
// llegada, y que nada se borra sin que el servidor lo confirme.

import { expect, test } from '@playwright/test'
import { FIXTURE_PAYLOAD, delayCameraStart, stubKioskApi } from './support/kiosk'
import {
  announceOnline,
  queueStoreReady,
  readQueue,
  seedQueue,
  stubBatchApi,
  stubHeartbeatQueueCapture,
} from './support/offlineQueue'

test.beforeEach(async ({ page }) => {
  await stubKioskApi(page)
})

test(
  'ficha sin red, encola en IndexedDB y consolida con el `occurred_at` original',
  { tag: ['@RF-KI-03', '@RF-KI-04', '@RQ-05'] },
  async ({ page }) => {
    // 1. Sin servidor: el envio individual no llega a ninguna parte.
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    const batch = await stubBatchApi(page)

    await page.goto('/')

    // 2. La confirmacion es LOCAL y honesta: ni entrada ni salida, «pendiente».
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')
    await expect(page.getByTestId('confirmation-pending-badge')).toBeVisible()

    // 3. El fichaje esta escrito en IndexedDB, con su `scan_id` y su hora real.
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)
    const queued = await readQueue(page)
    const first = queued[0]

    expect(first?.qr_payload).toBe(FIXTURE_PAYLOAD)
    expect(first?.intent).toBe('auto')
    expect(first?.scan_id).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    )
    const occurredAt = first?.occurred_at ?? ''
    expect(Number.isNaN(Date.parse(occurredAt))).toBe(false)

    // 4. Vuelve la red.
    await page.unroute('**/api/v1/scan')
    await announceOnline(page)

    // 5. Se sincroniza por lote, con el `occurred_at` del escaneo.
    await expect.poll(() => batch.calls.length).toBeGreaterThan(0)
    const sent = batch.calls[0]?.scans.find((item) => item.scan_id === first?.scan_id)
    expect(sent?.occurred_at).toBe(occurredAt)

    // 6. Y solo AHORA desaparece de la cola: tras confirmacion del servidor.
    await expect
      .poll(async () => (await readQueue(page)).some((row) => row.scan_id === first?.scan_id))
      .toBe(false)
  },
)

test(
  'un lote desordenado se envia ordenado por `occurred_at`',
  { tag: ['@RF-KI-03', '@RQ-05'] },
  async ({ page }) => {
    // La entrada y la salida de una jornada entera atrapadas sin red. Si se
    // enviaran del reves, el servidor veria una salida sin turno abierto.
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    const batch = await stubBatchApi(page)

    await page.goto('/')
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)

    await seedQueue(page, [
      {
        scan_id: '0199f13a-7c22-7b41-9e88-0c4d5e6f7a81',
        occurred_at: '2026-08-14T14:03:12.000Z',
        qr_payload: FIXTURE_PAYLOAD,
      },
      {
        scan_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        occurred_at: '2026-08-14T05:58:31.000Z',
        qr_payload: FIXTURE_PAYLOAD,
      },
    ])

    await page.unroute('**/api/v1/scan')
    await announceOnline(page)

    await expect.poll(() => batch.calls.length).toBeGreaterThan(0)
    const sent = batch.calls[0]?.scans.map((item) => item.occurred_at) ?? []
    const chronological = [...sent].sort()
    expect(sent).toEqual(chronological)
    expect(sent[0]).toBe('2026-08-14T05:58:31.000Z')

    // La clave del lote es propia, no un `scan_id` reciclado.
    expect(batch.calls[0]?.idempotencyKey).not.toBe('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
    expect(batch.calls[0]?.idempotencyKey).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    )
  },
)

test(
  'un `503` elemento a elemento NO borra el fichaje de la cola',
  { tag: ['@RF-KI-03', '@RQ-05'] },
  async ({ page }) => {
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    const batch = await stubBatchApi(page, 503)

    await page.goto('/')
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)
    const before = await readQueue(page)

    await page.unroute('**/api/v1/scan')
    await announceOnline(page)

    await expect.poll(() => batch.calls.length).toBeGreaterThan(0)

    // El servidor no decidio nada sobre este escaneo: sigue en disco.
    const after = await readQueue(page)
    expect(after.some((row) => row.scan_id === before[0]?.scan_id)).toBe(true)
  },
)

test(
  'un fichaje que jamas podra cuadrar sale de la cola con el `422` del lote, y el resto se consolida (RN-18)',
  { tag: ['@RN-18', '@RF-KI-04', '@RF-KI-03'] },
  async ({ page }) => {
    // El quiosco es SIEMPRE ajeno a por que el servidor rechaza un elemento
    // (RS-03, regla dura 17): un fichaje «irreconciliable» (RN-18, occurred_at
    // anterior al tramo abierto) no se distingue en el cliente de cualquier
    // otro `422` -mismo cuerpo generico `ScanRejected`-.
    //
    // ORDEN CRITICO (asi fallo en la CI real, no solo en teoria): el imposible
    // se siembra ANTES de que la camara -retrasada con `delayCameraStart`-
    // decodifique nada, y se espera por LECTURA DE DISCO (no por tiempo) a
    // que los DOS fichajes esten en la cola antes de reconectar. La version
    // anterior sembraba el imposible DESPUES de ver el escaneo de la camara
    // en la cola y confiaba el margen a `FIRST_RETRY_DELAY_MS` (1 s): en un
    // runner lento el primer reintento automatico de la camara podia ganarle
    // la mano al sembrado desde Node y partir el lote en dos peticiones
    // (`consolidatedIn.scans.length` salio `1` en la CI de la PR #69).
    const IRRECONCILABLE_SCAN_ID = '0199f300-8a11-7c42-9f01-abcdef123456'

    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    // Tambien se aborta `/scan/batch` desde el principio, no solo `/scan`
    // (revision de la tarea 3.7): el navegador nunca se marca offline de
    // verdad en esta prueba, asi que `syncRunner` podria reintentar en
    // segundo plano por su propio retroceso ANTES del `announceOnline` de
    // mas abajo y drenar el PRIMER fichaje (el de la camara) el solo -la
    // misma familia de carrera que el parrafo de arriba, por el lado del
    // lote en vez de por el de la siembra-. Un fallo de transporte YA deja
    // la cola intacta («se reintenta», ver la prueba de arriba): no hace
    // falta un `503` a medida, es la MISMA instruccion que ya usa `/scan`
    // en la linea de encima.
    await page.route('**/api/v1/scan/batch', async (route) => route.abort('failed'))
    await delayCameraStart(page, 1_500)
    // Registrado ANTES de sembrar nada: `stubKioskApi` (del `beforeEach`) ya
    // dejo un latido que contesta bien, este solo cambia lo que INTERCEPTA.
    const heartbeatQueue = await stubHeartbeatQueueCapture(page)

    await page.goto('/')

    // El almacen de la cola (Dexie) existe en cuanto la app arranca, ANTES de
    // que la camara -retrasada 1,5 s- decodifique nada: se siembra en cuanto
    // se puede escribir, condicion comprobada por lectura, no por reloj.
    await expect.poll(() => queueStoreReady(page)).toBe(true)
    await seedQueue(page, [
      {
        scan_id: IRRECONCILABLE_SCAN_ID,
        occurred_at: '2026-08-01T04:00:00.000Z',
        qr_payload: FIXTURE_PAYLOAD,
      },
    ])
    await expect.poll(async () => (await readQueue(page)).length).toBe(1)

    // La camara, retrasada, decodifica DESPUES: cuando lo haga, se une al
    // imposible en la MISMA cola. Se espera a verlo en disco, no un plazo.
    await expect.poll(async () => (await readQueue(page)).length).toBe(2)
    const beforeReconnect = await readQueue(page)
    const cameraScanId = beforeReconnect.find(
      (row) => row.scan_id !== IRRECONCILABLE_SCAN_ID,
    )?.scan_id
    expect(cameraScanId).toBeDefined()

    // Los DOS fichajes estan en disco antes de reconectar (RQ-05): ahora se
    // levanta la red y se anuncia. El drenaje reclama la cola entera de una
    // vez (`ignoreSchedule: true` en el primer `claim()` de la pasada,
    // `syncRunner.ts`), asi que el `422` del imposible y el `200` del otro
    // viajan, en consecuencia de ESTE orden -no de una coincidencia de
    // tiempos-, en el mismo `207`.
    await page.unroute('**/api/v1/scan')
    await page.unroute('**/api/v1/scan/batch')
    const batch = await stubBatchApi(page, (scanId) =>
      scanId === IRRECONCILABLE_SCAN_ID ? 422 : 200,
    )
    await announceOnline(page)

    // Lo que RN-18 exige, y solo eso: la cola queda a cero.
    await expect.poll(async () => (await readQueue(page)).length).toBe(0)

    // El imposible SE ENVIO (recibio su `422` genuino, no un silencio) y el
    // otro tambien: ninguno de los dos se perdio por el camino.
    const sentScanIds = batch.calls.flatMap((call) => call.scans.map((scan) => scan.scan_id))
    expect(sentScanIds).toContain(IRRECONCILABLE_SCAN_ID)
    expect(sentScanIds).toContain(cameraScanId)

    // Bonus, no el requisito: como los dos estaban en disco ANTES de
    // reconectar, viajaron en el MISMO lote (`207` mixto: `422` + `200`).
    const callWithBoth = batch.calls.find(
      (call) =>
        call.scans.some((scan) => scan.scan_id === IRRECONCILABLE_SCAN_ID) &&
        call.scans.some((scan) => scan.scan_id === cameraScanId),
    )
    expect(callWithBoth).toBeDefined()

    // Nunca se reintenta: el `422` YA es un desenlace (regla dura 8, al reves
    // de un `503`, que si se conserva -ver la prueba de arriba-). La ausencia
    // no se prueba con un plazo fijo, sino con la MISMA condicion que ya hace
    // falta para lo siguiente: el latido posterior a un reinicio de la
    // tablet. Si algo se hubiera reintentado tras el `422`, `batch.calls.length`
    // habria crecido ANTES de que ese latido llegara.
    const callsAfterConsolidation = batch.calls.length

    // El latido siguiente -tras un reinicio de la tablet, la misma condicion
    // de supervivencia que el resto de este fichero- declara la cola tal y
    // como quedo: cero pendientes, sin `oldest_pending_at` (ausente, no nulo:
    // `buildHeartbeatBody` en `heartbeat.ts`).
    const heartbeatsBeforeReload = heartbeatQueue.calls.length
    await page.reload()
    await expect.poll(() => heartbeatQueue.calls.length).toBeGreaterThan(heartbeatsBeforeReload)
    const nextHeartbeat = heartbeatQueue.calls[heartbeatQueue.calls.length - 1]
    expect(nextHeartbeat?.pendingQueueSize).toBe(0)
    expect(nextHeartbeat?.oldestPendingAt).toBeUndefined()

    // Y con la tablet ya reiniciada -tiempo real transcurrido de sobra para
    // cualquier reintento que hubiera quedado programado-, el lote sigue en
    // el mismo numero de llamadas: la no-repeticion determinista (fijada en
    // `tests/unit/syncRunner.spec.ts`, `scheduleNext()` con la cola a `0`) se
    // sostiene tambien de negro.
    expect(batch.calls.length).toBe(callsAfterConsolidation)
  },
)

test(
  'el indicador dice cuantos fichajes quedan pendientes',
  { tag: ['@RF-KI-04'] },
  async ({ page }) => {
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    await stubBatchApi(page)

    await page.goto('/')

    const badge = page.getByTestId('connection-status')
    await expect(badge).toBeVisible()
    await expect
      .poll(async () => Number(await badge.getAttribute('data-pending')))
      .toBeGreaterThan(0)
    await expect(badge).toContainText('pendiente')
  },
)

test(
  'la cola sobrevive a un reinicio de la tablet',
  { tag: ['@RF-KI-03', '@RQ-05'] },
  async ({ page }) => {
    // Nada de lo que hay en memoria cuenta: lo que vale es lo que quedo escrito.
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    await page.route('**/api/v1/scan/batch', async (route) => route.abort('failed'))

    await page.goto('/')
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)
    const before = await readQueue(page)

    // Reinicio: se recarga la aplicacion entera.
    await page.reload()

    const after = await readQueue(page)
    expect(after.some((row) => row.scan_id === before[0]?.scan_id)).toBe(true)
  },
)

test(
  'un break_start armado y encolado sin red llega a la cola con esa intencion (ADR-024, tarea 3.5)',
  { tag: ['@RF-AT-12', '@RF-KI-03', '@RQ-05'] },
  async ({ page }) => {
    // El boton necesita el ajuste activado, y armarlo tiene que ganarle la
    // carrera a la camara simulada (ver `support/kiosk.ts`).
    await delayCameraStart(page, 1_500)
    await stubKioskApi(page, { breakClockingEnabled: true })
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    const batch = await stubBatchApi(page)

    await page.goto('/')

    const toggle = page.getByTestId('break-toggle')
    await expect(toggle).toBeVisible()
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-pressed', 'true')

    // Se encola sin red, con `intent: 'break_start'` ya escrito -no `'auto'`-.
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)
    const queued = await readQueue(page)
    expect(queued[0]?.intent).toBe('break_start')

    // Y sobrevive a la sincronizacion por lote, horas despues: el servidor lo
    // recibe con la misma intencion (`ADR-024`: «se reenvia en cada reintento»).
    await page.unroute('**/api/v1/scan')
    await announceOnline(page)

    await expect.poll(() => batch.calls.length).toBeGreaterThan(0)
    const sent = batch.calls[0]?.scans.find((item) => item.scan_id === queued[0]?.scan_id)
    expect(sent?.intent).toBe('break_start')
  },
)
