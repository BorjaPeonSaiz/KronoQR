// Fichaje de respaldo por PIN (tarea 1.12, RF-AT-11), con y sin red.
//
// Mismo patron que `offline.spec.ts` de la 1.9 para la parte de cola: el
// backend no participa, se intercepta cada llamada, y se abre IndexedDB por
// debajo para comprobar lo que queda escrito de verdad.
//
// LA CAMARA, BLOQUEADA DONDE NO SE PRUEBA (intermitencia de la 2.2.1, bloque
// 16 §4, la misma causa que `pin-lockout.spec.ts`). El video del proyecto
// `kiosk-qr` decodifica la tarjeta en bucle, y su confirmacion desmonta el
// enlace «Ficha con tu codigo y PIN»: si llegaba durante el clic, el clic se
// perdia y la prueba agotaba el plazo en el `fill` del codigo. Toda prueba que
// pulsa el enlace llama a `blockCamera` y entra con `openPinScreen`, que espera
// al paso del codigo antes de teclear. Solo quedan con la camara en marcha la
// de la instalacion sin PIN y la de WebAssembly bloqueado: no pulsan el enlace
// y miran la pantalla de tarjeta. La convivencia del fichaje con la camara la
// prueba `scan.spec.ts`, no este fichero.

import { expect, test } from '@playwright/test'
import { expectNoBlockingViolations } from './support/axe'
import { installAudioProbe } from './support/audio'
import { blockCamera, readQueue } from './support/offlineQueue'
import {
  enterEmployeeCode,
  openPinScreen,
  pressPinDigits,
  stubKioskApiWithoutPin,
  stubKioskApiWithPin,
  stubPinScanApi,
} from './support/pin'
import { expectTouchTargets, TABLET_VIEWPORTS } from './support/touchTargets'

const EMPLOYEE_CODE = 'E7QK2MXPR'
const RAW_PIN = '483920'

test(
  'la instalacion sin PIN no ofrece el boton de respaldo (ADR-017)',
  { tag: ['@RF-AT-11'] },
  async ({ page }) => {
    await stubKioskApiWithoutPin(page)
    await page.goto('/')

    await expect(page.getByTestId('pin-entry-link')).toHaveCount(0)
  },
)

test(
  'la instalacion con PIN ofrece el boton, y ficha con red',
  { tag: ['@RF-AT-11'] },
  async ({ page }) => {
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    // Un retraso de 1200 ms -- por encima de `PIN_VERIFY_GRACE_MS` (300 ms), con
    // holgura para que el sondeo de Playwright lo vea en un runner lento, y
    // muy por debajo de `PIN_VERIFY_TIMEOUT_MS` (2500) -- deja tiempo a observar
    // «Comprobando…» antes de que se asiente en el desenlace real: el PIN no
    // se puede validar en local (viaja sellado, RF-AT-11), asi que esa
    // pantalla intermedia es del contrato, no un detalle de temporizacion.
    // Un retraso mas corto (o nulo) queda cubierto por la prueba siguiente,
    // que comprueba justo lo contrario: sin retraso, «Comprobando…» no debe
    // llegar a aparecer.
    const pinApi = await stubPinScanApi(page, 'clock_in', 1200)

    await openPinScreen(page)
    await enterEmployeeCode(page, EMPLOYEE_CODE)

    await expect(page.getByTestId('pin-step-pin')).toBeVisible()
    // El PIN nunca aparece en pantalla, ni siquiera parcialmente.
    await pressPinDigits(page, RAW_PIN)
    await expect(page.locator('body')).not.toContainText(RAW_PIN)

    await page.getByTestId('pin-confirm').click()

    // «Comprobando…» primero: con red, jamas se anuncia un exito antes de
    // saberlo.
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'verifying')
    await expect(page.getByTestId('confirmation-headline')).toHaveText('Comprobando…')

    // Y despues, el desenlace real: entrada confirmada.
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted', {
      timeout: 10_000,
    })
    await expect.poll(() => pinApi.recorded.length).toBeGreaterThan(0)

    const sent = pinApi.recorded[0]
    expect(sent?.employeeCode).toBe(EMPLOYEE_CODE)
    // Lo unico que viaja del PIN es el sobre sellado: nunca los 6 digitos.
    expect(sent?.pinSealed).not.toBe(RAW_PIN)
    expect(sent?.pinSealed).not.toContain(RAW_PIN)
    expect(sent?.pinSealed).toMatch(/^[A-Za-z0-9+/]+={0,2}$/)
    // La `Idempotency-Key` es el `scan_id`, igual que en `/scan` (regla dura 8).
    expect(sent?.idempotencyKey).toBe(sent?.scanId)
  },
)

