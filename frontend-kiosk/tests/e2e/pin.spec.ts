// Fichaje de respaldo por PIN (tarea 1.12, RF-AT-11), con y sin red.
//
// Mismo patron que `offline.spec.ts` de la 1.9 para la parte de cola: el
// backend no participa, se intercepta cada llamada, y se abre IndexedDB por
// debajo para comprobar lo que queda escrito de verdad.

import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { readQueue } from './support/offlineQueue'
import {
  enterEmployeeCode,
  pressPinDigits,
  stubKioskApiWithoutPin,
  stubKioskApiWithPin,
  stubPinScanApi,
} from './support/pin'

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
    // Un retraso de 400 ms -- por encima de `PIN_VERIFY_GRACE_MS` (300 ms) y
    // muy por debajo de `PIN_VERIFY_TIMEOUT_MS` -- deja tiempo a observar
    // «Comprobando…» antes de que se asiente en el desenlace real: el PIN no
    // se puede validar en local (viaja sellado, RF-AT-11), asi que esa
    // pantalla intermedia es del contrato, no un detalle de temporizacion.
    // Un retraso mas corto (o nulo) queda cubierto por la prueba siguiente,
    // que comprueba justo lo contrario: sin retraso, «Comprobando…» no debe
    // llegar a aparecer.
    const pinApi = await stubPinScanApi(page, 'clock_in', 400)

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

    // Se muestrea el estado del panel varias veces mientras se asienta: si
    // «Comprobando…» llegara a aparecer, aunque fuera un instante, quedaria
    // atrapado aqui.
    const kindsSeen = new Set<string>()
    for (let sample = 0; sample < 25; sample += 1) {
      const kind = await page.getByTestId('scan-confirmation').getAttribute('data-kind')
      if (kind !== null) kindsSeen.add(kind)
      await page.waitForTimeout(20)
    }

    expect([...kindsSeen]).not.toContain('verifying')
    expect(kindsSeen.has('accepted')).toBe(true)

    // Un unico pintado: el panel llega directamente al desenlace real, sin
    // pasar por ningun estado intermedio.
    expect([...kindsSeen]).toEqual(['accepted'])

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
    // El mismo retraso corto que en el caso de exito: sin el, la
    // interceptacion de Playwright podria contestar tan rapido que no
    // llegaria a comprobarse que «Comprobando…» aparecio de verdad.
    await stubPinScanApi(page, 'rejected', 400)

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
