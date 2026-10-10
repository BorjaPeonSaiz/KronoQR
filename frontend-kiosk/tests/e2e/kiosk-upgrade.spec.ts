// Un quiosco ABIERTO durante la actualizacion del servidor sigue fichando por
// PIN (tarea 3.1 de la 2.2.1; RF-KI-07, RF-AT-11, RF-PD-10).
//
// EL INCIDENTE QUE ESTO CIERRA. En la 2.1.0 la CSP de Nginx no llevaba
// `'wasm-unsafe-eval'`: libsodium no compilaba y el PIN salia «Código no
// válido». La 2.2.0 arreglo el snippet, pero una tablet que ya estaba abierta
// sigue ejecutando el documento VIEJO -servido desde la cache del service
// worker con las cabeceras que tenia al precachearse- hasta que aplica la
// version nueva. `pin-csp.spec.ts` solo cubre la instalacion limpia; aqui se
// prueba el salto: build N bajo la CSP de la 2.1.0, build N+1 bajo la del
// snippet real, publicado a mitad de prueba en el MISMO origen.
//
// LA PRUEBA SOLO PASA SI LA VERSION NUEVA SE APLICA DE VERDAD: la tablet
// declara N+1 en el latido, su documento llega con la CSP nueva y el PIN se
// sella con el WebAssembly real. Con la puerta anterior a la 2.2.1 (solo
// dentro de la ventana 03:00-05:00) y el reloj de la pagina a las 11:00, la
// version no se aplica y el PIN del documento viejo no se puede sellar.
//
// Proyecto `kiosk-upgrade` (`playwright.config.ts`): camara sin QR delante. Con
// la tarjeta del video de siempre, cada escaneo cerraria la puerta durante el
// silencio de 2 min del modo urgente y la actualizacion no llegaria nunca.