test(
  'un PIN de 8 cifras no se envia solo: se envia con «Aceptar» (ADR-050)',
  { tag: ['@RF-AT-11', '@RF-ID-09'] },
  async ({ page }) => {
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    const pinApi = await stubPinScanApi(page, 'clock_in')

    await openPinScreen(page)
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await expect(page.getByTestId('pin-step-pin')).toBeVisible()

    await pressPinDigits(page, RAW_PIN)
    await expect(page.getByTestId('pin-confirm')).toBeEnabled()
    // Con 6 cifras no hay envio automatico: sigue el teclado.
    await expect(page.getByTestId('scan-confirmation')).toHaveCount(0)
    expect(pinApi.recorded).toHaveLength(0)

    await pressPinDigits(page, '17')
    await expect(page.getByTestId('pin-confirm')).toBeEnabled()
    // El teclado con las 8 cifras puestas, antes de enviar (V7-QA-1).
    await expectNoBlockingViolations(page)
    await page.getByTestId('pin-confirm').click()

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted', {
      timeout: 10_000,
    })
    await expect.poll(() => pinApi.recorded.length).toBe(1)
    expect(pinApi.recorded[0]?.pinSealed).not.toContain('48392017')
  },
)

test(
  'en despliegue normal (respuesta sin retraso), jamas se ve «Comprobando…»: un solo pintado, un solo sonido',
  { tag: ['@RF-AT-11'] },
  async ({ page }) => {
    // El caso habitual: servidor on-premise en la misma VLAN que la tablet.
    // Sin retraso artificial, la interceptacion de Playwright contesta muy
    // por debajo de `PIN_VERIFY_GRACE_MS` (300 ms) -- igual que un servidor
    // real en la misma red. «Comprobando…» pintado y sustituido de inmediato
    // seria un parpadeo (dos pintados, dos sonidos por un unico fichaje):
    // este es el hallazgo de revision que la ventana de gracia corrige.
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    const pinApi = await stubPinScanApi(page, 'clock_in')

    await openPinScreen(page)
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)
    await page.getByTestId('pin-confirm').click()

    // Se muestrea el `data-kind` del panel repetidas veces mientras se
    // asienta, acumulando en un conjunto: si «Comprobando…» llegara a
    // aparecer, aunque fuera un instante, se quedaria dentro para siempre y
    // el conjunto YA NO PODRIA valer nunca `['accepted']` a solas -el `poll`
    // expira por plazo en vez de conformarse con un valor que no es el
    // esperado, que es justo lo que hace fallar la prueba si el parpadeo
    // reaparece-.
    const kindsSeen = new Set<string>()
    await expect
      .poll(
        async () => {
          const kind = await page.getByTestId('scan-confirmation').getAttribute('data-kind')
          kindsSeen.add(kind ?? 'kind-attribute-missing')
          return [...kindsSeen]
        },
        { intervals: [20], timeout: 1_000 },
      )
      .toEqual(['accepted'])

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted')
    await expect(page.getByTestId('confirmation-headline')).not.toHaveText('Comprobando…')
    await expect.poll(() => pinApi.recorded.length).toBeGreaterThan(0)
  },
)

