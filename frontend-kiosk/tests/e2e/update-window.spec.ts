// Ventana de actualizacion del quiosco (RF-KI-07, tarea 3.12).
//
// PROBAR UNA VERSION NUEVA DE VERDAD exigiria publicar dos builds distintos
// del service worker en el mismo E2E: viable, pero desproporcionado para lo
// que esta puerta necesita probar. Estas pruebas usan el gancho ACOTADO de
// `src/sw/testHooks.ts` (`window.__kronoqrTest`, activado con
// `window.__KRONOQR_ENABLE_TEST_HOOKS__` ANTES de que arranque la
// aplicacion, mismo patron que `pairDevice`/`stubKioskApi` con
// `page.addInitScript`) para forzar «hay version pendiente» y dejar que la
// puerta real (`canApply`, `features/offline/domain/updateWindow.ts`) y el
// temporizador de reintento (`registerServiceWorker.ts`) decidan.
//
// Sin `update_window` en el latido (`stubKioskApi` no lo declara), el
// quiosco usa la ventana DE SERIE: `03:00-05:00`, 10 min de silencio
// (decision 9 de la tarea 3.12).

import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
// Solo por su `declare global` (`window.__KRONOQR_ENABLE_TEST_HOOKS__` y
// `window.__kronoqrTest`): sin este import de tipo, este fichero no forma
// parte del mismo programa de TypeScript que `src/sw/testHooks.ts` y el
// compilador no conoceria esas dos propiedades de `Window`.
import type {} from '@/sw/testHooks'
import { stubKioskApi, stubScanApi } from './support/kiosk'
import { announceOnline, readQueue, stubBatchApi } from './support/offlineQueue'

/** Deja la señal ANTES de que arranque cualquier script de la pagina (ver la cabecera). */
async function enableTestHooks(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.__KRONOQR_ENABLE_TEST_HOOKS__ = true
  })
}

async function simulateUpdateAvailable(page: Page): Promise<void> {
  await page.evaluate(() => window.__kronoqrTest?.simulateUpdateAvailable())
}

async function hasApplied(page: Page): Promise<boolean> {
  return page.evaluate(() => window.__kronoqrTest?.hasAppliedUpdate() ?? false)
}

/** 04:00 en Madrid en enero (CET, sin horario de verano): dentro de 03:00-05:00. */
const INSIDE_WINDOW = new Date('2030-01-15T04:00:00+01:00')
/** 11:00 en Madrid: claramente fuera de la ventana de serie. */
const OUTSIDE_WINDOW = new Date('2030-01-15T11:00:00+01:00')

test.beforeEach(async ({ page }) => {
  await stubKioskApi(page)
  await enableTestHooks(page)
})

test(
  'fuera de la ventana, el quiosco sigue fichando y no se actualiza',
  { tag: ['@RF-KI-07'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'clock_in' })
    await page.goto('/')

    // El escaneo automatico del video de la camara se confirma y sale de la
    // cola: la cola esta vacia, que es justo el caso en el que la ventana es
    // la UNICA condicion que falta.
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    await expect.poll(() => readQueue(page).then((rows) => rows.length)).toBe(0)

    await page.clock.install({ time: OUTSIDE_WINDOW })
    await simulateUpdateAvailable(page)
    await page.clock.runFor(0)

    // No se aplica: sigue en la misma pagina, sin recargar.
    expect(await hasApplied(page)).toBe(false)

    // Y el quiosco sigue fichando: el indicador de conexion sigue vivo y la
    // pantalla no ha cambiado de la de escaneo.
    await expect(page.getByTestId('connection-status')).toBeVisible()
    await expect(page).toHaveURL(/\/$/)
  },
)

test(
  'dentro de la ventana y con la cola vacia, se aplica',
  { tag: ['@RF-KI-07'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'clock_in' })
    await page.goto('/')

    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    await expect.poll(() => readQueue(page).then((rows) => rows.length)).toBe(0)

    await page.clock.install({ time: INSIDE_WINDOW })
    await simulateUpdateAvailable(page)

    await expect.poll(() => hasApplied(page)).toBe(true)
  },
)

test(
  'con la cola llena no se aplica; al vaciarse dentro de la ventana, se aplica sin perder nada',
  { tag: ['@RF-KI-04', '@RF-KI-07', '@RQ-05'] },
  async ({ page }) => {
    // Sin servidor para `/scan`: el fichaje automatico de la camara se queda
    // encolado, y la cola deja de estar vacia.
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    const batch = await stubBatchApi(page)

    await page.goto('/')
    await expect.poll(() => readQueue(page).then((rows) => rows.length)).toBeGreaterThan(0)

    await page.clock.install({ time: INSIDE_WINDOW })
    await simulateUpdateAvailable(page)
    await page.clock.runFor(0)

    // Dentro de la ventana, pero con cola: no se aplica.
    expect(await hasApplied(page)).toBe(false)

    // La red vuelve y la cola se vacia.
    await page.unroute('**/api/v1/scan')
    await announceOnline(page)
    await expect.poll(() => readQueue(page).then((rows) => rows.length)).toBe(0)
    expect(batch.calls.length).toBeGreaterThan(0)

    // El temporizador de reintento (cada minuto, decision 10 de la tarea
    // 3.12) la aplica sin que nadie vuelva a pedirlo.
    await page.clock.runFor(60_000)
    await expect.poll(() => hasApplied(page)).toBe(true)

    // Nada se perdio: la cola sigue vacia tras aplicarse.
    expect(await readQueue(page)).toHaveLength(0)
  },
)

/** Pulsacion larga de 3 s sobre el reloj, mismo patron que `diagnostics.spec.ts`. */
async function openDiagnostics(page: Page): Promise<void> {
  const trigger = page.getByTestId('diagnostics-trigger')
  await trigger.hover()
  await page.mouse.down()
  await page.clock.runFor(3_000)
  await page.mouse.up()
  await expect(page).toHaveURL(/\/diagnostics$/)
}

test(
  'el diagnostico enseña la version y que hay una actualizacion pendiente, con su ventana',
  { tag: ['@RF-KI-08'] },
  async ({ page }) => {
    await stubScanApi(page, { outcome: 'offline' })
    await page.goto('/')

    // Fuera de la ventana: el intento inmediato del gancho al simular no se
    // resuelve solo, y `pending` se queda estable para que el diagnostico lo
    // enseñe (ver la primera prueba de este fichero).
    await page.clock.install({ time: OUTSIDE_WINDOW })
    await simulateUpdateAvailable(page)
    await page.clock.runFor(0)
    expect(await hasApplied(page)).toBe(false)

    await openDiagnostics(page)

    await expect(page.getByTestId('diagnostics-content')).toBeVisible()
    await expect(page.getByTestId('diagnostics-app-version')).not.toBeEmpty()
    await expect(page.getByTestId('diagnostics-update-status')).toContainText('03:00')
    await expect(page.getByTestId('diagnostics-update-status')).toContainText('05:00')
    await expect(page.getByTestId('diagnostics-update-window')).toHaveText('03:00–05:00')
  },
)
