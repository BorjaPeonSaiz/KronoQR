// Capturas de las confirmaciones del quiosco, para `docs/cliente/guia-rrhh.md`
// y `docs/cliente/hoja-empleado.md` (tarea 5.11b, bloque D, decisiones 2 y 4
// del cierre de la 5.11b).
//
// Las tres capturas se alcanzan por la via del PIN (RF-AT-11), no por la
// camara: `PinView.vue` reutiliza el MISMO `ScanConfirmationPanel` que la
// pantalla de tarjeta -mismo componente, mismo texto, mismo sonido, ver el
// comentario de cabecera de `PinView.vue`- asi que el resultado en pantalla
// es identico al de un escaneo de tarjeta aceptado o pendiente. El codigo de
// empleado y el PIN se escriben con teclado, sin depender del fotograma de
// camara simulada que usa este generador, que es SIN QR
// (`playwright.screenshots.config.ts`, `qr-video-blank.y4m`): aqui no hace
// falta tocarlo.
//
// NOMBRES DE FICHERO FIJOS (decision 4 del cierre de la 5.11b). No cambiarlos
// sin acordarlo con quien escribe `guia-rrhh.md` y `hoja-empleado.md`, que
// enlazan por nombre exacto:
//   quiosco-fichaje-confirmado.png
//   quiosco-fichaje-pendiente.png
//   quiosco-pin-respaldo.png
//
// Se ejecuta a mano con `npm run docs:screenshots` (nunca en CI, misma
// decision que `pairing.screenshots.ts`) sobre un `dist/` ya construido.

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'
import { stubBrandingApi } from '../e2e/support/kiosk'
import {
  enterEmployeeCode,
  pressPinDigits,
  stubKioskApiWithPin,
  stubPinScanApi,
} from '../e2e/support/pin'

const IMG_ROOT = fileURLToPath(new URL('../../../docs/cliente/img', import.meta.url))
const REPO_ROOT = fileURLToPath(new URL('../../../', import.meta.url))

/** Mismo nombre de demostracion que usan `scan.spec.ts` y `pin.spec.ts` (sin PII, regla dura 21). */
const DISPLAY_NAME = 'Lucia G.'

/** Codigo de empleado de demostracion de `pin.spec.ts`: opaco, sin PII. */
const EMPLOYEE_CODE = 'E7QK2MXPR'

/** PIN de demostracion de `pin.spec.ts`. Nunca aparece en pantalla: son puntos. */
const RAW_PIN = '483920'

/**
 * 07:02 en Europe/Madrid (CEST, +2 en septiembre): el ejemplo literal del
 * doc 01 §11, «Buenos dias, Lucia — Entrada 07:02». Fijar el reloj de la
 * pagina hace la captura reproducible sin depender de la hora real de quien
 * la genera; la hora que se ve en una captura no es un registro legal.
 */
const CONFIRMED_AT = new Date('2026-09-09T05:02:00.000Z')

test.beforeAll(() => {
  // Mismo sello que `pairing.screenshots.ts`: las SPA escriben el mismo
  // fichero con el mismo contenido, no pasa nada.
  const version = readFileSync(path.join(REPO_ROOT, 'VERSION'))
  mkdirSync(IMG_ROOT, { recursive: true })
  writeFileSync(path.join(IMG_ROOT, 'VERSION'), version)
})

test('fichaje confirmado: entrada con saludo y hora, via PIN (mismo panel que la tarjeta)', async ({
  page,
}, testInfo) => {
  const outDir = path.join(IMG_ROOT, testInfo.project.name)
  mkdirSync(outDir, { recursive: true })

  await stubKioskApiWithPin(page)
  // Marca por defecto del producto (RF-PD-08): sin cliente ni datos reales
  // (regla dura 21), que es justo lo que debe verse en una captura generica.
  await stubBrandingApi(page)
  await stubPinScanApi(page, 'clock_in')
  // No pausa los temporizadores (la tuberia del PIN los usa de verdad para
  // su ventana de gracia, `pinPipeline.ts`): solo fija lo que devuelven
  // `Date.now()`/`new Date()`.
  await page.clock.setFixedTime(CONFIRMED_AT)

  await page.goto('/')
  await page.getByTestId('pin-entry-link').click()
  await enterEmployeeCode(page, EMPLOYEE_CODE)
  await expect(page.getByTestId('pin-step-pin')).toBeVisible()
  await pressPinDigits(page, RAW_PIN)
  await page.getByTestId('pin-confirm').click()

  const panel = page.getByTestId('scan-confirmation')
  await expect(panel).toHaveAttribute('data-kind', 'accepted')
  await expect(page.getByTestId('confirmation-headline')).toContainText(DISPLAY_NAME)
  await expect(page.getByTestId('confirmation-detail')).toContainText('07:02')

  await page.screenshot({
    path: path.join(outDir, 'quiosco-fichaje-confirmado.png'),
    animations: 'disabled',
  })
})

test('fichaje pendiente: quiosco sin red, encolado y confirmado en local (regla dura 19)', async ({
  page,
}, testInfo) => {
  const outDir = path.join(IMG_ROOT, testInfo.project.name)
  mkdirSync(outDir, { recursive: true })

  await stubKioskApiWithPin(page)
  await stubBrandingApi(page)
  // Sin servidor: el envio no llega a ninguna parte, igual que
  // `pin.spec.ts` («sin red: se confirma en local, se encola sellado…»).
  await page.route('**/api/v1/scan/pin', async (route) => route.abort('failed'))

  await page.goto('/')
  await page.getByTestId('pin-entry-link').click()
  await enterEmployeeCode(page, EMPLOYEE_CODE)
  await expect(page.getByTestId('pin-step-pin')).toBeVisible()
  await pressPinDigits(page, RAW_PIN)
  await page.getByTestId('pin-confirm').click()

  const panel = page.getByTestId('scan-confirmation')
  await expect(panel).toHaveAttribute('data-kind', 'pending')
  await expect(page.getByTestId('confirmation-pending-badge')).toBeVisible()
  // Sin red de verdad, no solo un envio fallido: el indicador permanente lo
  // dice tambien (RF-KI-04), que es justo lo que la captura tiene que
  // ensenar («el quiosco sin red»).
  await expect(page.getByTestId('connection-status')).toHaveAttribute('data-status', 'offline')

  await page.screenshot({
    path: path.join(outDir, 'quiosco-fichaje-pendiente.png'),
    animations: 'disabled',
  })
})

test('PIN de respaldo: codigo de empleado ya escrito, teclado del PIN visible', async ({
  page,
}, testInfo) => {
  const outDir = path.join(IMG_ROOT, testInfo.project.name)
  mkdirSync(outDir, { recursive: true })

  await stubKioskApiWithPin(page)
  await stubBrandingApi(page)

  await page.goto('/')
  await page.getByTestId('pin-entry-link').click()
  await expect(page.getByTestId('pin-step-code')).toBeVisible()
  await enterEmployeeCode(page, EMPLOYEE_CODE)

  await expect(page.getByTestId('pin-step-pin')).toBeVisible()
  // A medio teclear: ensena el teclado numerico en uso (con el progreso de
  // puntos), sin completar el envio -- esta captura es de la pantalla, no
  // del desenlace.
  await pressPinDigits(page, RAW_PIN.slice(0, 3))

  await page.screenshot({
    path: path.join(outDir, 'quiosco-pin-respaldo.png'),
    animations: 'disabled',
  })
})
