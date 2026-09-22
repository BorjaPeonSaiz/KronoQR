// Fichaje de respaldo por PIN (tarea 1.12, RF-AT-11), con y sin red.
//
// Mismo patron que `offline.spec.ts` de la 1.9 para la parte de cola: el
// backend no participa, se intercepta cada llamada, y se abre IndexedDB por
// debajo para comprobar lo que queda escrito de verdad.

import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { installAudioProbe } from './support/audio'
import { readQueue } from './support/offlineQueue'
import {
  enterEmployeeCode,
  pressPinDigits,
  stubKioskApiWithoutPin,
  stubKioskApiWithPin,
  stubPinScanApi,
} from './support/pin'
import { expectTouchTargets } from './support/touchTargets'

const EMPLOYEE_CODE = 'E7QK2MXPR'
const RAW_PIN = '483920'
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']
const DISABLED_RULES = ['video-caption']

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

    await page.goto('/')
    await expect(page.getByTestId('pin-entry-link')).toBeVisible()
    await page.getByTestId('pin-entry-link').click()

    await expect(page.getByTestId('pin-step-code')).toBeVisible()
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
  'en despliegue normal (respuesta sin retraso), jamas se ve «Comprobando…»: un solo pintado, un solo sonido',
  { tag: ['@RF-AT-11'] },
  async ({ page }) => {
    // El caso habitual: servidor on-premise en la misma VLAN que la tablet.
    // Sin retraso artificial, la interceptacion de Playwright contesta muy
    // por debajo de `PIN_VERIFY_GRACE_MS` (300 ms) -- igual que un servidor
    // real en la misma red. «Comprobando…» pintado y sustituido de inmediato
    // seria un parpadeo (dos pintados, dos sonidos por un unico fichaje):
    // este es el hallazgo de revision que la ventana de gracia corrige.
    await stubKioskApiWithPin(page)
    const pinApi = await stubPinScanApi(page, 'clock_in')

    await page.goto('/')
    await page.getByTestId('pin-entry-link').click()
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
    await stubKioskApiWithPin(page)
    await page.route('**/api/v1/scan/pin', async (route) => route.abort('failed'))

    await page.goto('/')
    await page.getByTestId('pin-entry-link').click()
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
    await stubKioskApiWithPin(page)
    // El mismo retraso que en el caso de exito: sin el, la interceptacion de
    // Playwright contesta tan rapido que no llega a comprobarse que
    // «Comprobando…» aparecio de verdad (con 400 ms fallaba en el runner de la CI).
    await stubPinScanApi(page, 'rejected', 1200)

    await page.goto('/')
    await page.getByTestId('pin-entry-link').click()
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
    const { readTones, reset } = await installAudioProbe(page)
    await stubKioskApiWithPin(page)
    await stubPinScanApi(page, 'clock_in')

    // `stubKioskApiWithPin` deja el trafico de fondo del ESCANEO DE TARJETA
    // corriendo en la MISMA pagina (el video de camara del proyecto
    // `kiosk-qr` decodifica solo, ver `support/pin.ts`), y ese fichaje de
    // fondo suena tambien -su propio «pendiente»-, sin relacion con el PIN.
    // Con `retries: 0` una relectura de fondo podia colarse justo entre el
    // desenlace del PIN y la lectura del registro (hallazgo de la revision
    // QA sobre `3d004f4`: dos tonos 587/587 en vez de los del PIN). Por eso
    // se vacia el registro justo ANTES del ultimo gesto -pulsar «Comprobar»-,
    // lo mas cerca posible del sonido que interesa, y se lee el registro
    // COMPLETO despues: si algo de fondo se colara igualmente, la asercion de
    // longitud fallaria en vez de comparar contra un tono equivocado en
    // silencio.
    await page.goto('/')
    await page.getByTestId('pin-entry-link').click()
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)
    await reset()
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

    await page.goto('/')
    await page.getByTestId('pin-entry-link').click()
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await pressPinDigits(page, RAW_PIN)
    await reset()
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
  'la pantalla de PIN no tiene violaciones de accesibilidad criticas ni graves',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    await stubKioskApiWithPin(page)
    await page.goto('/')
    await page.getByTestId('pin-entry-link').click()
    await expect(page.getByTestId('pin-step-code')).toBeVisible()

    const codeResults = await new AxeBuilder({ page })
      .withTags(WCAG_TAGS)
      .disableRules(DISABLED_RULES)
      .analyze()
    const codeBlocking = codeResults.violations.filter(
      (violation) => violation.impact === 'critical' || violation.impact === 'serious',
    )
    expect(
      codeBlocking,
      codeBlocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
    ).toEqual([])

    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await expect(page.getByTestId('pin-step-pin')).toBeVisible()

    const pinResults = await new AxeBuilder({ page })
      .withTags(WCAG_TAGS)
      .disableRules(DISABLED_RULES)
      .analyze()
    const pinBlocking = pinResults.violations.filter(
      (violation) => violation.impact === 'critical' || violation.impact === 'serious',
    )
    expect(
      pinBlocking,
      pinBlocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
    ).toEqual([])
  },
)

test(
  'el teclado numerico del PIN mide al menos 48 px por digito',
  { tag: ['@RF-KI-06'] },
  async ({ page }) => {
    await stubKioskApiWithPin(page)
    await page.goto('/')
    await page.getByTestId('pin-entry-link').click()
    await enterEmployeeCode(page, EMPLOYEE_CODE)
    await expect(page.getByTestId('pin-step-pin')).toBeVisible()

    await expectTouchTargets(page.getByTestId('pin-step-pin').getByRole('button'))
  },
)
