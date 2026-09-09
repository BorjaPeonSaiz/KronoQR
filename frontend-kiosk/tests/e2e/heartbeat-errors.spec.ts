// Canal de errores de cliente en el latido (RF-PD-15, tarea 5.12).
//
// Dos cosas, y solo dos, puede afirmar un E2E de negro sobre esto sin repetir
// lo que ya prueba `heartbeat.spec.ts` en Vitest:
//
//   1. Que un fallo REAL de la aplicacion en marcha -no uno inventado en un
//      test unitario- acaba de verdad en el cuerpo del siguiente latido, y
//      que la respuesta del servidor vacia lo que se envio.
//   2. Que un latido roto NUNCA retrasa un fichaje (regla dura 19, al reves):
//      reportar errores es un canal de telemetria, no una dependencia del
//      camino de escaneo.

import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { stubHeartbeatWithErrorCapture, stubKioskApi, stubScanApi } from './support/kiosk'

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
  'un latido roto NUNCA bloquea ni retrasa un fichaje (regla dura 19)',
  { tag: ['@RF-PD-15', '@RF-KI-03'] },
  async ({ page }) => {
    await stubKioskApi(page)
    // El latido esta completamente roto: ni contesta.
    await page.route('**/api/v1/kiosk/heartbeat', async (route) => route.abort('failed'))
    const stub = await stubScanApi(page, { outcome: 'clock_in' })

    await page.goto('/')

    // La confirmacion sigue siendo local e instantanea (RNF-P-03): un latido
    // que ni siquiera contesta no puede retrasar esto ni un milisegundo,
    // porque no hay ningun `await` entre el escaneo y la confirmacion que
    // dependa del canal de telemetria.
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    const latency = Number(await page.getByTestId('scan-latency-ms').textContent())
    expect(latency).toBeLessThan(300)

    await expect.poll(() => stub.recorded.length).toBeGreaterThan(0)
  },
)
