// Recorrido de usuario del quiosco, con camara simulada (doc 02 §9.4).
//
// Chromium reproduce `e2e/fixtures/qr-video.y4m` como si fuera la camara, con un
// QR real en formato `FH1`. Todo lo que ocurre a partir de ahi es el codigo de
// produccion: `getUserMedia`, ZXing y la pantalla de confirmacion.
//
// Etiquetas del doc 02 §9.6.

import { expect, test } from '@playwright/test'
import { installAudioProbe } from './support/audio'
import { FIXTURE_PAYLOAD, delayCameraStart, stubKioskApi, stubScanApi } from './support/kiosk'
import { stubKioskApiWithPin } from './support/pin'
import { expectTouchTargets } from './support/touchTargets'

test.beforeEach(async ({ page }) => {
  await stubKioskApi(page)
})

/**
 * Comprueba que no llega mas de `maxRecorded` fichajes mientras pasan
 * `durationMs` de tiempo REAL. La camara falsa entrega fotogramas por el
 * pipeline de medios de Chromium, no por un temporizador de pagina: no hay
 * ninguna condicion de `page.clock` que adelantar, asi que hace falta dejar
 * pasar el tiempo de verdad. Lo que SI mejora sobre un `waitForTimeout` a
 * ciegas es volver a comprobar la invariante en cada tramo, para fallar en
 * cuanto se rompe y no solo al final (decision 10 de la tarea 3.7).
 */
async function expectNoAdditionalScans(
  stub: { readonly recorded: ReadonlyArray<unknown> },
  durationMs: number,
  maxRecorded: number,
): Promise<void> {
  const stepMs = 100
  const steps = Math.ceil(durationMs / stepMs)
  for (let step = 0; step < steps; step += 1) {
    expect(
      stub.recorded.length,
      'la camara en bucle disparo mas fichajes de los tolerados',
    ).toBeLessThanOrEqual(maxRecorded)
    // eslint-disable-next-line no-restricted-syntax -- no hay condicion observable: se afirma que NO ocurre nada y la camara falsa no pasa por temporizadores de pagina
    await new Promise((resolve) => setTimeout(resolve, stepMs))
  }
  expect(stub.recorded.length).toBeLessThanOrEqual(maxRecorded)
}

test(
  'arranca la camara y decodifica sin que nadie toque la pantalla',
  { tag: ['@RQ-04', '@RF-KI-01', '@RF-KI-02'] },
  async ({ page }) => {
    const stub = await stubScanApi(page, { outcome: 'clock_in', displayName: 'Lucia G.' })

    await page.goto('/')

    // Ni un clic: el bucle de decodificacion arranca solo.
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()

    // Y el envio va DETRAS de la confirmacion, no delante (tarea 1.9): la
    // pantalla no espera a la red, asi que aqui hay que esperar a la peticion.
    // Comprobar `stub.recorded` justo tras la confirmacion seria una carrera.
    await expect.poll(() => stub.recorded.length).toBeGreaterThan(0)
    expect(stub.recorded[0]?.idempotencyKey).toBe(stub.recorded[0]?.scanId)
    expect(stub.recorded[0]?.intent).toBe('auto')
  },
)

test(
  'la PWA declara manifiesto y registra su service worker',
  { tag: ['@RF-KI-01'] },
  async ({ page }) => {
    await stubScanApi(page)
    await page.goto('/')

    const manifestHref = await page.locator('link[rel="manifest"]').first().getAttribute('href')
    expect(manifestHref).toBeTruthy()

    const manifest = await page.request.get(manifestHref ?? '')
    expect(manifest.ok()).toBe(true)
    const parsed = (await manifest.json()) as { display?: string; orientation?: string }
    expect(parsed.display).toBe('fullscreen')
    expect(parsed.orientation).toBe('landscape')

    await expect
      .poll(async () =>
        page.evaluate(async () => (await navigator.serviceWorker.getRegistrations()).length),
      )
      .toBeGreaterThan(0)
  },
)

test(
  'confirma nombre, accion, hora y acumulado del dia',
  { tag: ['@RF-AT-05'] },
  async ({ page }) => {
    await stubScanApi(page, {
      outcome: 'clock_out',
      displayName: 'Lucia G.',
      workedMinutes: 360,
    })

    await page.goto('/')

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted')
    await expect(page.getByTestId('confirmation-headline')).toHaveText('Hasta luego, Lucia G.')
    await expect(page.getByTestId('confirmation-detail')).toContainText('Salida')
    // Horas y minutos, nunca decimales.
    await expect(page.getByTestId('confirmation-total')).toHaveText('Hoy: 6 h 0 min')
  },
)

