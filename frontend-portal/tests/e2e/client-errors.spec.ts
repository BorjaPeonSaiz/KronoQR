// Reporte de errores de cliente hacia el historico del panel (RF-PD-15,
// tarea 5.12). El backend no participa: lo que se prueba aqui es que el
// portal capta un error real, lo manda a `POST /api/v1/client-errors` en
// cuanto hay sesion, y que un fallo al reportarlo NO bloquea el portal ni
// reintenta en bucle. El saneado en servidor, la agrupacion por huella y la
// autorizacion negativa se prueban en el backend (regla dura 18); mismo
// patron que `frontend-admin/tests/e2e/errors.spec.ts`.
import { expect, test } from '@playwright/test'
import {
  PORTAL_EMPLOYEE_CODE,
  PORTAL_PIN,
  stubClientErrorsEndpoint,
  stubPortalApi,
} from './support/portal'

/**
 * `DEFAULT_INTERVAL_MS` de `packages/web-kit/src/clientErrorTransport.ts`. Se
 * repite aqui en vez de importarse (ningun E2E de este proyecto importa
 * codigo de `packages/`): es lo que tarda el transporte en reintentar el
 * envio pendiente si el primero falla.
 */
const RETRY_INTERVAL_MS = 60_000

test(
  'un fallo al reportar un error de cliente no bloquea el portal ni reintenta en bucle',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    // Reloj falso ANTES de navegar, para que el `setInterval` de 60 s que el
    // transporte arma al arrancar la app sea un temporizador FALSO y no uno
    // real del navegador (instalarlo mas tarde no lo capturaria: comprobado).
    // Mismo patron que `frontend-kiosk/tests/e2e/heartbeat-errors.spec.ts`.
    await page.clock.install({ time: new Date('2026-03-09T08:00:00.000Z') })

    await stubPortalApi(page, { locale: 'es' })
    const clientErrors = stubClientErrorsEndpoint(page)

    await page.goto('/login')

    // `install()` FIJA el instante de partida pero NO para el reloj: el
    // tiempo real que tarde en cargar la pagina se sigue sumando hasta que
    // algo lo pare (comprobado: `runFor` por si solo hereda ese arrastre y
    // el reintento puede dispararse antes de lo que la prueba cree). Se
    // detiene aqui, justo tras cargar: se lee el instante que ya lleva el
    // reloj de la propia pagina -no uno inventado por la prueba- y se le
    // suma un margen de 2 s para el propio viaje de ida y vuelta de esta
    // llamada (`pauseAt` rechaza un instante que ya haya quedado en el
    // pasado para el reloj; comprobado con `--repeat-each` que sin margen
    // falla de forma intermitente). Desde aqui en adelante el tiempo virtual
    // lo conduce solo `runFor`, nunca el reloj de pared de quien ejecuta la
    // prueba.
    const pausedAt = (await page.evaluate(() => Date.now())) + 2_000
    await page.clock.pauseAt(pausedAt)

    // Un error real por el mismo camino que uno de produccion, sin pasar por
    // Playwright: se dispara un `ErrorEvent` de verdad sobre `window`, que
    // es exactamente lo que el navegador entrega al listener que instala
    // `installGlobalErrorCapture` (`window.addEventListener('error', …)`) -
    // ANTES de iniciar sesion, para que el transporte lo vacie justo al
    // pasar a autenticado, sin depender del intervalo periodico de 60 s, que
    // un E2E no puede esperar-. Con el reloj falso instalado no vale lanzar
    // la excepcion dentro de un `setTimeout(0)`: el reloj emula el
    // temporizador con una llamada directa, no con una tarea nueva del
    // navegador, y esa excepcion nunca llega a convertirse en un evento
    // `error` del `window` (comprobado).
    await page.evaluate(() => {
      window.dispatchEvent(
        new ErrorEvent('error', {
          message: 'boom-e2e-portal',
          error: new Error('boom-e2e-portal'),
          filename: `${window.location.origin}/e2e.js`,
          lineno: 1,
        }),
      )
    })

    // Se entra SIN volver a navegar (nada de `page.goto`, que recargaria la
    // pagina y perderia el error ya lanzado, en memoria hasta que algo lo
    // vacie): se rellena y se envia el formulario ya cargado, igual que
    // `frontend-admin/tests/e2e/errors.spec.ts`.
    await page.locator('input[name="employee_code"]').fill(PORTAL_EMPLOYEE_CODE)
    await page.locator('input[name="pin"]').fill(PORTAL_PIN)
    await page.locator('form button[type="submit"]').click()
    await page.waitForURL('**/records')

    await expect.poll(() => clientErrors.count(), { timeout: 5_000 }).toBeGreaterThanOrEqual(1)
    const firstCount = clientErrors.count()

    // La propiedad honesta no es «nunca vuelve a intentarlo» -el transporte
    // SI reintenta mientras quede algo pendiente, es su diseño- sino
    // «reintenta una vez por intervalo, nunca en bucle»: se avanza el reloj
    // virtual EXACTAMENTE el intervalo periodico completo, sin mas ni menos,
    // y el recuento sube en uno justo. Es determinista PORQUE el reloj esta
    // parado desde `pauseAt`: nada de lo que tarde este mismo recorrido de
    // Playwright (rellenar, enviar, esperar la redireccion) se suma a este
    // avance, y por eso el resultado no depende de la maquina que lo ejecute.
    // Y se ESPERA a que la peticion llegue: `runFor` dispara el temporizador,
    // pero el `fetch` que este arma viaja de forma asincrona hasta la ruta
    // interceptada, y leer el contador en la misma vuelta del bucle lo
    // encontraba a veces todavia en `firstCount` (fallo intermitente en la
    // CI, tambien en `main`). Mismo patron que `heartbeat-errors.spec.ts`.
    await page.clock.runFor(RETRY_INTERVAL_MS)
    await expect.poll(() => clientErrors.count(), { timeout: 5_000 }).toBe(firstCount + 1)

    // El portal sigue funcionando tras el 500: se puede seguir navegando.
    // (Una navegacion de verdad -`page.goto`, en modo `history`- recarga la
    // pagina, así que se deja para el final, despues de comprobar el
    // reintento: el buffer del reportero, en memoria, no sobrevive a una
    // recarga completa.)
    await page.goto('/export')
    await expect(
      page.getByRole('heading', { level: 1, name: 'Descargar mi historial' }),
    ).toBeVisible()
  },
)

