// Playwright del quiosco, con camara simulada (doc 02 §9.4).
//
// DOS PROYECTOS Y NO UNO. Chromium solo admite UN fichero de video falso por
// proceso de navegador (`--use-file-for-fake-video-capture` es un argumento de
// arranque, no algo que se cambie por pestana). Como hay que probar el QR limpio
// y el QR degradado, hay dos proyectos con dos navegadores distintos.
//
// SE PRUEBA EL BUILD, NO EL SERVIDOR DE DESARROLLO. `vite preview` sirve
// exactamente lo que se instala en la tablet: los mismos trozos, la misma carga
// diferida del decodificador y el mismo presupuesto del Anexo A. Un E2E contra
// `vite dev` no habria detectado nunca que ZXing llega por `import()`.
//
// EL BACKEND NO HACE FALTA. Las llamadas a `/api/v1/*` se interceptan en cada
// prueba. El ciclo completo contra el servidor real —fichar sin red, reconectar,
// comprobar que se consolida con el `occurred_at` original— es de la tarea 1.9,
// que es la que tiene la cola.

import { defineConfig, devices } from '@playwright/test'
import { fileURLToPath } from 'node:url'

const fixture = (name: string): string =>
  fileURLToPath(new URL(`./e2e/fixtures/${name}`, import.meta.url))

const PORT = 4173
const BASE_URL = `http://127.0.0.1:${PORT}`

/** Argumentos comunes: camara falsa y permiso concedido sin dialogo. */
function chromiumArgs(videoFile: string): string[] {
  return [
    '--use-fake-device-for-media-stream',
    '--use-fake-ui-for-media-stream',
    `--use-file-for-fake-video-capture=${videoFile}`,
    // La tablet esta en modo quiosco y sin altavoz que moleste al robot.
    '--autoplay-policy=no-user-gesture-required',
  ]
}

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
    permissions: ['camera'],
    // La tablet del quiosco: apaisada y de gama media.
    viewport: { width: 1280, height: 800 },
    // La tablet de un hotel espanol viene en espanol, y el quiosco DETECTA el
    // idioma del sistema (RF-KI-05). Sin fijarlo, Chromium arranca en `en-US` y
    // las pruebas comprobarian los textos de un aparato que no existe.
    // La deteccion automatica se prueba a proposito en `scan.spec.ts`.
    locale: 'es-ES',
    timezoneId: 'Europe/Madrid',
  },

  projects: [
    {
      name: 'kiosk-qr',
      testIgnore: /degraded\.spec\.ts$|layout\.spec\.ts$/,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 800 },
        launchOptions: { args: chromiumArgs(fixture('qr-video.y4m')) },
      },
    },
    {
      name: 'kiosk-qr-degraded',
      testMatch: /degraded\.spec\.ts$/,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 800 },
        launchOptions: { args: chromiumArgs(fixture('qr-video-degraded.y4m')) },
      },
    },
    {
      // `layout.spec.ts` mide la geometria de la pantalla de espera en varios
      // viewports. Necesita esa pantalla estable e indefinidamente, asi que
      // usa un fotograma SIN QR (`qr-video-blank.y4m`): con la camara mirando
      // a una tarjeta de verdad, el bucle de decodificacion la sustituiria
      // por la confirmacion a mitad de medicion.
      name: 'kiosk-layout',
      testMatch: /layout\.spec\.ts$/,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 800 },
        launchOptions: { args: chromiumArgs(fixture('qr-video-blank.y4m')) },
      },
    },
  ],

  webServer: {
    // Los videos se generan antes de arrancar: son deterministas y no se
    // versionan (ver `scripts/generate-qr-fixture.mjs`).
    command: `node scripts/generate-qr-fixture.mjs && npx vite preview --port ${PORT} --host 127.0.0.1 --strictPort`,
    url: BASE_URL,
    reuseExistingServer: process.env['CI'] !== 'true',
    timeout: 120_000,
  },
})