test(
  'confirma en menos de 300 ms, antes de saber nada del servidor',
  { tag: ['@RF-AT-05', '@RF-KI-02'] },
  async ({ page }) => {
    // El servidor tarda tres segundos: el empleado no puede esperarlo.
    await page.route('**/api/v1/scan', async (route) => {
      // eslint-disable-next-line no-restricted-syntax -- el retraso es del servidor simulado, no una espera de la prueba
      await new Promise((resolve) => setTimeout(resolve, 3_000))
      await route.abort('failed')
    })

    await page.goto('/')

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')
    const latency = Number(await page.getByTestId('scan-latency-ms').textContent())
    expect(latency).toBeLessThan(300)
  },
)

test(
  'ficha igual sin red y lo dice: nunca bloquea al empleado',
  { tag: ['@RF-KI-02', '@RF-AT-05'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'offline' })

    await page.goto('/')

    const panel = page.getByTestId('scan-confirmation')
    await expect(panel).toHaveAttribute('data-kind', 'pending')
    await expect(page.getByTestId('confirmation-pending-badge')).toHaveText('Pendiente de validar')
    // Y NO afirma ni entrada ni salida: eso lo decide el servidor.
    await expect(panel).not.toContainText('Entrada')
    await expect(panel).not.toContainText('Salida')
  },
)

test('avisa del anti-rebote sin tratarlo como error', { tag: ['@RF-AT-05'] }, async ({ page }) => {
  await stubScanApi(page, { outcome: 'debounced', workedMinutes: 240 })

  await page.goto('/')

  const panel = page.getByTestId('scan-confirmation')
  await expect(panel).toHaveAttribute('data-kind', 'debounced')
  await expect(panel).toHaveAttribute('data-variant', 'notice')
  await expect(page.getByTestId('confirmation-headline')).toHaveText(
    'Ya has fichado hace unos segundos',
  )
})

test('un rechazo del servidor no revela la causa', { tag: ['@RF-AT-05'] }, async ({ page }) => {
  await stubScanApi(page, { outcome: 'rejected' })

  await page.goto('/')

  await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected')
  await expect(page.getByTestId('confirmation-headline')).toHaveText('Código no válido')
  const detail = await page.getByTestId('confirmation-detail').textContent()
  expect(detail).not.toMatch(/revocada|firma|caducada|desconocida|inactiv/i)
})

test(
  'un solo gesto produce un solo fichaje, aunque la camara lea diez veces',
  { tag: ['@RF-KI-02'] },
  async ({ page }) => {
    const stub = await stubScanApi(page, { outcome: 'clock_in' })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()

    // Primero el envio (que va detras de la confirmacion, tarea 1.9)...
    await expect.poll(() => stub.recorded.length).toBeGreaterThan(0)

    // ...y despues se deja correr el video, que esta en bucle: la tarjeta sigue
    // delante del objetivo y el anti-rebote tiene que absorber cada relectura.
    await expectNoAdditionalScans(stub, 2_000, 2)

    expect(stub.recorded[0]?.scanId).toBeTruthy()
  },
)

test(
  'tarjeta sostenida: un unico fichaje y la instruccion (con el PIN) vuelve tras la confirmacion',
  { tag: ['@RF-KI-02'] },
  async ({ page }) => {
    // El `beforeEach` empareja el quiosco sin PIN: aqui hace falta lo contrario
    // para comprobar que el enlace de respaldo sigue accesible cuando la
    // instalacion SI lo ofrece — y `pin_sealing_public_key` solo llega si la
    // tablet esta emparejada (ver el comentario de `pairDevice` en
    // `support/pin.ts`).
    await stubKioskApiWithPin(page)
    const stub = await stubScanApi(page, { outcome: 'clock_in' })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    await expect.poll(() => stub.recorded.length).toBeGreaterThan(0)

    // El video de la camara falsa esta en bucle: la tarjeta sigue delante del
    // objetivo mucho mas alla de lo que dura la confirmacion en pantalla
    // (CONFIRMATION_DISPLAY_MS.accepted = 4000 ms). El mecanismo de "tarjeta
    // sostenida" (HELD_GAP_MS) tiene que seguir absorbiendo cada relectura.
    await expect(page.getByTestId('scan-idle')).toBeVisible({ timeout: 10_000 })
    await expect(page.getByTestId('pin-entry-link')).toBeEnabled()

    // Ni un fichaje mas mientras la tarjeta siga en el objetivo.
    expect(stub.recorded.length).toBe(1)
  },
)

