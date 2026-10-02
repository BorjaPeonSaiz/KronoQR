// Canal de errores de cliente en el latido (RF-PD-15, tarea 5.12).
//
// Dos cosas, y solo dos, puede afirmar un E2E de negro sobre esto sin repetir
// lo que ya prueba `heartbeat.spec.ts` en Vitest:
//
//   1. Que un fallo REAL de la aplicacion en marcha -no uno inventado en un
//      test unitario- acaba de verdad en el cuerpo del siguiente latido, y
//      que la respuesta del servidor vacia lo que se envio.
//   2. Que un latido roto NUNCA bloquea un fichaje (regla dura 19, al reves):
//      reportar errores es un canal de telemetria, no una dependencia del
//      camino de escaneo.

import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import {
  delayCameraStart,
  stubHeartbeatWithErrorCapture,
  stubHeartbeatWithTokenRotation,
  stubKioskApi,
  stubScanApi,
} from './support/kiosk'
import { announceOnline, queueStoreReady, seedQueue, stubBatchApi } from './support/offlineQueue'

/**
 * `DEFAULT_HEARTBEAT_INTERVAL_MS` de `src/shared/telemetry/heartbeat.ts`. Se
 * repite aqui en vez de importarse: los E2E de este proyecto no importan
 * codigo de `src/` (ningun otro fichero de `tests/e2e/` lo hace), y depender
 * de la resolucion del alias `@/` en el cargador de Playwright seria fragil
 * para una sola constante.
 */
const HEARTBEAT_INTERVAL_MS = 60_000

/** Fuerza que `getUserMedia` falle con `NotAllowedError`, ANTES de que arranque la app. */
async function forceCameraPermissionDenied(page: Page): Promise<void> {
  await page.addInitScript(() => {
    Object.defineProperty(window.navigator, 'mediaDevices', {
      configurable: true,
      value: {
        getUserMedia: () =>
          Promise.reject(new DOMException('Denegado por el usuario', 'NotAllowedError')),
      },
    })
  })
}

/**
 * Instala el reloj falso de la PAGINA y lo mantiene en el mismo instante que
 * el `server_time` que le va a contestar el latido simulado (que corre en el
 * proceso de Playwright, con su propio reloj REAL). Sin esto, cualquier
 * diferencia entre los dos generaria un `kiosk.clock.skew_detected` en cada
 * ciclo -ruido real, no un fallo-, que ensuciaria las aserciones sobre que
 * errores concretos lleva cada latido.
 */
async function installLockstepClock(
  page: Page,
  start: Date,
): Promise<{ advance(): Promise<void>; nowIso(): string }> {
  let now = start
  await page.clock.install({ time: start })
  return {
    async advance() {
      now = new Date(now.getTime() + HEARTBEAT_INTERVAL_MS)
      await page.clock.runFor(HEARTBEAT_INTERVAL_MS)
    },
    nowIso: () => now.toISOString(),
  }
}

test(
  'un error de camara provocado aparece en el siguiente latido y el buffer se vacia con la respuesta',
  { tag: ['@RF-PD-15', '@RF-KI-03'] },
  async ({ page }) => {
    await stubKioskApi(page)
    // Reloj controlado: el latido por defecto es cada 60 s (real) y esperar
    // eso de verdad haria la prueba lenta y fragil. Se instala ANTES de
    // navegar para que la app arranque ya bajo reloj falso.
    const clock = await installLockstepClock(page, new Date('2026-09-09T05:58:00.000Z'))
    const heartbeat = await stubHeartbeatWithErrorCapture(page, {
      accepted: 1,
      serverTime: () => clock.nowIso(),
    })
    await forceCameraPermissionDenied(page)

    await page.goto('/')

    // La camara ha fallado de verdad: esto NO es un doble de `errorReporter`,
    // es `useCamera.ts` clasificando un `NotAllowedError` real.
    await expect(page.getByTestId('camera-failure')).toBeVisible()

    // El latido que arranca AL MONTARSE la pantalla corre en paralelo con el
    // escaner, asi que puede haber salido antes de que el error terminara de
    // reportarse (misma carrera que existiria en la tablet real). Se avanza
    // un ciclo completo para llegar a un latido que SI lo lleve seguro, y se
    // busca ahi -no en uno concreto por indice-.
    await clock.advance()
    await expect
      .poll(() =>
        heartbeat.calls.some((call) =>
          call.clientErrors.some((event) => event.code === 'kiosk.camera.permission_denied'),
        ),
      )
      .toBe(true)

    // La pantalla puede tener OTRA telemetria legitima en el mismo latido (por
    // ejemplo el `wake lock` denegado en un navegador de pruebas sin esa API):
    // lo que importa es el error de camara concreto, no que sea el unico.
    const callWithCameraError = heartbeat.calls.find((call) =>
      call.clientErrors.some((event) => event.code === 'kiosk.camera.permission_denied'),
    )
    const cameraError = callWithCameraError?.clientErrors.find(
      (event) => event.code === 'kiosk.camera.permission_denied',
    )
    expect(cameraError).toBeDefined()
    // Ni rastro de identidad en el cuerpo: ni `device_id` ni nombre alguno.
    expect(cameraError).not.toHaveProperty('device_id')
    expect(JSON.stringify(cameraError)).not.toMatch(/lucia|garcia/i)

    // Un ciclo completo de latido mas: la respuesta (`client_errors_accepted: 1`)
    // ha vaciado el buffer, y el error de camara -que ya no vuelve a producirse,
    // la pantalla se quedo en el aviso- no se repite en el siguiente.
    const callsSoFar = heartbeat.calls.length
    await clock.advance()
    await expect.poll(() => heartbeat.calls.length).toBeGreaterThan(callsSoFar)
    const next = heartbeat.calls[heartbeat.calls.length - 1]
    expect(
      next?.clientErrors.some((event) => event.code === 'kiosk.camera.permission_denied'),
    ).toBe(false)
  },
)

