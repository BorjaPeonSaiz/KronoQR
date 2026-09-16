// Pantalla de diagnostico del quiosco (RF-KI-08, tarea 3.3).
//
// Se abre con una pulsacion larga de 3 s sobre el reloj de `ScanView`/
// `PairingView` (`openDiagnostics`, mas abajo: mantiene el boton del raton
// pulsado, igual que un dedo en la tablet) y nunca depende de la red: el
// codigo de servicio se comprueba en local contra la huella que ya trae el
// ultimo latido (decision 6 de la tarea).

import AxeBuilder from '@axe-core/playwright'
import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import { stubKioskApi, stubScanApi } from './support/kiosk'
import { announceOnline, readQueue, seedQueue } from './support/offlineQueue'
import { stubPairing } from './support/pairing'

const DEVICE_ID = 'e2e-diagnostics-device'
const SERVICE_CODE = '48392017'

/** Mantiene pulsado el reloj los 3 s que exige `useLongPress` (decision 8). */
async function openDiagnostics(page: Page): Promise<void> {
  const trigger = page.getByTestId('diagnostics-trigger')
  await trigger.hover()
  await page.mouse.down()
  // 600 ms de holgura sobre los 3 000 ms del gesto: en un runner cargado el
  // `mouse.up()` no debe adelantarse al temporizador de `useLongPress`.
  await page.waitForTimeout(3_600)
  await page.mouse.up()
  await expect(page).toHaveURL(/\/diagnostics$/)
}

async function enterServiceCode(page: Page, code: string): Promise<void> {
  for (const digit of code) {
    await page.getByRole('button', { name: digit, exact: true }).click()
  }
  await page.getByTestId('diagnostics-code-confirm').click()
}

/**
 * `stubScanApi` es OBLIGATORIO en cada prueba de este fichero que visite `/`:
 * el proyecto `kiosk-qr` sirve un video con un QR REAL y VALIDO, asi que
 * `ScanView` decodifica y envia un fichaje SOLO, sin que nadie toque nada
 * (RF-KI-02). Sin doble, esa peticion llega al servidor de verdad
 * (`vite preview`), que devuelve `401` -y DOS 401 seguidos disparan la
 * revocacion del dispositivo (`deviceRevocation.ts`, RF-PD-06), que navegaria
 * fuera de `/diagnostics` en mitad de la prueba-.
 */

