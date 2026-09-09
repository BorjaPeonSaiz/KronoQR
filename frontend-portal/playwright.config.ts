// Playwright del portal del empleado (doc 02 §9.4 y §9.5: «recorrido de
// usuario → E2E»; RL-05, RF-ID-*). Misma disciplina que el panel y el
// quiosco, con una diferencia deliberada: el portal es de **prioridad
// movil** (doc 02 §11) — quien lo consulta lo abre casi siempre desde su
// telefono personal, en su tiempo libre, no desde el ordenador del centro.
// El unico proyecto es por eso un movil (412x915, `Pixel 7`), no un
// escritorio: las diferencias de maquetacion por tamano las cubren las
// pruebas unitarias y las capturas (`playwright.screenshots.config.ts`), no
// este recorrido funcional.
//
// SE PRUEBA EL BUILD, NO EL SERVIDOR DE DESARROLLO. `vite preview` sirve
// exactamente lo que se despliega. El build se hace aqui mismo para que el
// E2E nunca corra sobre un `dist/` viejo.
//
// EL BACKEND NO HACE FALTA. Las llamadas a `/api/v1/*` se interceptan en
// `tests/e2e/support/portal.ts` con las formas del contrato. Lo que se
// prueba aqui es el recorrido de la persona empleada por el portal: acceso
// con codigo y PIN, su registro y la descarga de su historico. La
// autorizacion real y las policies se prueban en el backend (regla dura 18).
//
// PUERTO PROPIO: 4175. Ni el 4173 del quiosco, ni el 4174 del panel, ni el
// 4176/4177/4178 de las capturas de panel/quiosco/portal (ver HANDOFF.md ->
// "Trampas del entorno"), para poder correr todos a la vez.
import { defineConfig, devices } from '@playwright/test'

const PORT = 4175
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
    // El movil personal de una persona empleada, en un hotel espanol; las
    // pruebas leen los textos de `locales/es.json`. La zona horaria del
    // NAVEGADOR no debe importar: las horas que se muestran vienen resueltas
    // en la zona del centro (regla dura 3, `Europe/Madrid` en los datos de
    // ejemplo). Se fija una distinta a proposito para que una reconversion
    // en el cliente se note.
    locale: 'es-ES',
    timezoneId: 'Atlantic/Canary',
    // El portal marca sus ganchos de prueba con `data-test`, no con el
    // `data-testid` que Playwright busca por omision.
    testIdAttribute: 'data-test',
  },

  projects: [
    {
      name: 'mobile',
      use: {
        ...devices['Pixel 7'],
        viewport: { width: 412, height: 915 },
        isMobile: true,
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
