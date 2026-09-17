// Fichaje de pausa y aviso de desfase de reloj (RF-AT-12, RF-AT-10, tarea 3.5,
// ADR-024), con camara simulada (doc 02 §9.4).
//
// El backend real no participa: el doble de `/api/v1/scan` responde lo que la
// prueba pide, igual que en `scan.spec.ts`. Lo que se prueba aqui es el
// quiosco: el boton se arma y desarma, la intencion viaja en la peticion, y
// el aviso de desfase no bloquea nada.
//
// LA CARRERA QUE HAY QUE EVITAR. El video de la camara simulada arranca a
// decodificar la tarjeta en cuanto `getUserMedia` resuelve, y el boton
// «Pausa» necesita el primer latido (redondo pero real: una peticion
// interceptada) para aparecer. Sin nada mas, armar el boton «a tiempo» seria
// una carrera contra la camara. `delayCameraStart` (`support/kiosk.ts`)
// retrasa `getUserMedia` lo suficiente para que el boton este SIEMPRE listo
// antes de que exista nada que decodificar, sin tocar el codigo de produccion.

import { expect, test } from '@playwright/test'
import { delayCameraStart, stubKioskApi, stubScanApi } from './support/kiosk'
import { stubKioskApiWithPin } from './support/pin'

test.describe('boton «Pausa» (@RF-AT-12)', () => {
  test(
    'con el fichaje de pausa activado, el boton aparece, arma y encola break_start',
    { tag: ['@RF-AT-12'] },
    async ({ page }) => {
      await delayCameraStart(page, 1_500)
      await stubKioskApi(page, { breakClockingEnabled: true })
      const stub = await stubScanApi(page, { outcome: 'break_start', displayName: 'Lucia G.' })

      await page.goto('/')

      const toggle = page.getByTestId('break-toggle')
      await expect(toggle).toBeVisible()
      await expect(toggle).toHaveAttribute('aria-pressed', 'false')

      await toggle.click()
      await expect(toggle).toHaveAttribute('aria-pressed', 'true')
      await expect(page.getByTestId('break-armed-hint')).toContainText('Pasa tu tarjeta')

      // El primer (y unico) escaneo de la tarjeta llega DESPUES de armar: se
      // encola con `intent: 'break_start'`.
      await expect(page.getByTestId('scan-confirmation')).toBeVisible({ timeout: 10_000 })
      await expect.poll(() => stub.recorded.length).toBeGreaterThan(0)
      expect(stub.recorded[0]?.intent).toBe('break_start')

      // Y el servidor decidio `break_start`: la confirmacion lo dice, con el
      // color/sonido de salida (ADR-024) y la jornada sigue abierta.
      await expect(page.getByTestId('confirmation-detail')).toContainText('Pausa')
      await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-variant', 'exit')
      await expect(page.getByTestId('confirmation-break-open-hint')).toBeVisible()

      // Y el boton se desarmo solo, tras el fichaje (no hay que esperar 10 s):
      // la confirmacion que se ve ahora («Pausa 14:32») ya lo dice, asi que
      // no hace falta comprobar la region viva aqui -el bloque que la
      // contiene se oculta en el mismo instante, ver el otro escenario mas
      // abajo para el anuncio del desarme con la pantalla todavia visible.
      await expect(toggle).toHaveAttribute('aria-pressed', 'false')
    },
  )

  test(
    'sin armar nada, el mismo escaneo vuelve de la pausa (break_end) sin pulsar nada',
    { tag: ['@RF-AT-12'] },
    async ({ page }) => {
      await stubKioskApi(page, { breakClockingEnabled: true })
      const stub = await stubScanApi(page, { outcome: 'break_end', displayName: 'Lucia G.' })

      await page.goto('/')
      await expect(page.getByTestId('break-toggle')).toHaveAttribute('aria-pressed', 'false')

      await expect(page.getByTestId('scan-confirmation')).toBeVisible()
      await expect.poll(() => stub.recorded.length).toBeGreaterThan(0)
      expect(stub.recorded[0]?.intent).toBe('auto')

      await expect(page.getByTestId('confirmation-detail')).toContainText('Vuelta')
      await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-variant', 'entry')
      await expect(page.getByTestId('confirmation-break-open-hint')).toHaveCount(0)
    },
  )

  test('se desarma solo a los 10 s sin fichar', { tag: ['@RF-AT-12'] }, async ({ page }) => {
    // Sin tarjeta que fichar: el objeto de la prueba es el temporizador, no
    // el escaneo (y con la camara simulada normal el primer escaneo llegaria
    // antes de los 10 s y desarmaria el boton por el motivo equivocado).
    await delayCameraStart(page, 20_000)
    await stubKioskApi(page, { breakClockingEnabled: true })
    await stubScanApi(page, { outcome: 'offline' })

    await page.goto('/')

    const toggle = page.getByTestId('break-toggle')
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-pressed', 'true')

    // 15 s de margen sobre un temporizador de 10 s (medido 10,3 s en CI;
    // revision de la segunda vuelta: 11 s no dejaba margen suficiente).
    await expect(toggle).toHaveAttribute('aria-pressed', 'false', { timeout: 15_000 })
    // Y la region viva anuncia el desarme -sigue montada, ver `ScanView.vue`-,
    // con la pantalla de inicio todavia visible (nada se fichó).
    await expect(page.getByTestId('break-armed-hint')).toContainText('Pausa desarmada')
  })

  test(
    'sin el ajuste de la instalacion, el boton no existe',
    { tag: ['@RF-AT-12'] },
    async ({ page }) => {
      await stubKioskApi(page, { breakClockingEnabled: false })
      await stubScanApi(page)

      await page.goto('/')

      await expect(page.getByTestId('scan-idle')).toBeVisible()
      await expect(page.getByTestId('break-toggle')).toHaveCount(0)
    },
  )

  test(
    'cambiar de pantalla desarma el boton: la intencion es de la tablet, no de la persona (revision de seguridad)',
    { tag: ['@RF-AT-12'] },
    async ({ page }) => {
      // Sin camara activa: lo que se prueba es la navegacion, no un fichaje.
      await delayCameraStart(page, 20_000)
      await stubKioskApiWithPin(page, { breakClockingEnabled: true })

      await page.goto('/')

      const scanToggle = page.getByTestId('break-toggle')
      await expect(scanToggle).toBeVisible()
      await scanToggle.click()
      await expect(scanToggle).toHaveAttribute('aria-pressed', 'true')

      // Se navega a la pantalla de PIN (tarjeta olvidada, por ejemplo).
      await page.getByTestId('pin-entry-link').click()
      await expect(page.getByTestId('pin-step-code')).toBeVisible()

      // El MISMO boton -mismo singleton- llega desarmado: no sobrevive al
      // cambio de pantalla, aunque sea la misma tablet y la misma sesion.
      const pinToggle = page.getByTestId('break-toggle')
      await expect(pinToggle).toBeVisible()
      await expect(pinToggle).toHaveAttribute('aria-pressed', 'false')
    },
  )
})