test(
  'un latido que responde 400 por client_errors invalidos no deja la tablet muda',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubKioskApi(page)
    const clock = await installLockstepClock(page, new Date('2026-09-09T05:58:00.000Z'))
    // El servidor simulado rechaza SIEMPRE: lo que importa es que la tablet
    // no se quede repitiendo el mismo lote invalido para siempre.
    const heartbeat = await stubHeartbeatWithErrorCapture(page, {
      status: 400,
      serverTime: () => clock.nowIso(),
    })
    await forceCameraPermissionDenied(page)

    await page.goto('/')
    await expect(page.getByTestId('camera-failure')).toBeVisible()

    // Mismo motivo que en la prueba anterior: se avanza hasta un latido que
    // lleve el error de verdad, en vez de suponer que es el primero.
    await clock.advance()
    await expect
      .poll(() =>
        heartbeat.calls.some((call) =>
          call.clientErrors.some((event) => event.code === 'kiosk.camera.permission_denied'),
        ),
      )
      .toBe(true)

    // El siguiente latido ya no repite el error de camara (se vacio pese al
    // 400): solo llevaria el `kiosk.heartbeat.failed` que el propio rechazo
    // genera, y ese es nuevo, no el mismo lote de antes.
    const callsSoFar = heartbeat.calls.length
    await clock.advance()
    await expect.poll(() => heartbeat.calls.length).toBeGreaterThan(callsSoFar)
    const next = heartbeat.calls[heartbeat.calls.length - 1]
    expect(
      next?.clientErrors.some((event) => event.code === 'kiosk.camera.permission_denied'),
    ).toBe(false)
  },
)

test(
  'un `400` que nombra OTRO campo (no `client_errors`) conserva el buffer',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubKioskApi(page)
    const clock = await installLockstepClock(page, new Date('2026-09-09T05:58:00.000Z'))
    // El `400` es real, pero habla de `app_version`, no de `client_errors`:
    // vaciar el buffer aqui borraria un error que el servidor nunca llego a
    // rechazar (revision del `heartbeat.ts:157-165`, tarea 5.12).
    const heartbeat = await stubHeartbeatWithErrorCapture(page, {
      status: 400,
      invalidFields: ['app_version'],
      serverTime: () => clock.nowIso(),
    })
    await forceCameraPermissionDenied(page)

    await page.goto('/')
    await expect(page.getByTestId('camera-failure')).toBeVisible()

    await clock.advance()
    await expect
      .poll(() =>
        heartbeat.calls.some((call) =>
          call.clientErrors.some((event) => event.code === 'kiosk.camera.permission_denied'),
        ),
      )
      .toBe(true)

    // Un ciclo mas: el error de camara SIGUE en el buffer -no se vacio-, asi
    // que el siguiente latido lo vuelve a llevar.
    const callsSoFar = heartbeat.calls.length
    await clock.advance()
    await expect.poll(() => heartbeat.calls.length).toBeGreaterThan(callsSoFar)
    const next = heartbeat.calls[heartbeat.calls.length - 1]
    expect(
      next?.clientErrors.some((event) => event.code === 'kiosk.camera.permission_denied'),
    ).toBe(true)
  },
)