test.describe('pantalla de diagnostico (RF-KI-08, tarea 3.3)', () => {
  test(
    'pulsacion larga + codigo correcto abre las secciones, sin revelar el token, y muestra la cola',
    { tag: ['@RF-KI-08'] },
    async ({ page }) => {
      await stubKioskApi(page, { serviceCode: { deviceId: DEVICE_ID, code: SERVICE_CODE } })
      // `clock_in`, no `offline`: el fichaje que decodifica la camara SOLA se
      // confirma y sale de la cola, para que la fila sembrada de abajo sea la
      // UNICA que cuenta al final.
      await stubScanApi(page, { outcome: 'clock_in' })
      await page.goto('/')
      await expect(page.getByTestId('scan-confirmation')).toBeVisible()
      await expect.poll(() => readQueue(page).then((rows) => rows.length)).toBe(0)

      await seedQueue(page, [
        {
          scan_id: '0199f3c1-4a2b-7e55-9c10-8d7e6f5a4b32',
          occurred_at: new Date().toISOString(),
          qr_payload: 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
        },
      ])
      // `seedQueue` escribe directamente en IndexedDB, por detras de la
      // aplicacion (asi se prueba que la cola de verdad tiene la fila, no que
      // la cola se lee a si misma): el espejo en memoria que pinta
      // `diagnostics-queue-size` no se entera solo. `/scan/batch` sin doble
      // para que el intento de sincronizacion falle SIN sacar la fila de la
      // cola -el numero que se comprueba abajo tiene que seguir siendo 1-.
      await page.route('**/api/v1/scan/batch', async (route) => route.abort('failed'))
      await announceOnline(page)

      await openDiagnostics(page)
      await expect(page.getByTestId('diagnostics-gate')).toBeVisible()

      await enterServiceCode(page, SERVICE_CODE)

      await expect(page.getByTestId('diagnostics-content')).toBeVisible()
      for (const section of ['camera', 'network', 'queue', 'roster', 'token', 'version']) {
        await expect(page.getByTestId(`diagnostics-section-${section}`)).toBeVisible()
      }

      await expect(page.getByTestId('diagnostics-queue-size')).toHaveText('1')

      // El token nunca aparece en claro (regla dura 21, decision 9): solo su
      // identificador corto (ocho hex de `sha256(token)`), nunca el valor.
      const content = await page.content()
      expect(content).not.toContain('device-token-e2e')
    },
  )

  test('un codigo incorrecto no abre nada', { tag: ['@RF-KI-08'] }, async ({ page }) => {
    await stubKioskApi(page, { serviceCode: { deviceId: DEVICE_ID, code: SERVICE_CODE } })
    await stubScanApi(page, { outcome: 'offline' })
    await page.goto('/')
    await openDiagnostics(page)

    await enterServiceCode(page, '00000000')

    await expect(page.getByTestId('diagnostics-content')).not.toBeVisible()
    await expect(page.getByTestId('diagnostics-gate')).toBeVisible()
  })

  test(
    'sin red, la pantalla se sigue abriendo (regla dura 19)',
    { tag: ['@RF-KI-08'] },
    async ({ page }) => {
      // Sin codigo de servicio: es el caso que no depende de nada de red para
      // decidir si pide algo, y el que aisla mejor lo que se quiere probar
      // aqui -que la pantalla se abre sin conexion-.
      await stubKioskApi(page)
      await stubScanApi(page, { outcome: 'offline' })
      await page.goto('/')

      // Primera apertura EN LINEA: dentro de la misma pagina, el modulo de
      // `DiagnosticsView.vue` (cargado con `import()`) queda en la cache de
      // modulos del navegador para el resto de esta sesion -exactamente como
      // quedaria en una tablet real tras la primera vez que alguien abre el
      // diagnostico-, asi que la SEGUNDA apertura no necesita red para el
      // propio codigo de la pantalla.
      await openDiagnostics(page)
      await expect(page.getByTestId('diagnostics-content')).toBeVisible()
      await page.getByTestId('diagnostics-back').click()
      await expect(page).toHaveURL(/\/$/)

      await page.context().setOffline(true)
      try {
        await openDiagnostics(page)
        await expect(page.getByTestId('diagnostics-content')).toBeVisible()
      } finally {
        await page.context().setOffline(false)
      }
    },
  )

  test(
    'una tablet sin emparejar abre el diagnostico desde /pair, sin codigo',
    { tag: ['@RF-KI-08'] },
    async ({ page }) => {
      await stubPairing(page, { pendingPolls: 999 })
      await page.goto('/pair')
      await expect(page.getByTestId('pairing-code')).toBeVisible()

      await openDiagnostics(page)

      await expect(page.getByTestId('diagnostics-content')).toBeVisible()
      await expect(page.getByTestId('diagnostics-gate')).not.toBeVisible()
      await expect(page.getByTestId('diagnostics-no-code')).toBeVisible()
    },
  )

  test(
    'abrir el diagnostico desde /pair, emparejar y recibir dos 401 seguidos vuelve a /pair (RF-PD-06)',
    { tag: ['@RF-KI-08', '@RF-PD-06'] },
    async ({ page }) => {
      // Sin `serviceCode`: una tablet sin emparejar no tiene huella cacheada,
      // y el diagnostico visto desde aqui NO crea el controlador de la cola
      // (revision de la 3.3, segunda vuelta) -asi que esta prueba, ademas de
      // la revocacion, confirma que abrir y cerrar el diagnostico antes de
      // emparejar no deja nada a medio montar que estorbe despues-.
      // Bastantes sondeos «pending» (a 1 s de cadencia) para que el desvio
      // por el diagnostico -pulsacion larga de 3 s incluida- no alcance a
      // completar la vinculacion antes de volver aqui. Con 15 sondeos y 20 s
      // de espera la revision de QA midio 17,6 s: demasiado justo.
      await stubPairing(page, { pendingPolls: 25 })
      await page.goto('/pair')
      await expect(page.getByTestId('pairing-code')).toBeVisible()

      await openDiagnostics(page)
      await expect(page.getByTestId('diagnostics-content')).toBeVisible()
      await expect(page.getByTestId('diagnostics-no-code')).toBeVisible()
      await page.getByTestId('diagnostics-back').click()

      // El guard del router manda esto mismo de vuelta a `/pair` (sigue sin
      // token): la pantalla de emparejamiento se remonta y retoma el sondeo.
      await expect(page).toHaveURL(/\/pair$/)

      // El sondeo confirma la vinculacion sola (regla dura 19) y navega a la
      // pantalla de fichaje, que es la que crea el controlador de la cola
      // -la UNICA vez en esta prueba, con el `onDeviceRevoked` de `ScanView`-.
      await expect(page).toHaveURL(/\/$/, { timeout: 40_000 })

      // AHORA, con la tablet ya emparejada, el latido Y el padron -los dos
      // canales que `ScanView` toca nada mas montarse- empiezan a rechazar
      // con `401`. Dos canales distintos, cada uno con exito ninguno de por
      // medio, bastan para el umbral de dos de `deviceRevocation.ts`: no
      // hace falta esperar al segundo ciclo del intervalo del latido (60 s).
      await page.route('**/api/v1/kiosk/heartbeat', async (route) => {
        await route.fulfill({
          status: 401,
          contentType: 'application/problem+json',
          body: JSON.stringify({
            type: 'urn:kronoqr:problem:unauthenticated',
            title: 'No autenticado',
            status: 401,
          }),
        })
      })
      await page.route('**/api/v1/kiosk/roster', async (route) => {
        await route.fulfill({
          status: 401,
          contentType: 'application/problem+json',
          body: JSON.stringify({
            type: 'urn:kronoqr:problem:unauthenticated',
            title: 'No autenticado',
            status: 401,
          }),
        })
      })

      await expect(page).toHaveURL(/\/pair$/, { timeout: 10_000 })
      await expect(page.getByTestId('pairing-code')).toBeVisible()
    },
  )

  test(
    'el fichaje sigue disponible al volver',
    { tag: ['@RF-KI-08', '@RF-AT-05'] },
    async ({ page }) => {
      await stubKioskApi(page)
      await stubScanApi(page, { outcome: 'clock_in' })
      await page.goto('/')

      await openDiagnostics(page)
      await expect(page.getByTestId('diagnostics-content')).toBeVisible()
      await page.getByTestId('diagnostics-back').click()

      await expect(page.getByTestId('scan-idle')).toBeVisible()
    },
  )
})