test.describe('aviso de desfase de reloj (@RF-AT-10)', () => {
  test(
    'reloj de servidor 40 min desviado: la banda avisa y el fichaje se sigue confirmando',
    { tag: ['@RF-AT-10'] },
    async ({ page }) => {
      const skewedServerTime = new Date(Date.now() - 40 * 60 * 1000).toISOString()
      await stubKioskApi(page, {
        clockSkewToleranceSeconds: 900,
        serverTime: () => skewedServerTime,
      })
      const stub = await stubScanApi(page, { outcome: 'clock_in', displayName: 'Lucia G.' })

      await page.goto('/')

      const banner = page.getByTestId('clock-skew-banner')
      await expect(banner).toBeVisible({ timeout: 5_000 })
      await expect(banner).toHaveAttribute('role', 'status')
      await expect(banner).toContainText('40 min')

      // El fichaje se registra igual: nunca se bloquea por desfase (regla dura 19).
      await expect(page.getByTestId('scan-confirmation')).toBeVisible()
      await expect.poll(() => stub.recorded.length).toBeGreaterThan(0)
      await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'accepted')
    },
  )

  test(
    'dentro de la tolerancia, no aparece ninguna banda',
    { tag: ['@RF-AT-10'] },
    async ({ page }) => {
      await stubKioskApi(page, { clockSkewToleranceSeconds: 900 })
      await stubScanApi(page)

      await page.goto('/')
      await expect(page.getByTestId('scan-idle')).toBeVisible()

      await expect(page.getByTestId('clock-skew-banner')).toHaveCount(0)
    },
  )
})
