// Generador de capturas del asistente de puesta en marcha (tarea 5.11, bloque
// 5.11-D, decision 3 de la ficha). Produce las imagenes de
// `docs/cliente/img/<idioma>/` sobre el doble del contrato (`stubOnboardingApi`),
// sin backend y sin datos reales (regla dura 21).
//
// NUNCA EN LA CI: no se suben binarios desde un runner (decision 3). Es un
// comando manual, `npm run docs:screenshots`, que se repite en cada version
// menor — el sello `docs/cliente/img/VERSION` es lo que ata la vigencia de las
// capturas a `VERSION` (una herramienta lo comprueba, no la costumbre).
//
// `testDir` PROPIO Y DISTINTO del `playwright.config.ts` de siempre: el E2E
// habitual (`npm run test:e2e`) nunca debe recoger este generador, y viceversa
// (verificar con `npx playwright test --list` en cada configuracion).
//
// Mismo `vite preview` que el E2E, en un PUERTO DISTINTO (4176, el E2E usa
// 4174) para poder correr los dos sin que choquen.
import { defineConfig, devices } from '@playwright/test'

const PORT = 4176
const BASE_URL = `http://127.0.0.1:${PORT}`

export default defineConfig({
  testDir: './tests/screenshots',
  // El nombre de fichero pactado en la ficha es `*.screenshots.ts`, no
  // `*.spec.ts`: el patron por omision de Playwright no lo reconoceria.
  testMatch: '**/*.screenshots.ts',
  // Dentro de `test-results/`, que ya ignoran `.gitignore` y `.prettierignore`
  // (el E2E de siempre usa la raiz de esa misma carpeta).
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
    viewport: { width: 1366, height: 768 },
    // Distinta del navegador que abriria alguien en el hotel: aqui no importa
    // (las capturas no muestran ninguna hora del registro legal), pero se fija
    // para que el comportamiento sea reproducible.
    timezoneId: 'Europe/Madrid',
    testIdAttribute: 'data-test',
  },

  projects: [
    {
      name: 'es',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1366, height: 768 },
        locale: 'es-ES',
      },
    },
    {
      name: 'en',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1366, height: 768 },
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