test(
  'el payload que viaja es exactamente el que lleva la tarjeta',
  { tag: ['@RF-KI-02'] },
  async ({ page }) => {
    let sentPayload: string | null = null
    await page.route('**/api/v1/scan', async (route) => {
      const body = route.request().postDataJSON() as { qr_payload: string; scan_id: string }
      sentPayload = body.qr_payload
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          scan_id: body.scan_id,
          action: 'clock_in',
          employee_display_name: 'Lucia G.',
          work_date: '2026-08-14',
          occurred_at: new Date().toISOString(),
          recorded_at: new Date().toISOString(),
          worked_minutes: 0,
        }),
      })
    })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()

    // La confirmacion es local y llega antes que la peticion (tarea 1.9): se
    // espera al envio en lugar de suponer que ya ocurrio.
    await expect.poll(() => sentPayload).toBe(FIXTURE_PAYLOAD)
  },
)

test(
  'el aviso de privacidad esta siempre visible',
  { tag: ['@RF-KI-09', '@RL-09'] },
  async ({ page }) => {
    await stubScanApi(page)
    await page.goto('/')

    const notice = page.getByTestId('privacy-notice')
    await expect(notice).toBeVisible()
    await expect(notice).toContainText('Finalidad')
    await expect(notice).toContainText('Base jurídica')
    await expect(notice).toContainText('Derechos')

    // Y sigue estando mientras se confirma un fichaje.
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    await expect(notice).toBeVisible()
  },
)

test(
  'se cambia de idioma y la eleccion sobrevive a una recarga',
  { tag: ['@RF-KI-05'] },
  async ({ page }) => {
    await stubScanApi(page)
    await page.goto('/')

    await page.getByRole('button', { name: 'English' }).click()
    await expect(page.getByTestId('privacy-notice')).toContainText('Data protection notice')

    await page.reload()
    await expect(page.getByTestId('privacy-notice')).toContainText('Data protection notice')
    await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  },
)

test.describe('tablet configurada en ingles', () => {
  // El resto de la suite corre en `es-ES` (ver `playwright.config.ts`). Aqui se
  // comprueba la otra mitad de RF-KI-05: la DETECCION automatica, sin que nadie
  // toque el selector.
  test.use({ locale: 'en-GB' })

  test('arranca en ingles sin que nadie elija nada', { tag: ['@RF-KI-05'] }, async ({ page }) => {
    await stubScanApi(page, { outcome: 'clock_in', displayName: 'Lucia G.' })
    await page.goto('/')

    await expect(page.locator('html')).toHaveAttribute('lang', 'en')
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    await expect(page.getByTestId('confirmation-detail')).toContainText('Clock-in')
  })
})

test('los objetivos tactiles miden al menos 48 px', { tag: ['@RF-KI-06'] }, async ({ page }) => {
  await stubScanApi(page)
  await page.goto('/')

  await expectTouchTargets(page.locator('button:visible, a[href]:visible'))
})

test(
  'el enlace de PIN y el boton de pausa tambien miden al menos 48 px',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    // La pantalla de espera «desnuda» (sin PIN, sin pausa) ya la cubre la
    // prueba de arriba. Estos dos aparecen solo con ciertos ajustes de la
    // instalacion (RF-AT-11, RF-AT-12) y no estan en el `button:visible,
    // a[href]:visible` generico si nadie los activa antes de medir.
    //
    // La camara real decodifica la tarjeta del video en cuanto arranca -sin
    // retraso, en menos de un segundo-, y eso sustituye la pantalla de
    // espera (con el enlace de PIN y el boton de pausa) por la confirmacion:
    // sin `delayCameraStart` esta prueba mide objetivos que ya no estan en
    // pantalla, una carrera que se ve con un build real y no con uno en cache
    // (mismo patron que `accessibility.spec.ts`, boton «Pausa»).
    await delayCameraStart(page, 3_000)
    await stubKioskApiWithPin(page, { breakClockingEnabled: true })
    await stubScanApi(page, { outcome: 'offline' })
    await page.goto('/')

    const pinLink = page.getByTestId('pin-entry-link')
    const breakToggle = page.getByTestId('break-toggle')
    await expect(pinLink).toBeVisible()
    await expect(breakToggle).toBeVisible()

    await expectTouchTargets(pinLink)
    await expectTouchTargets(breakToggle)
  },
)

