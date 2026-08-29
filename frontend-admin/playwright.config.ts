// Playwright del panel de gestion (doc 02 §9.4 y §9.5: «recorrido de usuario →
// E2E»). Misma disposicion que el del quiosco, sin camara.
//
// SE PRUEBA EL BUILD, NO EL SERVIDOR DE DESARROLLO. `vite preview` sirve
// exactamente lo que se despliega: los mismos trozos y la misma carga diferida.
// El build se hace aqui mismo para que el E2E nunca corra sobre un `dist/`
// viejo: un E2E verde sobre codigo antiguo es peor que ninguno.
//
// EL BACKEND NO HACE FALTA. Las llamadas a `/api/v1/*` se interceptan en
// `tests/e2e/support/admin.ts` con las formas del contrato. Lo que se prueba
// aqui es el recorrido de la persona por el panel: acceso, plantilla, ficha y
// registro horario. La autorizacion real y las policies se prueban en el
// backend (regla dura 18).

import { defineConfig, devices } from '@playwright/test'

const PORT = 4174
const BASE_URL = `http://127.0.0.1:${PORT}`

export default defineConfig({
  testDir: './tests/e2e',
  outputDir: './test-results',
  fullyParallel: false,
  forbidOnly: process.env['CI'] === 'true',
  retries: process.env['CI'] === 'true' ? 1 : 0,
  workers: 1,
  reporter: process.env['CI'] === 'true' ? [['github'], ['list']] : [['list']],
  timeout: 45_000,
  expect: { timeout: 15_000 },

  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    video: 'off',
    // El ordenador de recepcion o de RRHH: un portatil corriente.
    viewport: { width: 1366, height: 768 },
    // El panel se abre en espanol en un hotel espanol; las pruebas leen los
    // textos de `locales/es.json`. La zona horaria del NAVEGADOR no debe
    // importar: las horas que se muestran vienen resueltas en la zona del
    // centro (regla dura 3). Se fija una distinta a proposito para que una
    // reconversion en el cliente se note.
    locale: 'es-ES',
    timezoneId: 'Atlantic/Canary',
    // El panel marca sus ganchos de prueba con `data-test`, no con el
    // `data-testid` que Playwright busca por omision.
    testIdAttribute: 'data-test',
  },

  projects: [
    {
      name: 'admin',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 768 } },
    },
  ],

  webServer: {
    command: `npx vite build && npx vite preview --port ${PORT} --host 127.0.0.1 --strictPort`,
    url: BASE_URL,
    reuseExistingServer: process.env['CI'] !== 'true',
    timeout: 120_000,
  },
})