test(
  'un latido roto NUNCA bloquea un fichaje: se confirma y se envia con el latido aun pendiente (regla dura 19)',
  { tag: ['@RF-PD-15', '@RF-KI-03'] },
  async ({ page }) => {
    await stubKioskApi(page)
    // La camara arranca con retraso (`delayCameraStart`): el latido, que sale
    // al montar la pantalla, queda retenido ANTES de que exista un escaneo.
    await delayCameraStart(page, 1_500)
    // El latido esta completamente roto: ni contesta. La peticion queda
    // retenida hasta el final de la prueba, de modo que si la confirmacion
    // dependiera del latido, esperaria aqui y la prueba fallaria por el
    // `expect.timeout`, no por un cronometro.
    let heartbeatHeld = false
    let releaseHeartbeat: () => void = () => undefined
    const heartbeatGate = new Promise<void>((resolve) => {
      releaseHeartbeat = resolve
    })
    await page.route('**/api/v1/kiosk/heartbeat', async (route) => {
      heartbeatHeld = true
      await heartbeatGate
      await route.abort('failed').catch(() => undefined)
    })
    const stub = await stubScanApi(page, { outcome: 'clock_in' })

    await page.goto('/')

    // Primero: el latido salio y esta retenido. Sin esto la prueba pasaria
    // sin probar nada si el latido dejara de enviarse al montar.
    await expect.poll(() => heartbeatHeld).toBe(true)

    // Despues: la confirmacion y el envio del fichaje ocurren con el latido
    // SIN resolver. El presupuesto de 300 ms (RNF-P-03) NO se afirma aqui: es
    // de rendimiento y depende de la carga de la maquina; lo afirman
    // `tests/unit/ScanView.spec.ts` (118) y `tests/e2e/scan.spec.ts` (111).
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    await expect.poll(() => stub.recorded.length).toBeGreaterThan(0)

    releaseHeartbeat()
  },
)

const TOKEN_STORAGE_KEY = 'kronoqr.kiosk.device_token'
const TOKEN_EXPIRES_STORAGE_KEY = 'kronoqr.kiosk.device_token_expires_at'
/** El que escribe `pairDevice` de `support/kiosk.ts`. */
const PAIRED_TOKEN = 'device-token-e2e'

async function storedToken(page: Page, key: string): Promise<string | null> {
  return page.evaluate((storageKey: string) => window.localStorage.getItem(storageKey), key)
}

test(
  'un latido con rotated_token: el quiosco sigue fichando y la cola sincroniza con el token nuevo',
  { tag: ['@RF-ID-04', '@RF-KI-03', '@RQ-05'] },
  async ({ page }) => {
    const ROTATED = { value: '93|token-rotado-e2e', expires_at: '2027-01-01T06:00:00.000Z' }
    await stubKioskApi(page)
    const clock = await installLockstepClock(page, new Date('2026-10-01T05:58:00.000Z'))
    // El PRIMER latido entrega el relevo; los siguientes, ninguno (clave ausente).
    const heartbeat = await stubHeartbeatWithTokenRotation(page, {
      serverTime: () => clock.nowIso(),
      rotate: ({ index }) => (index === 0 ? ROTATED : undefined),
    })
    // Sin red para el envio individual: todo se encola y viaja por lote.
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    const batch = await stubBatchApi(page)

    await page.goto('/')

    // Regla dura 19: con el relevo en curso el quiosco confirma igual. La
    // latencia de 300 ms ya la fijan `scan.spec.ts` y la prueba de arriba.
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()

    // El token y SU caducidad se guardaron juntos.
    await expect.poll(() => storedToken(page, TOKEN_STORAGE_KEY)).toBe(ROTATED.value)
    expect(await storedToken(page, TOKEN_EXPIRES_STORAGE_KEY)).toBe(ROTATED.expires_at)
    expect(heartbeat.calls[0]?.authorization).toBe(`Bearer ${PAIRED_TOKEN}`)

    // El siguiente latido ya va firmado con el token nuevo.
    await clock.advance()
    await expect
      .poll(() => heartbeat.calls.some((call) => call.authorization === `Bearer ${ROTATED.value}`))
      .toBe(true)

    // Y la cola sincroniza con el token nuevo: la peticion posterior lleva el Bearer nuevo.
    await expect.poll(() => queueStoreReady(page)).toBe(true)
    const scanId = '0199f13a-7c22-7b41-9e88-0c4d5e6f7a81'
    await seedQueue(page, [
      {
        scan_id: scanId,
        occurred_at: '2026-10-01T05:58:31.000Z',
        qr_payload: 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
      },
    ])
    await announceOnline(page)
    await expect
      .poll(() =>
        batch.calls.some(
          (call) =>
            call.authorization === `Bearer ${ROTATED.value}` &&
            call.scans.some((item) => item.scan_id === scanId),
        ),
      )
      .toBe(true)
  },
)