import { Buffer } from 'node:buffer'
import { mkdtemp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { expect, test } from '@playwright/test'
import {
  KIOSK_BASE_PATH,
  buildKioskRelease,
  recordLoadedDocuments,
  securityHeadersOf210,
  startKioskReleaseServer,
  stubUpgradeHeartbeat,
} from './support/kioskReleases'
import type { KioskReleaseServer } from './support/kioskReleases'
import {
  enterEmployeeCode,
  pressPinDigits,
  stubKioskApiWithPin,
  stubPinScanApi,
} from './support/pin'
import { readProductionSecurityHeaders } from './support/securityHeaders'

const KIOSK_UPGRADE_FROM_VERSION = '7.0.0'
const KIOSK_UPGRADE_TO_VERSION = '7.0.1'
const KIOSK_UPGRADE_EMPLOYEE_CODE = 'E7QK2MXPR'
const KIOSK_UPGRADE_RAW_PIN = '483920'
// `crypto_box_seal`: 32 bytes de clave efimera + 16 de MAC + los 6 del PIN.
const KIOSK_UPGRADE_SEALED_BYTES = 54
/** 11:00 en Madrid en enero: fuera de la ventana de serie 03:00-05:00. Solo el modo urgente aplica aqui. */
const KIOSK_UPGRADE_OUTSIDE_WINDOW = new Date('2030-01-15T11:00:00+01:00')
/** Un intervalo del latido (`DEFAULT_HEARTBEAT_INTERVAL_MS`). */
const KIOSK_UPGRADE_HEARTBEAT_MS = 60_000
const KIOSK_UPGRADE_BLOCKED = /CompileError|WebAssembly|wasm|Content Security Policy|Refused to/i

const productionHeaders = readProductionSecurityHeaders()
const headers210 = securityHeadersOf210(productionHeaders)

let buildsDir = ''
let server: KioskReleaseServer | null = null

test.beforeAll(async () => {
  test.setTimeout(120_000)
  buildsDir = await mkdtemp(join(tmpdir(), 'kronoqr-kiosk-upgrade-'))
  await buildKioskRelease(KIOSK_UPGRADE_FROM_VERSION, join(buildsDir, 'from'))
  await buildKioskRelease(KIOSK_UPGRADE_TO_VERSION, join(buildsDir, 'to'))
})

test.afterEach(async () => {
  await server?.close()
  server = null
})

test.afterAll(async () => {
  await rm(buildsDir, { recursive: true, force: true })
})

test(
  'una tablet abierta con la CSP de la 2.1.0 aplica la version nueva sin recargarla nadie y ficha por PIN',
  { tag: ['@RF-AT-11', '@RF-KI-07', '@RF-PD-10', '@RS-09'] },
  async ({ page }) => {
    // Arrange: tablet con la version N, controlada por su service worker, cuyo
    // documento lleva la CSP de la 2.1.0.
    server = await startKioskReleaseServer({
      distDir: join(buildsDir, 'from'),
      headers: headers210,
    })
    const browserDiagnostics: string[] = []
    page.on('pageerror', (error) =>
      browserDiagnostics.push(`pageerror: ${error.name}: ${error.message}`),
    )
    page.on('console', (message) =>
      browserDiagnostics.push(`console.${message.type()}: ${message.text()}`),
    )
    await page.addInitScript(() => {
      document.addEventListener('securitypolicyviolation', (event) => {
        console.error(`Content Security Policy violation: ${event.violatedDirective}`)
      })
    })
    await page.clock.install({ time: KIOSK_UPGRADE_OUTSIDE_WINDOW })
    const clockInstalledAt = Date.now()
    await stubKioskApiWithPin(page)
    const heartbeat = await stubUpgradeHeartbeat(page, () =>
      new Date(
        KIOSK_UPGRADE_OUTSIDE_WINDOW.getTime() + (Date.now() - clockInstalledAt),
      ).toISOString(),
    )
    const pinApi = await stubPinScanApi(page, 'clock_in')
    const documents = recordLoadedDocuments(page)

    const kioskUrl = `${server.origin}${KIOSK_BASE_PATH}`
    await page.goto(kioskUrl)
    await page.evaluate(async () => {
      await navigator.serviceWorker.ready
    })
    await page.reload()
    await expect
      .poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null))
      .toBe(true)
    await expect.poll(() => heartbeat.appVersions.at(-1)).toBe(KIOSK_UPGRADE_FROM_VERSION)
    expect(documents.at(-1)).toEqual({
      fromServiceWorker: true,
      csp: headers210['Content-Security-Policy'],
    })

    // Act: el servidor se actualiza (build N+1 con la CSP del snippet) y el
    // siguiente latido declara N+1 como minima. Nadie toca la tablet.
    server.publish({ distDir: join(buildsDir, 'to'), headers: productionHeaders })
    heartbeat.announceMinimumAppVersion(KIOSK_UPGRADE_TO_VERSION)
    await page.clock.runFor(KIOSK_UPGRADE_HEARTBEAT_MS)

    // Assert: la tablet ha recargado ella sola en la version nueva y su
    // documento lleva la CSP de produccion.
    await expect
      .poll(() => heartbeat.appVersions.at(-1), {
        message: 'la tablet no ha aplicado la version nueva',
        timeout: 30_000,
      })
      .toBe(KIOSK_UPGRADE_TO_VERSION)
    expect(documents.at(-1)).toEqual({
      fromServiceWorker: true,
      csp: productionHeaders['Content-Security-Policy'],
    })

    // Y ficha por PIN con el WebAssembly real.
    // PIN-03: el enlace solo aparece si libsodium puede sellar en ESTE documento.
    await expect(page.getByTestId('pin-entry-link')).toBeVisible()
    const diagnosticsBeforePin = browserDiagnostics.length
    await page.getByTestId('pin-entry-link').click()
    await enterEmployeeCode(page, KIOSK_UPGRADE_EMPLOYEE_CODE)
    await pressPinDigits(page, KIOSK_UPGRADE_RAW_PIN)
    await page.getByTestId('pin-confirm').click()

    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute(
      'data-kind',
      /^(accepted|rejected)$/,
      { timeout: 10_000 },
    )
    const blocked = browserDiagnostics
      .slice(diagnosticsBeforePin)
      .filter((text) => KIOSK_UPGRADE_BLOCKED.test(text))
    expect(blocked, blocked.join('\n')).toEqual([])
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted')
    expect(pinApi.recorded).toHaveLength(1)
    expect(Buffer.from(pinApi.recorded[0]?.pinSealed ?? '', 'base64')).toHaveLength(
      KIOSK_UPGRADE_SEALED_BYTES,
    )
  },
)