test(
  'sin red: se confirma en local, se encola sellado y se sincroniza al volver',
  { tag: ['@RF-AT-11', '@RQ-05'] },
  async ({ page }) => {
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    await page.route('**/api/v1/scan/pin', async (route) => route.abort('failed'))

    await openPinScreen(page)
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)
    await page.getByTestId('pin-confirm').click()

    // Confirmacion LOCAL y honesta: pendiente, no rechazada.
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')

    // En IndexedDB: el sobre sellado, JAMAS el PIN en claro.
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)
    const queued = await readQueue(page)
    const serialized = JSON.stringify(queued)
    expect(serialized).not.toContain(RAW_PIN)
    expect(serialized).toContain('"kind":"pin"')

    // Vuelve la red: se sincroniza por `/api/v1/scan/pin`, UNA llamada, sin lote
    // (el PIN no tiene variante de lote, doc 02 §11).
    await page.unroute('**/api/v1/scan/pin')
    const pinApi = await stubPinScanApi(page, 'clock_in')
    await page.evaluate(() => window.dispatchEvent(new Event('online')))

    await expect.poll(() => pinApi.recorded.length).toBeGreaterThan(0)
    await expect.poll(async () => (await readQueue(page)).length).toBe(0)
  },
)

test(
  'PIN erroneo: nunca se ve «pendiente» (indigo), solo «Comprobando…» y despues el rechazo generico (regla dura 17)',
  { tag: ['@RF-AT-11', '@RS-03'] },
  async ({ page }) => {
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    // El mismo retraso que en el caso de exito: sin el, la interceptacion de
    // Playwright contesta tan rapido que no llega a comprobarse que
    // «Comprobando…» aparecio de verdad (con 400 ms fallaba en el runner de la CI).
    await stubPinScanApi(page, 'rejected', 1200)

    await openPinScreen(page)
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)

    // El PIN NO se puede validar en local (viaja sellado, RF-AT-11): pintar
    // «pendiente» de entrada, en indigo, seria enseñar una confirmacion que
    // parece un exito y sustituirla por un rechazo justo despues — enganoso.
    // Aqui se comprueba el cableado punto a punto (verifying -> rejected sin
    // pasar por la pantalla); que NINGUNA respuesta rapida produce jamas un
    // «pending» intermedio ya lo prueba, rama a rama y con reloj falso,
    // `pinPipeline.spec.ts`.
    await page.getByTestId('pin-confirm').click()

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'verifying')
    await expect(page.getByTestId('confirmation-headline')).toHaveText('Comprobando…')

    // Y despues, el rechazo generico: el mismo texto que usa el escaneo de
    // tarjeta. Nunca «pending»: la asercion de arriba ya atrapo «verifying»
    // como PRIMER desenlace en pantalla, y `data-kind` solo cambia una vez
    // mas, aqui, al desenlace real.
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected', {
      timeout: 10_000,
    })
    await expect(page.getByTestId('confirmation-headline')).toContainText('Código no válido')
  },
)

test(
  'la entrada y el rechazo del fichaje por PIN disparan tonos distintos, ademas del visual (RF-KI-06)',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    // A diferencia del escaneo de tarjeta (ver el comentario del final de
    // `scan.spec.ts`), el PIN SI pinta el desenlace real con su propio
    // sonido: `PinView.vue` llama a `session.present()` -que SI suena- para
    // el resultado definitivo, nunca a `session.settle()` sin sonido, salvo
    // que ya hubiera sonado un aviso neutro antes (`verifying`/`pending`).
    // Es la unica via del producto donde hoy se puede demostrar de extremo a
    // extremo lo que pide el principio de diseno: firmas distintas para la
    // entrada y para el rechazo.
    // Con la camara bloqueada no hay fichaje de tarjeta de fondo que suene
    // por su cuenta (antes colaba un «pendiente» 587/587, hallazgo de la
    // revision QA sobre `3d004f4`), y cada `openPinScreen` recarga la pagina
    // y con ella el registro del doble: lo que se lee es el sonido COMPLETO
    // de cada intento, sin vaciados intermedios.
    const { readTones } = await installAudioProbe(page)
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    await stubPinScanApi(page, 'clock_in')

    await openPinScreen(page)
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)
    await page.getByTestId('pin-confirm').click()

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted', {
      timeout: 10_000,
    })
    const acceptedTones = await readTones()
    // `TONES.entry` en `useScanSound.ts`: 784 Hz seguido de 1175 Hz, sinusoidal.
    expect(acceptedTones.map((tone) => tone.type)).toEqual(['sine', 'sine'])
    expect(acceptedTones.map((tone) => tone.frequency)).toEqual([784, 1175])

    await page.unroute('**/api/v1/scan/pin')
    await stubPinScanApi(page, 'rejected')

    await openPinScreen(page)
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)
    await page.getByTestId('pin-confirm').click()

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected', {
      timeout: 10_000,
    })
    const rejectedTones = await readTones()
    // `TONES.error`: 196 Hz seguido de 147 Hz, onda cuadrada -inconfundible
    // frente al tono ascendente y sinusoidal de la entrada de arriba-.
    expect(rejectedTones.map((tone) => tone.type)).toEqual(['square', 'square'])
    expect(rejectedTones.map((tone) => tone.frequency)).toEqual([196, 147])
  },
)