/** Etiquetas WCAG que se comprueban: A y AA hasta la 2.2 (doc 01 §6.5), como en `accessibility.spec.ts`. */
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

async function expectNoBlockingViolations(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags(WCAG_TAGS)
    .disableRules(['video-caption'])
    .analyze()
  const blocking = results.violations.filter(
    (violation) => violation.impact === 'critical' || violation.impact === 'serious',
  )
  expect(blocking, blocking.map((v) => `${v.id}: ${v.help}`).join('\n')).toEqual([])
}

test.describe('accesibilidad (RF-KI-08, RF-KI-06)', () => {
  test(
    'la puerta del codigo de servicio no tiene violaciones criticas ni graves',
    { tag: ['@RF-KI-08', '@RF-KI-06'] },
    async ({ page }) => {
      await stubKioskApi(page, { serviceCode: { deviceId: DEVICE_ID, code: SERVICE_CODE } })
      await stubScanApi(page, { outcome: 'offline' })
      await page.goto('/')
      await openDiagnostics(page)
      await expect(page.getByTestId('diagnostics-gate')).toBeVisible()

      await expectNoBlockingViolations(page)
    },
  )

  test(
    'el contenido del diagnostico tampoco',
    { tag: ['@RF-KI-08', '@RF-KI-06'] },
    async ({ page }) => {
      await stubKioskApi(page, { serviceCode: { deviceId: DEVICE_ID, code: SERVICE_CODE } })
      await stubScanApi(page, { outcome: 'offline' })
      await page.goto('/')
      await openDiagnostics(page)
      await enterServiceCode(page, SERVICE_CODE)
      await expect(page.getByTestId('diagnostics-content')).toBeVisible()

      await expectNoBlockingViolations(page)
    },
  )
})