test(
  'dos relevos cruzados: el quiosco conserva el vigente, sigue sincronizando y nunca se desvincula',
  { tag: ['@RF-ID-04', '@RF-KI-03', '@RQ-05'] },
  async ({ page }) => {
    // El servidor retiro T2 al emitir T3 (dos latidos simultaneos con T1), pero
    // la respuesta con T2 llega primero. T1 sigue en solape; T3 es el vigente.
    const T1 = `Bearer ${PAIRED_TOKEN}`
    const T2 = { value: '92|relevo-retirado-e2e', expires_at: '2027-01-01T06:00:00.000Z' }
    const T3 = { value: '93|relevo-vigente-e2e', expires_at: '2027-01-02T06:00:00.000Z' }
    const alive = new Set([T1, `Bearer ${T3.value}`])
    await stubKioskApi(page)
    const clock = await installLockstepClock(page, new Date('2026-10-01T05:58:00.000Z'))
    let staleSent = false
    const heartbeat = await stubHeartbeatWithTokenRotation(page, {
      serverTime: () => clock.nowIso(),
      reject: (authorization) => !alive.has(authorization ?? ''),
      rotate: ({ index, authorization }) => {
        if (index === 0) return T2
        // Reentrega: T1 sigue firmando (por respaldo) y recibe el vigente.
        if (authorization === T1) return T3
        // Respuesta cruzada que llega tarde: otra vez el T2 ya retirado.
        if (authorization === `Bearer ${T3.value}` && !staleSent) {
          staleSent = true
          return T2
        }
        return undefined
      },
    })
    const synced: Array<{ authorization: string | undefined; scanIds: string[] }> = []
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    await page.route('**/api/v1/scan/batch', async (route) => {
      const authorization = route.request().headers()['authorization']
      if (!alive.has(authorization ?? '')) {
        await route.fulfill({ status: 401, contentType: 'application/problem+json', body: '{}' })
        return
      }
      const body = route.request().postDataJSON() as {
        scans: Array<{ scan_id: string; occurred_at: string }>
      }
      synced.push({ authorization, scanIds: body.scans.map((item) => item.scan_id) })
      await route.fulfill({
        status: 207,
        contentType: 'application/json',
        body: JSON.stringify({
          results: body.scans.map((item) => ({
            scan_id: item.scan_id,
            status: 200,
            outcome: {
              scan_id: item.scan_id,
              action: 'clock_in',
              employee_display_name: 'Lucia G.',
              work_date: item.occurred_at.slice(0, 10),
              occurred_at: item.occurred_at,
              recorded_at: new Date().toISOString(),
              worked_minutes: 0,
            },
          })),
        }),
      })
    })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()

    // 1. Adopta el T2 (que ya esta muerto sin que la tablet pueda saberlo).
    await expect.poll(() => storedToken(page, TOKEN_STORAGE_KEY)).toBe(T2.value)

    // 2. Firmado con T2 -> 401 -> reintento con el respaldo (T1) -> el servidor
    //    lo acepta y le entrega el vigente (T3): la tablet vuelve a T1 y adopta T3.
    await clock.advance()
    await expect.poll(() => storedToken(page, TOKEN_STORAGE_KEY)).toBe(T3.value)

    // 3. Llega tarde, cruzada, la respuesta con T2: id menor, se ignora.
    await clock.advance()
    await expect.poll(() => staleSent).toBe(true)
    await clock.advance()
    await expect
      .poll(
        () => heartbeat.calls.filter((call) => call.authorization === `Bearer ${T3.value}`).length,
      )
      .toBeGreaterThanOrEqual(2)
    expect(await storedToken(page, TOKEN_STORAGE_KEY)).toBe(T3.value)
    expect(await storedToken(page, TOKEN_EXPIRES_STORAGE_KEY)).toBe(T3.expires_at)

    // 4. Sigue sincronizando con el vigente, y la tablet NUNCA se desvinculo.
    await expect.poll(() => queueStoreReady(page)).toBe(true)
    const scanId = '0199f13a-7c22-7b41-9e88-0c4d5e6f7a83'
    await seedQueue(page, [
      {
        scan_id: scanId,
        occurred_at: '2026-10-01T05:58:31.000Z',
        qr_payload: 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
      },
    ])
    await announceOnline(page)
    await expect
      .poll(() =>
        synced.some(
          (call) => call.authorization === `Bearer ${T3.value}` && call.scanIds.includes(scanId),
        ),
      )
      .toBe(true)
    expect(page.url()).not.toContain('/pair')
  },
)