test(
  '«Comprobando…» es mudo y el tono llega con el desenlace del servidor (RF-AT-05, RF-AT-11)',
  { tag: ['@RF-AT-05', '@RF-AT-11', '@RF-KI-06'] },
  async ({ page }) => {
    const { readTones, reset } = await installAudioProbe(page)
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    // Contesta a los 1,2 s: pasada la gracia (300 ms) y dentro del plazo del PIN (2,5 s).
    await stubPinScanApi(page, 'clock_in', 1_200)

    await openPinScreen(page)
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)
    await reset()
    await page.getByTestId('pin-confirm').click()

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'verifying')
    expect(await readTones(), 'Comprobando… no debe sonar').toEqual([])

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted', {
      timeout: 10_000,
    })
    // Un solo tono: `TONES.entry` (784 -> 1175 Hz, sinusoidal).
    const tones = await readTones()
    expect(tones.map((tone) => tone.frequency)).toEqual([784, 1175])
    expect(tones.map((tone) => tone.type)).toEqual(['sine', 'sine'])
  },
)

test(
  'PIN con plazo vencido: suena «pendiente» una vez y la respuesta tardia no vuelve a sonar (RF-AT-05)',
  { tag: ['@RF-AT-05', '@RF-AT-11', '@RF-KI-06'] },
  async ({ page }) => {
    const { readTones, reset } = await installAudioProbe(page)
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    // Contesta a los 3,5 s: despues de `PIN_VERIFY_TIMEOUT_MS` (2,5 s).
    await stubPinScanApi(page, 'clock_in', 3_500)

    await openPinScreen(page)
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)
    await reset()
    await page.getByTestId('pin-confirm').click()

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'verifying')
    expect(await readTones()).toEqual([])

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending', {
      timeout: 10_000,
    })
    // `TONES.pending`: dos toques de 587 Hz en triangulo.
    await expect
      .poll(async () => (await readTones()).map((tone) => tone.frequency))
      .toEqual([587, 587])

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted', {
      timeout: 10_000,
    })
    expect((await readTones()).map((tone) => tone.frequency)).toEqual([587, 587])
  },
)

test(
  'PIN rechazado tras el plazo: suena «pendiente» y despues «error» (RF-AT-05)',
  { tag: ['@RF-AT-05', '@RF-AT-11', '@RF-KI-06'] },
  async ({ page }) => {
    const { readTones, reset } = await installAudioProbe(page)
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    // Rechaza a los 3,5 s: despues de `PIN_VERIFY_TIMEOUT_MS` (2,5 s).
    await stubPinScanApi(page, 'rejected', 3_500)

    await openPinScreen(page)
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)
    await reset()
    await page.getByTestId('pin-confirm').click()

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'verifying')
    expect(await readTones()).toEqual([])

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending', {
      timeout: 10_000,
    })
    await expect
      .poll(async () => (await readTones()).map((tone) => tone.frequency))
      .toEqual([587, 587])

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'rejected', {
      timeout: 10_000,
    })
    // `TONES.error`: 196 -> 147 Hz, cuadrada, detras del «pendiente».
    await expect
      .poll(async () => (await readTones()).map((tone) => tone.frequency))
      .toEqual([587, 587, 196, 147])
    expect((await readTones()).map((tone) => tone.type)).toEqual([
      'triangle',
      'triangle',
      'square',
      'square',
    ])
  },
)

