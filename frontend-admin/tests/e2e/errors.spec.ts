// Historico de errores agrupado por huella (RF-PD-15, tarea 5.12).
//
// El backend no participa: aqui se prueba el recorrido por el panel con la API
// simulada en `support/errors.ts`. La autorizacion negativa por rol, el
// saneado en servidor y la agrupacion por huella se prueban en el backend
// (regla dura 18).
import { expect, test } from '@playwright/test'
import { ADMIN_USER, logInAsAdmin, stubManagementApi } from './support/admin'
import {
  ERROR_EVENT_ID,
  KIOSK_CRITICAL_ERROR,
  stubClientErrorsEndpoint,
  stubErrorEventsApi,
} from './support/errors'

/**
 * `DEFAULT_INTERVAL_MS` de `packages/web-kit/src/clientErrorTransport.ts`. Se
 * repite aqui en vez de importarse (ningun E2E de este proyecto importa
 * codigo de `packages/`): es lo que tarda el transporte en reintentar el
 * envio pendiente si el primero falla. Mismo patron que
 * `frontend-portal/tests/e2e/client-errors.spec.ts`.
 */
const RETRY_INTERVAL_MS = 60_000

test(
  'el IT del cliente encuentra el error provocado, con su origen, nivel y recuento',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)

    await logInAsAdmin(page)
    await page.goto('/errors')

    await expect(page.getByRole('heading', { level: 1, name: 'Errores' })).toBeVisible()

    const row = page.getByTestId('error-row')

    await expect(row).toBeVisible()
    await expect(row).toContainText('Crítico')
    await expect(row).toContainText('La cámara ha dejado de responder')
    await expect(row).toContainText('12')
  },
)

test(
  'filtrar por nivel vuelve a pedir la pagina con ese filtro',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)

    await logInAsAdmin(page)
    await page.goto('/errors')
    await expect(page.getByTestId('error-row')).toBeVisible()

    await page.getByLabel('Nivel').selectOption('critical')
    await expect(page.getByTestId('error-row')).toBeVisible()

    await page.getByLabel('Nivel').selectOption('error')
    await expect(page.getByTestId('error-row')).toHaveCount(0)
    await expect(page.getByText('Sin errores pendientes')).toBeVisible()
  },
)

test(
  'expandir la fila enseña el detalle, el bloque «que hacer», y el trace_id se copia',
  { tag: ['@RF-PD-15'] },
  async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write'])
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)

    await logInAsAdmin(page)
    await page.goto('/errors')

    await page.getByTestId(`toggle-${ERROR_EVENT_ID}`).click()

    const whatToDo = page.getByTestId('what-to-do')

    await expect(whatToDo).toBeVisible()
    await expect(whatToDo).toContainText('La tablet indicada no puede escanear')

    await page.getByTestId(`copy-trace-${ERROR_EVENT_ID}`).click()

    const clipboard = await page.evaluate(() => navigator.clipboard.readText())

    expect(clipboard).toBe(KIOSK_CRITICAL_ERROR.trace_id)
  },
)

test(
  'marcarlo como resuelto lo saca de la vista de pendientes',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)

    await logInAsAdmin(page)
    await page.goto('/errors')
    await expect(page.getByTestId('error-row')).toBeVisible()

    await page.getByTestId(`resolve-${ERROR_EVENT_ID}`).click()

    const dialog = page.getByRole('dialog', { name: 'Marcar como resuelto' })
    await expect(dialog).toBeVisible()

    await dialog.getByRole('button', { name: 'Marcar como resuelto' }).click()

    await expect(page.getByRole('dialog')).not.toBeVisible()
    await expect(page.getByTestId('error-row')).toHaveCount(0)
    await expect(page.getByText('Sin errores pendientes')).toBeVisible()
  },
)

// La ocultacion del boton de resolver para un acceso de soporte con alcance
// `diagnostics`/`read_only` (decision 8 de la ficha) se prueba a nivel de
// componente en `tests/unit/ErrorsView.spec.ts`, que puede controlar
// `abilities` con precision; `stubManagementApi` (`support/admin.ts`) solo
// modela los seis roles de aplicacion, ninguno de los cuales es un acceso de
// soporte. La autorizacion negativa real -que el servidor rechace con `403`
// a cualquier acceso de soporte, sea cual sea su alcance- se prueba en el
// backend (regla dura 18).

