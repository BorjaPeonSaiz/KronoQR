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
  { tag: ['@RQ-04', '@RF-KI-01', '@RF-KI-02', '@RF-AT-01'] },
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

// SONIDO TRAS LA RESPUESTA DEL SERVIDOR (RF-AT-05, RF-KI-06, V7-KI-01).
//
// La pantalla confirma al instante y MUDA; el tono es el del desenlace que
// decide el servidor, si contesta dentro de `SCAN_SOUND_SERVER_WAIT_MS` (2 s).
// Si no, suena `pending` una sola vez y una respuesta tardia solo repinta.
// Cada firma es inconfundible (`TONES` en `useScanSound.ts`):
//
//   entry    784 -> 1175 Hz, sinusoidal      exit     1175 -> 784 Hz, sinusoidal
//   pending  587 / 587 Hz, triangulo         notice   880 Hz, triangulo
//   error    196 -> 147 Hz, cuadrada
const TONE = {
  entry: { frequencies: [784, 1175], types: ['sine', 'sine'] },
  exit: { frequencies: [1175, 784], types: ['sine', 'sine'] },
  pending: { frequencies: [587, 587], types: ['triangle', 'triangle'] },
  notice: { frequencies: [880], types: ['triangle'] },
  error: { frequencies: [196, 147], types: ['square', 'square'] },
} as const

async function expectTones(
  readTones: () => Promise<ReadonlyArray<{ frequency: number; type: string }>>,
  expected: { readonly frequencies: readonly number[]; readonly types: readonly string[] },
): Promise<void> {
  await expect
    .poll(async () => (await readTones()).map((tone) => tone.frequency), {
      message: 'no suena la firma esperada',
    })
    .toEqual(expected.frequencies)
  expect((await readTones()).map((tone) => tone.type)).toEqual(expected.types)
}

test(
  'aceptado dentro del plazo: suena UNA entrada, sin el «pendiente» previo (RF-AT-05)',
  { tag: ['@RF-AT-05', '@RF-KI-06'] },
  async ({ page }) => {
    const { readTones } = await installAudioProbe(page)
    await stubScanApi(page, { outcome: 'clock_in' })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted')

    await expectTones(readTones, TONE.entry)
  },
)

test(
  'una salida aceptada suena distinta de la entrada: baja en vez de subir (RF-KI-06)',
  { tag: ['@RF-AT-05', '@RF-KI-06'] },
  async ({ page }) => {
    const { readTones } = await installAudioProbe(page)
    await stubScanApi(page, { outcome: 'clock_out' })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted')

    await expectTones(readTones, TONE.exit)
  },
)

test(
  'un rechazo del servidor suena a error y nada mas, sin «pendiente» delante (RF-KI-06)',
  { tag: ['@RF-AT-05', '@RF-KI-06'] },
  async ({ page }) => {
    const { readTones } = await installAudioProbe(page)
    await stubScanApi(page, { outcome: 'rejected' })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected')

    await expectTones(readTones, TONE.error)
  },
)

test(
  'el anti-rebote del servidor suena a aviso, no a error (RF-AT-06, RF-KI-06)',
  { tag: ['@RF-AT-05', '@RF-KI-06'] },
  async ({ page }) => {
    const { readTones } = await installAudioProbe(page)
    await stubScanApi(page, { outcome: 'debounced', workedMinutes: 240 })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'debounced')

    await expectTones(readTones, TONE.notice)
  },
)

test(
  'respuesta tardia: la pantalla confirma muda, a los 2 s suena «pendiente» y la respuesta posterior no vuelve a sonar (RF-AT-05)',
  { tag: ['@RF-AT-05', '@RF-KI-06', '@RNF-P-03'] },
  async ({ page }) => {
    const { readTones } = await installAudioProbe(page)
    // El servidor contesta a los 3 s: despues del plazo del sonido (2 s) y con
    // 1 s de margen antes de que la pantalla «pendiente» se retire (4 s).
    await stubScanApi(page, { outcome: 'clock_in', delayMs: 3_000 })

    await page.goto('/')

    // Confirmacion inmediata y muda: la persona ya ve que su fichaje esta
    // recogido, sin esperar ni oir nada todavia.
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')
    expect(await readTones()).toEqual([])

    // Vence el plazo: suena «pendiente», una sola vez.
    await expectTones(readTones, TONE.pending)

    // Llega la respuesta: la pantalla se actualiza y NO suena nada mas.
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted', {
      timeout: 10_000,
    })
    expect((await readTones()).map((tone) => tone.frequency)).toEqual(TONE.pending.frequencies)
  },
)

test(
  'rechazo tardio: «pendiente» y despues «error», para que nadie crea que ficho (RF-AT-05)',
  { tag: ['@RF-AT-05', '@RF-KI-06'] },
  async ({ page }) => {
    const { readTones } = await installAudioProbe(page)
    // Rechazo a los 3 s: pasado el plazo del sonido (2 s), dentro de los 4 s de pantalla.
    await stubScanApi(page, { outcome: 'rejected', delayMs: 3_000 })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')
    await expectTones(readTones, TONE.pending)

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected', {
      timeout: 10_000,
    })
    // Suenan los dos, en orden: «guardado» y luego el error. Nada mas.
    await expect
      .poll(async () => (await readTones()).map((tone) => tone.frequency))
      .toEqual([...TONE.pending.frequencies, ...TONE.error.frequencies])
    expect((await readTones()).map((tone) => tone.type)).toEqual([
      ...TONE.pending.types,
      ...TONE.error.types,
    ])
  },
)

test(
  'servidor inalcanzable: suena «pendiente» una sola vez y la pantalla no cambia (regla dura 19)',
  { tag: ['@RF-AT-05', '@RF-KI-06', '@RF-AT-10'] },
  async ({ page }) => {
    const { readTones } = await installAudioProbe(page)
    await stubScanApi(page, { outcome: 'offline' })

    await page.goto('/')
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')

    await expectTones(readTones, TONE.pending)
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')
  },
)
