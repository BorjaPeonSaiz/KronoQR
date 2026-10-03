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

import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { FIXTURE_PAYLOAD, delayCameraStart, stubKioskApi } from './support/kiosk'
import type { BatchStep, QueuedRow, SeededRow, ServerRequest } from './support/offlineQueue'
import {
  announceOnline,
  blockCamera,
  controlNavigatorOnline,
  countQueueListReads,
  pageTimePasses,
  queueListReads,
  queueStoreReady,
  readDiscarded,
  readQueue,
  reconnect,
  seedQueue,
  stubBatchApi,
  stubDiscardedScanReports,
  stubHeartbeatQueueCapture,
  stubInvalidScanApi,
  stubOrderingServer,
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

// ---------------------------------------------------------------------------
// EL PEOR ESCENARIO (RN-21, G1, G2, G3) — R3-QA-01
//
// La version anterior de esta prueba no podia fallar: la reconexion drenaba con
// `ignoreSchedule` (el `claim()` de la 2.1.0 tambien sacaba todo en orden), el
// primer corte dejaba las 40 filas con el mismo retroceso, el doble del lote
// fallaba la SEGUNDA mitad (el orden se conservaba por construccion) y G2 se
// medía en peticiones, que un giro en vacio no hace. Esta version:
// - deja la cabeza con 8 fallos previos: un corte mas la aplaza 256 s y a las
//   demas 1 s, y el sondeo de 30 s del drenaje pasa ENTRE medias;
// - falla el PRIMER elemento del lote, con un servidor anterior a la 2.2.0
//   (los demas `200`) y con el 2.2.0 (los demas `scan-held-back`);
// - comprueba el orden en el servidor (`overtakes`): ninguna peticion llega
//   mientras un fichaje anterior sigue sin decidir y no viaja en ella;
// - mide G2 en lecturas de la cola, no en peticiones.
// ---------------------------------------------------------------------------

const worstCaseScanId = (index: number): string =>
  `0199f400-0000-7000-8000-${String(index).padStart(12, '0')}`

const worstCaseMinute = (index: number): string =>
  new Date(Date.UTC(2026, 7, 14, 5, index, 0)).toISOString()

const worstCaseRange = (from: number, to: number): number[] =>
  Array.from({ length: to - from + 1 }, (_, offset) => from + offset)

const worstCaseQr = (index: number, attempts = 0): SeededRow => ({
  kind: 'qr',
  scan_id: worstCaseScanId(index),
  occurred_at: worstCaseMinute(index),
  qr_payload: FIXTURE_PAYLOAD,
  attempts,
})

const worstCasePin = (index: number): SeededRow => ({
  kind: 'pin',
  scan_id: worstCaseScanId(index),
  occurred_at: worstCaseMinute(index),
  employee_code: '1042',
  pin_sealed: 'c29icmUtc2VsbGFkby1lMmU=',
})

const WORST_CASE_HEAD = worstCaseScanId(0)

/**
 * 40 fichajes de una manana sin red (05:00 a 05:39 UTC). La cabeza ya fallo 8
 * veces; del 1 al 19 por tarjeta, del 20 al 29 por PIN y del 30 al 39 por
 * tarjeta: tres tramos, y lo que va detras de un tramo atascado no debe salir.
 */
const WORST_CASE_QUEUE: readonly SeededRow[] = [
  worstCaseQr(0, 8),
  ...worstCaseRange(1, 19).map((index) => worstCaseQr(index)),
  ...worstCaseRange(20, 29).map(worstCasePin),
  ...worstCaseRange(30, 39).map((index) => worstCaseQr(index)),
]

const WORST_CASE_SCAN_IDS = WORST_CASE_QUEUE.map((row) => row.scan_id)

const WORST_CASE_SERVERS = [
  {
    name: 'servidor anterior a la 2.2.0: el primero 503 y los demas 200',
    step: 'first-not-processed-legacy',
  },
  {
    name: 'servidor 2.2.0: el primero 503 y los demas aplazados',
    step: 'first-not-processed-held-back',
  },
] as const satisfies ReadonlyArray<{ name: string; step: BatchStep }>

async function attemptsOf(page: Page, scanId: string): Promise<number | undefined> {
  return (await readQueue(page)).find((row) => row.scan_id === scanId)?.attempts
}

/** Los distintos recuentos de intentos de todo lo que va detras de la cabeza. */
async function attemptsBehindHead(page: Page): Promise<number[]> {
  const rows = (await readQueue(page)).filter((row) => row.scan_id !== WORST_CASE_HEAD)
  return [...new Set(rows.map((row) => row.attempts))].sort()
}

function isChronological(request: ServerRequest): boolean {
  return request.occurredAts.every(
    (occurredAt, index) => index === 0 || (request.occurredAts[index - 1] ?? '') <= occurredAt,
  )
}

for (const server of WORST_CASE_SERVERS) {
  test(
    `el peor escenario: 40 encolados, la red va y viene y el lote falla en el primero; nada adelanta al que sigue sin decidir, nada se pierde ni se duplica (${server.name})`,
    { tag: ['@RN-21', '@RF-KI-03', '@RF-KI-04', '@RF-AT-07', '@RQ-05'] },
    async ({ page }) => {
      test.setTimeout(90_000)
      await page.clock.install()
      await controlNavigatorOnline(page, false)
      await blockCamera(page)
      await countQueueListReads(page)
      const backend = await stubOrderingServer(page, { batchPlan: ['abort', server.step] })

      // La tablet se reinicia sin red y con los 40 en la cola.
      await page.goto('/')
      await expect.poll(() => queueStoreReady(page)).toBe(true)
      await seedQueue(page, WORST_CASE_QUEUE)
      await page.reload()
      await expect(page.getByTestId('connection-status')).toHaveAttribute('data-pending', '40')

      // G2: sin red, la cola se lee al arrancar y despues nadie la vuelve a leer
      // hasta el sondeo de 30 s. Un drenaje que gira en vacio la leeria cientos
      // de veces en este segundo sin hacer ni una peticion.
      await expect.poll(() => queueListReads(page)).toBeGreaterThanOrEqual(2)
      const readsWhileOffline = await queueListReads(page)
      await pageTimePasses(page, 1_000)
      expect(await queueListReads(page)).toBeLessThanOrEqual(readsWhileOffline + 1)
      expect(backend.requests).toEqual([])

      // Vuelve la red y el WiFi se cae con el primer lote en el aire: la cabeza
      // queda aplazada 256 s y todo lo demas 1 s.
      await reconnect(page)
      await expect.poll(() => attemptsOf(page, WORST_CASE_HEAD)).toBe(9)
      await expect.poll(() => attemptsBehindHead(page)).toEqual([1])

      // Pasan 240 s: lo de detras ya podria salir, la cabeza no. Los sondeos
      // del drenaje no pueden sacar nada.
      await page.clock.fastForward(120_000)
      const readsBeforeSecondPoll = await queueListReads(page)
      await page.clock.fastForward(120_000)
      await expect.poll(() => queueListReads(page)).toBeGreaterThan(readsBeforeSecondPoll)

      // Vence el retroceso de la cabeza: el lote sale y el servidor falla el PRIMERO.
      await page.clock.fastForward(60_000)
      await expect.poll(() => backend.stepsLeft()).toBe(0)
      expect(backend.overtakes).toEqual([])
      await expect.poll(() => attemptsOf(page, WORST_CASE_HEAD)).toBe(10)

      // Pasa su nuevo retroceso (300 s) y se vacia todo.
      await page.clock.fastForward(301_000)
      await expect.poll(async () => (await readQueue(page)).length).toBe(0)

      expect(backend.overtakes).toEqual([])
      expect([...backend.registered].sort()).toEqual(WORST_CASE_SCAN_IDS)
      expect(backend.requests.filter((request) => !isChronological(request))).toEqual([])
    },
  )
}

test(
  'un fichaje en vuelo no lo adelanta uno posterior aunque la red vuelva mientras viaja (RN-21)',
  { tag: ['@RN-21', '@RF-KI-04', '@RQ-05'] },
  async ({ page }) => {
    const LATER_SCAN_ID = '0199f500-0000-7000-8000-000000000001'
    await countQueueListReads(page)
    const backend = await stubOrderingServer(page, { holdSingleScan: true })

    // La camara ficha con la cola vacia: sale por el camino rapido y se queda en el aire.
    await page.goto('/')
    await expect.poll(() => backend.heldScan()).not.toBeNull()
    const held = backend.heldScan()

    // Cinco segundos despues ficha otra persona, y el navegador avisa de que hay red.
    await seedQueue(page, [
      {
        kind: 'qr',
        scan_id: LATER_SCAN_ID,
        occurred_at: new Date(Date.parse(held?.occurredAt ?? '') + 5_000).toISOString(),
        qr_payload: FIXTURE_PAYLOAD,
      },
    ])
    const readsBeforeWake = await queueListReads(page)
    await announceOnline(page)
    await expect.poll(() => queueListReads(page)).toBeGreaterThan(readsBeforeWake)

    // El primero no se llega a procesar.
    backend.releaseHeldScan(503)
    await expect.poll(async () => (await readQueue(page)).length).toBe(0)

    expect(backend.overtakes).toEqual([])
    expect(backend.registered).toEqual([held?.scanId, LATER_SCAN_ID])
  },
)

test(
  'mientras un fichaje esta en vuelo, el drenaje no gira en vacio esperandolo (G2)',
  { tag: ['@RF-KI-04', '@RQ-05'] },
  async ({ page }) => {
    await countQueueListReads(page)
    const backend = await stubOrderingServer(page, { holdSingleScan: true })

    await page.goto('/')
    await expect.poll(() => backend.heldScan()).not.toBeNull()

    const readsBeforeWake = await queueListReads(page)
    await announceOnline(page)
    await expect.poll(() => queueListReads(page)).toBeGreaterThan(readsBeforeWake)
    const readsAfterWake = await queueListReads(page)
    await pageTimePasses(page, 1_000)

    expect(await queueListReads(page)).toBeLessThanOrEqual(readsAfterWake + 1)

    backend.releaseHeldScan(200)
    await expect.poll(async () => (await readQueue(page)).length).toBe(0)
  },
)

// ---------------------------------------------------------------------------
// RN-21: la salida de las 15:00 no se registra antes que la entrada de las 07:00
// ---------------------------------------------------------------------------

const RN21_ENTRY: SeededRow = {
  kind: 'qr',
  scan_id: '0199f600-0000-7000-8000-000000000700',
  occurred_at: '2026-08-14T05:00:00.000Z',
  qr_payload: FIXTURE_PAYLOAD,
}

const RN21_EXITS = [
  {
    name: 'salida con tarjeta: viaja detras, en el mismo lote',
    exit: {
      kind: 'qr',
      scan_id: '0199f600-0000-7000-8000-000000001500',
      occurred_at: '2026-08-14T13:00:00.000Z',
      qr_payload: FIXTURE_PAYLOAD,
    },
    firstPass: ['0199f600-0000-7000-8000-000000000700', '0199f600-0000-7000-8000-000000001500'],
  },
  {
    name: 'salida con PIN: es el tramo siguiente',
    exit: {
      kind: 'pin',
      scan_id: '0199f600-0000-7000-8000-000000001500',
      occurred_at: '2026-08-14T13:00:00.000Z',
      employee_code: '1042',
      pin_sealed: 'c29icmUtc2VsbGFkby1lMmU=',
    },
    firstPass: ['0199f600-0000-7000-8000-000000000700'],
  },
] as const satisfies ReadonlyArray<{ name: string; exit: SeededRow; firstPass: readonly string[] }>

for (const variant of RN21_EXITS) {
  test(
    `la salida de las 15:00 no se registra antes que la entrada de las 07:00 que el servidor dejo sin procesar (${variant.name})`,
    { tag: ['@RN-21', '@RF-KI-04', '@RQ-05'] },
    async ({ page }) => {
      await blockCamera(page)
      const backend = await stubOrderingServer(page, {
        batchPlan: ['first-not-processed-held-back'],
      })

      await page.goto('/')
      await expect.poll(() => queueStoreReady(page)).toBe(true)
      await seedQueue(page, [variant.exit, RN21_ENTRY])
      await announceOnline(page)

      await expect.poll(async () => (await readQueue(page)).length).toBe(0)

      expect(backend.requests[0]?.scanIds).toEqual(variant.firstPass)
      expect(backend.overtakes).toEqual([])
      expect(backend.registered).toEqual([RN21_ENTRY.scan_id, variant.exit.scan_id])
    },
  )
}

// ---------------------------------------------------------------------------
// RN-22: 40 fichajes que el servidor declara invalidos (400) — R4-SC-03, R3-QA-05
// ---------------------------------------------------------------------------

const DISCARD_SEEDED: readonly SeededRow[] = worstCaseRange(0, 38).map((index) => ({
  kind: 'qr',
  scan_id: `0199f700-0000-7000-8000-${String(index).padStart(12, '0')}`,
  occurred_at: worstCaseMinute(index),
  qr_payload: FIXTURE_PAYLOAD,
}))

interface ReportedScan {
  readonly scan_id: string
  readonly occurred_at: string
  readonly kind: string
  readonly http_status: number
}

const byScanId = (left: { scan_id: string }, right: { scan_id: string }): number =>
  left.scan_id.localeCompare(right.scan_id)

/** 39 sembrados y el que lee la camara (el mas reciente), sin red. Devuelve los 40 de disco. */
async function queueFortyWhileOffline(page: Page): Promise<QueuedRow[]> {
  await page.goto('/')
  await expect.poll(() => queueStoreReady(page)).toBe(true)
  await seedQueue(page, DISCARD_SEEDED)
  await expect.poll(async () => (await readQueue(page)).length).toBe(40)
  await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')
  return readQueue(page)
}

test(
  '40 fichajes que el servidor ya no entiende (400) se descartan con rastro, se avisan en 4 tandas de 10 y, acusados, no queda nada pendiente (RN-22)',
  { tag: ['@RN-22', '@RF-KI-04', '@RQ-05'] },
  async ({ page }) => {
    test.setTimeout(90_000)
    await page.clock.install()
    await controlNavigatorOnline(page, false)
    await delayCameraStart(page, 1_500)
    const scans = await stubInvalidScanApi(page)
    const reports = await stubDiscardedScanReports(page)
    const heartbeats = await stubHeartbeatQueueCapture(page)
    const queued = await queueFortyWhileOffline(page)

    await reconnect(page)
    await expect.poll(() => reports.acknowledged.length).toBe(40)
    await expect.poll(async () => (await readDiscarded(page)).length).toBe(0)

    expect(await readQueue(page)).toEqual([])
    expect(scans.singleCalls).toHaveLength(40)
    expect(reports.calls.map((call) => call.length)).toEqual([10, 10, 10, 10])
    expect([...reports.acknowledged].sort()).toEqual(queued.map((row) => row.scan_id).sort())
    const reported: ReportedScan[] = reports.calls.flat().map((report) => ({
      scan_id: report.scan_id,
      occurred_at: report.occurred_at,
      kind: report.kind,
      http_status: report.http_status,
    }))
    expect(reported.sort(byScanId)).toEqual(
      queued
        .map((row) => ({
          scan_id: row.scan_id,
          occurred_at: row.occurred_at,
          kind: 'qr',
          http_status: 400,
        }))
        .sort(byScanId),
    )

    const heartbeatsBefore = heartbeats.calls.length
    await page.clock.fastForward(60_000)
    await expect.poll(() => heartbeats.calls.length).toBeGreaterThan(heartbeatsBefore)
    expect(heartbeats.calls.at(-1)).toMatchObject({
      pendingQueueSize: 0,
      unreportedDiscards: undefined,
    })
    await expect(
      page.locator('[data-testid="scan-confirmation"][data-kind="accepted"]'),
    ).toHaveCount(0)
  },
)

test(
  'si el servidor solo acusa 5 avisos de descartados, los otros 35 se quedan en la lista y el latido los declara (RN-22)',
  { tag: ['@RN-22', '@RF-KI-04', '@RQ-05'] },
  async ({ page }) => {
    test.setTimeout(90_000)
    await page.clock.install()
    await controlNavigatorOnline(page, false)
    await delayCameraStart(page, 1_500)
    await stubInvalidScanApi(page)
    const reports = await stubDiscardedScanReports(page, { acknowledgeAtMost: 5 })
    const heartbeats = await stubHeartbeatQueueCapture(page)
    const queued = await queueFortyWhileOffline(page)

    await reconnect(page)
    await expect.poll(() => reports.calls.length).toBeGreaterThanOrEqual(4)
    await expect.poll(async () => (await readDiscarded(page)).length).toBe(35)

    const acknowledged = new Set(reports.acknowledged)
    expect(acknowledged.size).toBe(5)
    expect((await readDiscarded(page)).map((row) => row.scan_id).sort()).toEqual(
      queued
        .map((row) => row.scan_id)
        .filter((scanId) => !acknowledged.has(scanId))
        .sort(),
    )
    expect(await readQueue(page)).toEqual([])

    const heartbeatsBefore = heartbeats.calls.length
    await page.clock.fastForward(60_000)
    await expect.poll(() => heartbeats.calls.length).toBeGreaterThan(heartbeatsBefore)
    expect(heartbeats.calls.at(-1)).toMatchObject({ pendingQueueSize: 0, unreportedDiscards: 35 })
  },
)