/** El texto de `testId` mide al menos 24 px (RF-KI-06). */
async function expectConfirmationTextSize(
  page: import('@playwright/test').Page,
  testId: string,
): Promise<void> {
  const size = await page
    .getByTestId(testId)
    .evaluate((element) => Number.parseFloat(getComputedStyle(element).fontSize))
  expect(size, `${testId} por debajo del minimo de RF-KI-06`).toBeGreaterThanOrEqual(24)
}

test(
  'los textos de confirmacion miden al menos 24 px (aceptado)',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'clock_out', workedMinutes: 360 })
    await page.goto('/')

    await expect(page.getByTestId('scan-confirmation')).toBeVisible()

    for (const testId of ['confirmation-headline', 'confirmation-detail', 'confirmation-total']) {
      await expectConfirmationTextSize(page, testId)
    }
  },
)

test(
  'los textos del rechazo tambien miden al menos 24 px, y es el mas largo de los tres',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    // El texto del rechazo (`scan.rejected.body`) es el mas largo de los que
    // pinta el panel: si algo va a desbordar o a encogerse por CSS, es el
    // primer sitio donde se notaria.
    await stubScanApi(page, { outcome: 'rejected' })
    await page.goto('/')

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected')

    for (const testId of ['confirmation-headline', 'confirmation-detail']) {
      await expectConfirmationTextSize(page, testId)
    }
  },
)

test('los textos de «pendiente» miden al menos 24 px', { tag: ['@RF-KI-06'] }, async ({ page }) => {
  await stubScanApi(page, { outcome: 'offline' })
  await page.goto('/')

  await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')

  for (const testId of ['confirmation-headline', 'confirmation-pending-badge']) {
    await expectConfirmationTextSize(page, testId)
  }
})

test(
  'la confirmacion en pantalla va acompanada de un sonido, siempre (RF-KI-06)',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    // Doble canal (doc 01 §6.5): el escaneo NUNCA es solo visual. La
    // confirmacion local es siempre «pendiente» en el momento de decodificar
    // -el servidor es quien decide entrada, salida o rechazo (`scanPipeline.ts`,
    // regla dura 19)-, y ese primer pintado es tambien el primer sonido.
    const { readTones } = await installAudioProbe(page)
    await stubScanApi(page, { outcome: 'clock_in' })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()

    const tones = await readTones()
    expect(tones.length, 'no sono nada al confirmar el fichaje').toBeGreaterThan(0)
    // `TONES.pending` en `useScanSound.ts`: dos toques de 587 Hz en triangulo.
    expect(tones[0]?.type).toBe('triangle')
    expect(tones[0]?.frequency).toBe(587)

    // El desenlace real (aceptado) llega despues por `settle()`, y NO repite
    // sonido: el «pendiente» de arriba ya fue el unico pitido de este
    // fichaje -doble pitido seria una molestia, no informacion nueva-.
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted')
    expect(await readTones()).toEqual(tones)
  },
)

test(
  'un rechazo de tarjeta SI suena, distinto del «pendiente» inicial (RF-KI-06)',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    // Opcion B de la revision de `ui-ux`: `useScanSession.settle()` reproduce
    // el tono de error cuando el desenlace asentado es un rechazo -el
    // «pendiente» inicial no decia nada del resultado, asi que no sonar aqui
    // dejaria al empleado sin saber que su tarjeta no ficho-.
    const { readTones } = await installAudioProbe(page)
    await stubScanApi(page, { outcome: 'rejected' })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected')

    const tones = await readTones()
    // `TONES.pending` (587/587, triangulo) seguido de `TONES.error`
    // (196/147, cuadrada): dos firmas inconfundibles, no un doble pitido del
    // mismo tono.
    expect(tones.map((tone) => tone.type)).toEqual(['triangle', 'triangle', 'square', 'square'])
    expect(tones.map((tone) => tone.frequency)).toEqual([587, 587, 196, 147])
  },
)