test(
  'un throw real, no capturado por Playwright, llega al transporte',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    // SIN reloj falso: esta prueba no controla el tiempo, solo comprueba que
    // el camino real del navegador funciona. La prueba de arriba, con
    // `page.clock`, ya no lanza una excepcion de verdad (bajo reloj falso un
    // `setTimeout` que lanza nunca llega a convertirse en un evento `error`
    // del `window`: comprobado) y usa un `ErrorEvent` sintetico en su lugar
    // -valido para probar el reintento, pero no demuestra que
    // `installGlobalErrorCapture` atrapa un fallo real-. Esta es esa
    // demostracion, aparte.
    await stubPortalApi(page, { locale: 'es' })
    const clientErrors = stubClientErrorsEndpoint(page)

    await page.goto('/login')

    // Un `<script>` inyectado que lanza al ejecutarse dispara un `error`
    // GENUINO y no capturado del navegador -Playwright no lo convierte en un
    // rechazo, al contrario que un `throw` dentro de `page.evaluate`-: es
    // exactamente lo que `window.addEventListener('error', …)`
    // (`installGlobalErrorCapture`, `packages/web-kit/src/clientErrors.ts`)
    // esta pensado para atrapar. Mensaje tecnico sin PII (regla dura 21).
    // ANTES de iniciar sesion, por lo mismo que la prueba de arriba: el
    // transporte solo vacia el buffer al pasar a autenticado o cada 60 s
    // reales (aqui sin reloj falso, un E2E no puede esperar eso), asi que si
    // el error llegase DESPUES de entrar, la transicion a autenticado ya
    // habria vaciado un buffer vacio y esta prueba no demostraria nada en
    // 5 s.
    await page.addScriptTag({ content: 'throw new Error("boom-e2e-real-throw")' })

    await page.locator('input[name="employee_code"]').fill(PORTAL_EMPLOYEE_CODE)
    await page.locator('input[name="pin"]').fill(PORTAL_PIN)
    await page.locator('form button[type="submit"]').click()
    await page.waitForURL('**/records')

    await expect.poll(() => clientErrors.count(), { timeout: 5_000 }).toBeGreaterThanOrEqual(1)
    expect(clientErrors.messages()).toContain('Error: boom-e2e-real-throw')
  },
)