test(
  'un fallo al reportar un error de cliente no bloquea el panel ni reintenta en bucle',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    // Reloj falso ANTES de navegar: el reintento periodico es cada 60 s
    // reales y esperar eso de verdad haria la prueba lenta y fragil. Mismo
    // patron que `frontend-portal/tests/e2e/client-errors.spec.ts`.
    await page.clock.install({ time: new Date('2026-03-09T08:00:00.000Z') })

    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)
    const clientErrors = stubClientErrorsEndpoint(page)

    await page.goto('/login')
    // `install()` por si solo NO congela el tiempo: los timers del reloj
    // falso siguen avanzando al ritmo real hasta que se llama a `pauseAt`,
    // `runFor`, `fastForward` o `resume` (documentado en `Clock.pauseAt`).
    // Sin este paso, el tiempo real que tarda el login (unos segundos) se
    // sumaba al avance virtual posterior y desbordaba el intervalo de 60 s
    // antes de lo esperado -hallazgo de `revisor-codigo`-. Se pausa a un
    // instante FUTURO respecto al de instalacion (nunca al mismo: el reloj ya
    // ha avanzado con la carga de la pagina y retroceder falla con «Cannot
    // fast-forward to the past»), con margen de sobra sobre lo que tarda
    // cargar `/login` y muy por debajo del intervalo de 60 s que se mide
    // despues.
    await page.clock.pauseAt(new Date('2026-03-09T08:00:05.000Z'))

    // Un error real por el mismo camino que uno de produccion, sin pasar por
    // Playwright: se dispara un `ErrorEvent` de verdad sobre `window`, que es
    // exactamente lo que el navegador entrega al listener que instala
    // `installGlobalErrorCapture` (`window.addEventListener('error', …)`) -
    // ANTES de iniciar sesion, para que el transporte lo vacie justo al pasar
    // a autenticado (decision 7 de la ficha 5.12), sin depender del intervalo
    // periodico de 60 s, que un E2E no puede esperar-. Con el reloj falso
    // instalado no vale lanzar la excepcion dentro de un `setTimeout(0)`: el
    // reloj emula el temporizador con una llamada directa, no con una tarea
    // nueva del navegador, y esa excepcion nunca llega a convertirse en un
    // evento `error` del `window` (comprobado).
    await page.evaluate(() => {
      window.dispatchEvent(
        new ErrorEvent('error', {
          message: 'boom-e2e',
          error: new Error('boom-e2e'),
          filename: `${window.location.origin}/e2e.js`,
          lineno: 1,
        }),
      )
    })

    await page.getByLabel(/Correo electrónico/).fill(ADMIN_USER.email)
    await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
    await page.getByRole('button', { name: 'Entrar' }).click()
    await page.waitForURL('**/employees')

    await expect.poll(() => clientErrors.count(), { timeout: 5_000 }).toBeGreaterThanOrEqual(1)

    // El panel sigue funcionando tras el 500: se puede seguir navegando. Por
    // el propio menu (enrutado en el cliente), NUNCA con `page.goto` -que es
    // una recarga completa del navegador y vaciaria el buffer en memoria del
    // transporte junto con el reloj congelado, dejando el reintento
    // posterior sin nada que reenviar (comprobado)-.
    await page
      .getByRole('banner')
      .getByRole('navigation')
      .getByRole('link', { name: 'Errores' })
      .click()
    await expect(page.getByRole('heading', { level: 1, name: 'Errores' })).toBeVisible()

    const firstCount = clientErrors.count()

    // Reintento periodico, no en bucle: con el reloj congelado desde
    // `pauseAt` (nada de deriva real de por medio), se avanza el intervalo
    // de 60 s COMPLETO de una vez y el recuento sube EXACTAMENTE uno -ni cero
    // (no ha reintentado) ni mas de uno (bucle)-, que es la propiedad que
    // dice el titulo de la prueba. Reemplaza el `waitForTimeout(3_000)` real:
    // determinista, cubre el intervalo entero y no tarda esos 60 s de verdad.
    // Y se ESPERA a que la peticion llegue: el `fetch` que arma el temporizador
    // viaja de forma asincrona hasta la ruta interceptada, y leerlo en la misma
    // vuelta del bucle es la carrera que fallaba de forma intermitente en el
    // portal (misma prueba, mismo patron; corregido en la 3.10).
    await page.clock.runFor(RETRY_INTERVAL_MS)
    await expect.poll(() => clientErrors.count(), { timeout: 5_000 }).toBe(firstCount + 1)
  },
)

test(
  'un error real sin capturar por el codigo llega al transporte tal cual',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    // SIN reloj falso: la prueba de arriba ya demuestra el reintento
    // periodico con el tiempo congelado; esta demuestra el otro extremo, que
    // la captura global de verdad atrapa una excepcion no capturada -no un
    // doble de Playwright-.
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)
    const clientErrors = stubClientErrorsEndpoint(page)

    await page.goto('/login')

    // Un `<script>` insertado que lanza al ejecutarse produce el mismo
    // `error` no capturado que entrega el navegador en produccion:
    // `page.addScriptTag` no envuelve la ejecucion en un try/catch como
    // `page.evaluate` (que SI intercepta la excepcion y la devuelve a
    // Playwright, sin pasar nunca por `window.addEventListener('error', …)`
    // -el mismo listener que instala `installGlobalErrorCapture`, comprobado
    // en la prueba anterior mientras usaba ese camino-). Mensaje sin datos
    // personales (regla dura 21).
    await page.addScriptTag({ content: 'throw new Error("boom-e2e-uncaught")' })

    await page.getByLabel(/Correo electrónico/).fill(ADMIN_USER.email)
    await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
    await page.getByRole('button', { name: 'Entrar' }).click()
    await page.waitForURL('**/employees')

    await expect.poll(() => clientErrors.count(), { timeout: 5_000 }).toBeGreaterThanOrEqual(1)
    // `describe()` (`clientErrors.ts`) antepone el nombre del constructor:
    // «Error: boom-e2e-uncaught» es el mensaje saneado tal cual lo produce el
    // codigo de produccion, no un formato inventado por esta prueba.
    expect(clientErrors.messages()).toContain('Error: boom-e2e-uncaught')
  },
)
