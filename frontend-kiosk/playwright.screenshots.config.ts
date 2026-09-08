// Generador de capturas para la documentacion de cliente (tarea 5.11-E,
// decision 3 del cierre de la 5.11: "Capturas generadas con Playwright sobre
// el doble del contrato... sin backend y sin datos reales").
//
// SE PRUEBA EL BUILD, NO EL SERVIDOR DE DESARROLLO, exactamente como
// `playwright.config.ts`: `vite preview` sirve lo mismo que se instala en la
// tablet. Ejecuta `npm run build` antes de `npm run docs:screenshots` si el
// `dist/` no esta ya generado.
//
// PUERTO PROPIO. Ni el 4173 del E2E funcional ni el 5175 del `dev` del
// portal (ver HANDOFF.md -> "Trampas del entorno"): las tres pueden convivir.
//
// NUNCA EN CI. No se suben binarios desde un runner (decision 3): esto se
// ejecuta a mano, en el puesto de quien redacta la documentacion, cada vez
// que cambia la version menor.

import { defineConfig, devices } from '@playwright/test'
import { fileURLToPath } from 'node:url'

const fixture = (name: string): string =>
  fileURLToPath(new URL(`./e2e/fixtures/${name}`, import.meta.url))

const PORT = 4177
const BASE_URL = `http://127.0.0.1:${PORT}`

/** Argumentos comunes: camara falsa y permiso concedido sin dialogo. */
function chromiumArgs(videoFile: string): string[] {
  return [
    '--use-fake-device-for-media-stream',
    '--use-fake-ui-for-media-stream',
    `--use-file-for-fake-video-capture=${videoFile}`,
    '--autoplay-policy=no-user-gesture-required',
  ]
}

// Fotograma SIN QR (el mismo que usa `layout.spec.ts`): la segunda captura es
// la pantalla de fichaje YA operativa pero SIN NADIE FICHANDO. Con el video
// de verdad, el bucle de decodificacion la sustituiria por la confirmacion a
// mitad de captura.
const BLANK_VIDEO = fixture('qr-video-blank.y4m')

export default defineConfig({
  testDir: './tests/screenshots',
  // Los ficheros de este generador se llaman `*.screenshots.ts`, no
  // `*.spec.ts`: el patron de Playwright por defecto no los recogeria.
  testMatch: /\.screenshots\.ts$/,
  outputDir: './test-results-screenshots',
  fullyParallel: false,
  forbidOnly: process.env['CI'] === 'true',
  retries: 0,
  workers: 1,
  reporter: [['list']],
  timeout: 45_000,
  expect: { timeout: 15_000 },

  use: {
    baseURL: BASE_URL,
    trace: 'off',
    video: 'off',
    screenshot: 'off',
    permissions: ['camera'],
    viewport: { width: 1280, height: 800 },
    timezoneId: 'Europe/Madrid',
  },

  projects: [
    {
      // El quiosco DETECTA el idioma del sistema (RF-KI-05): no hay selector
      // que fijar aqui, solo el `locale` del navegador.
      name: 'es',
      use: {
        ...devices['Desktop Chrome'],
        locale: 'es-ES',
        viewport: { width: 1280, height: 800 },
        launchOptions: { args: chromiumArgs(BLANK_VIDEO) },
      },
    },
    {
      name: 'en',
      use: {
        ...devices['Desktop Chrome'],
        locale: 'en-US',
        viewport: { width: 1280, height: 800 },
        launchOptions: { args: chromiumArgs(BLANK_VIDEO) },
      },
    },
  ],

  webServer: {
    // Los fixtures de video se generan antes de arrancar, igual que en el E2E
    // funcional: son deterministas y no se versionan.
    command: `node scripts/generate-qr-fixture.mjs && npx vite build && npx vite preview --port ${PORT} --host 127.0.0.1 --strictPort`,
    url: BASE_URL,
    reuseExistingServer: process.env['CI'] !== 'true',
    timeout: 120_000,
  },
})
