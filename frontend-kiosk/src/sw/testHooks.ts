// Gancho de pruebas ACOTADO para el guardian de actualizacion (RF-KI-07, tarea
// 3.12).
//
// POR QUE HACE FALTA. Probar de verdad «hay una version nueva pendiente»
// exigiria publicar DOS versiones distintas del service worker en el mismo
// E2E y esperar el ciclo real de `workbox-window` (instalar, esperar,
// controlar) — viable, pero desproporcionado para lo que esta puerta necesita
// probar: que NO se aplica fuera de la ventana o con cola, y que SI se aplica
// dentro de la ventana con la cola vacia. Este gancho fuerza el estado «hay
// version pendiente» y deja que la MISMA puerta (`canApply`, en
// `registerServiceWorker.ts`) y el MISMO temporizador de reintento decidan;
// lo unico que sustituye es el efecto final de recargar la pagina -que
// requeriria un service worker de verdad esperando- por un marcador
// observable desde Playwright.
//
// NUNCA EN PRODUCCION, con DOS guardas independientes (decision 16 de la
// tarea 3.12):
//
//   1. DE COMPILACION: `__KRONOQR_TEST_HOOKS__` (`vite.config.ts` -> `define`)
//      es `false` en el build de produccion (`mode: 'production'`, el que se
//      instala en la tablet), asi que `installTestHooks` queda como codigo
//      muerto y el minificador lo QUITA del bundle -no basta con que el
//      gancho no se active, no puede ni estar-.
//   2. DE EJECUCION: incluso en un build donde el gancho SI esta presente
//      (`--mode test`, el que usa el E2E), exige que alguien ejecute
//      JavaScript en la pagina ANTES de que arranque la aplicacion para
//      dejar `window.__KRONOQR_ENABLE_TEST_HOOKS__` en `true` — exactamente
//      lo que ya hacen `pairDevice`/`stubKioskApi` con `page.addInitScript`
//      (ver `tests/e2e/support/kiosk.ts`). En una tablet de un hotel real no
//      existe ese camino: nadie inyecta un script antes de que la PWA cargue.

export interface KronoQrTestHooks {
  /** Fuerza «hay una version pendiente» y dispara la evaluacion de la puerta. */
  readonly simulateUpdateAvailable: () => void
  /** `true` en cuanto la puerta ha dejado pasar la actualizacion simulada. */
  readonly hasAppliedUpdate: () => boolean
}

declare global {
  interface Window {
    __KRONOQR_ENABLE_TEST_HOOKS__?: boolean
    __kronoqrTest?: KronoQrTestHooks
  }
}

/** `true` solo si una prueba ha dejado la senal ANTES de que esto se evalue. */
export function testHooksEnabled(): boolean {
  return typeof window !== 'undefined' && window.__KRONOQR_ENABLE_TEST_HOOKS__ === true
}

/** No-op fuera de las pruebas: ver la cabecera de este fichero. */
export function installTestHooks(hooks: KronoQrTestHooks): void {
  // GUARDA DE COMPILACION (decision 16 de la tarea 3.12), ademas de la de
  // ejecucion de mas abajo: `__KRONOQR_TEST_HOOKS__` es `false` como LITERAL
  // en el build de produccion (`vite.config.ts` -> `define`), asi que este
  // `if` -y el codigo que protege- se convierte en muerto y el minificador lo
  // quita del bundle que se instala en la tablet. En cualquier otro `mode`
  // (desarrollo, o el `--mode test` del E2E) vale `true` y no cambia nada.
  if (!__KRONOQR_TEST_HOOKS__) return
  if (!testHooksEnabled()) return
  window.__kronoqrTest = hooks
}
