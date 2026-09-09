// Generador de capturas de la guia del portal del empleado (tarea 5.11b,
// bloque C). Produce las imagenes de `docs/cliente/img/<idioma>/` sobre el
// doble del contrato (`stubPortalApi`), sin backend y sin datos reales
// (regla dura 21).
//
// EL PORTAL NO TENIA PLAYWRIGHT NI E2E (solo unitarias con Vitest): este
// fichero y `tests/e2e/support/portal.ts` nacen aqui, y quedan listos para un
// recorrido E2E futuro que hoy no se escribe (fuera del alcance de este
// bloque).
//
// NUNCA EN LA CI: no se suben binarios desde un runner, igual que en el panel
// y en el quiosco. Es un comando manual, `npm run docs:screenshots`, que se
// repite en cada version menor — el sello `docs/cliente/img/VERSION` es lo
// que ata la vigencia de las capturas a `VERSION`.
//
// `testDir` PROPIO Y DISTINTO de cualquier E2E funcional futuro, con el mismo
// patron `*.screenshots.ts` que el panel y el quiosco.
//
// PUERTO PROPIO: 4178. Ni el 4174/4176 del panel ni el 4177 del quiosco (ver
// HANDOFF.md -> "Trampas del entorno"), para poder correr los tres a la vez.
//
// VIEWPORT DE MOVIL (412x915, `Pixel 7`), a proposito y distinto del panel:
// la persona empleada abre el portal desde su telefono, casi siempre personal
// y en su tiempo libre (doc 02 §11, prioridad movil).
import { defineConfig, devices } from '@playwright/test'

const PORT = 4178
const BASE_URL = `http://127.0.0.1:${PORT}`

export default defineConfig({
  testDir: './tests/screenshots',
  // El nombre de fichero pactado es `*.screenshots.ts`, no `*.spec.ts`: el
  // patron por omision de Playwright no lo reconoceria.
  testMatch: '**/*.screenshots.ts',
  // Dentro de `test-results/`, que ya ignoran `.gitignore` y `.prettierignore`.
  outputDir: './test-results/screenshots',
  fullyParallel: false,
  forbidOnly: process.env['CI'] === 'true',
  retries: 0,
  workers: 1,
  reporter: [['list']],
  timeout: 60_000,
  expect: { timeout: 15_000 },

  use: {
    baseURL: BASE_URL,
    trace: 'off',
    video: 'off',
    viewport: { width: 412, height: 915 },
    isMobile: true,
    // Distinta de la del navegador de quien mira (regla dura 3: lo que
    // importa es la zona del centro, que viaja en cada respuesta): se fija
    // para que el comportamiento sea reproducible.
    timezoneId: 'Europe/Madrid',
    testIdAttribute: 'data-test',
  },

  projects: [
    {
      name: 'es',
      use: {
        ...devices['Pixel 7'],
        viewport: { width: 412, height: 915 },
        isMobile: true,
        locale: 'es-ES',
      },
    },
    {
      name: 'en',
      use: {
        ...devices['Pixel 7'],
        viewport: { width: 412, height: 915 },
        isMobile: true,
        locale: 'en-US',
      },
    },
  ],

  webServer: {
    command: `npx vite build && npx vite preview --port ${PORT} --host 127.0.0.1 --strictPort`,
    url: BASE_URL,
    reuseExistingServer: process.env['CI'] !== 'true',
    timeout: 120_000,
  },
})