test(
  'la pantalla de PIN no tiene violaciones de accesibilidad criticas ni graves',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    await openPinScreen(page)

    await expectNoBlockingViolations(page)

    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await expect(page.getByTestId('pin-step-pin')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

// KI7-03: en las tres orientaciones, no solo en la apaisada de la configuracion.
for (const { name, viewport } of TABLET_VIEWPORTS) {
  test.describe(`PIN en ${name}`, () => {
    test.use({ viewport })

    test(
      'el teclado numerico del PIN mide al menos 48 px por digito',
      { tag: ['@RF-KI-06', '@RF-AT-11'] },
      async ({ page }) => {
        await blockCamera(page)
        await stubKioskApiWithPin(page)
        await openPinScreen(page)
        await enterEmployeeCode(page, EMPLOYEE_CODE)
        await expect(page.getByTestId('pin-step-pin')).toBeVisible()

        await expectTouchTargets(page.getByTestId('pin-step-pin').getByRole('button'))
      },
    )

    test(
      'los controles del paso del codigo de empleado miden al menos 48 px',
      { tag: ['@RF-KI-06', '@RF-AT-11'] },
      async ({ page }) => {
        await blockCamera(page)
        await stubKioskApiWithPin(page)
        await openPinScreen(page)

        await expectTouchTargets(page.locator('button:visible, a[href]:visible, input:visible'))
      },
    )
  })
}

test(
  'si el navegador no puede arrancar WebAssembly, el quiosco no ofrece el PIN ni lo disfraza de «codigo no valido» (PIN-03)',
  { tag: ['@RF-AT-11', '@RF-KI-06'] },
  async ({ page }) => {
    // La CSP de una instalacion mal configurada, o un WebView sin WASM: libsodium
    // no arranca y `crypto_box_seal` no existe.
    await page.addInitScript(() => {
      const blocked = (): Promise<never> => Promise.reject(new Error('wasm blocked'))
      Object.defineProperty(WebAssembly, 'instantiate', { value: blocked, configurable: true })
      Object.defineProperty(WebAssembly, 'compile', { value: blocked, configurable: true })
      Object.defineProperty(WebAssembly, 'instantiateStreaming', {
        value: blocked,
        configurable: true,
      })
    })
    await stubKioskApiWithPin(page)

    await page.goto('/')

    // La tarjeta sigue ahi (es la via principal) y el PIN NO se ofrece.
    await expect(page.getByTestId('scan-idle')).toBeVisible()
    await expect(page.getByTestId('pin-entry-link')).toHaveCount(0)
    await expect(page.getByTestId('pin-entry-link-fallback')).toHaveCount(0)

    // Quien llega directo a /pin (recarga, marcador) lo ve dicho con claridad,
    // antes de teclear nada.
    await page.goto('/pin')
    await expect(page.getByTestId('pin-unavailable')).toBeVisible()
    await expect(page.getByTestId('pin-unavailable')).toContainText('recepción')
    await expectNoBlockingViolations(page)
    await expect(page.getByTestId('pin-step-code')).toHaveCount(0)

    // Y de vuelta a la tarjeta, con el padron ya cargado y el estado ya sabido,
    // el boton sigue sin existir (la ausencia de arriba no era solo prisa).
    await page.getByTestId('pin-back-to-card').click()
    await expect(page.getByTestId('scan-idle')).toBeVisible()
    await expect(page.getByTestId('pin-entry-link')).toHaveCount(0)
  },
)

test(
  'un PIN abandonado a medio teclear vuelve solo a inicio a los 60 s y borra lo tecleado',
  { tag: ['@RF-AT-11', '@RF-KI-07'] },
  async ({ page }) => {
    await blockCamera(page)
    await stubKioskApiWithPin(page)
    await openPinScreen(page)

    await page.clock.install()
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await expect(page.getByTestId('pin-step-pin')).toBeVisible()
    await pressPinDigits(page, '48')

    await page.clock.runFor(59_000)
    await expect(page.getByTestId('pin-step-pin')).toBeVisible()

    await page.clock.runFor(1_500)
    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByTestId('pin-step-pin')).toHaveCount(0)
  },
)
