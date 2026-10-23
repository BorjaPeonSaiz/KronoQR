import { fileURLToPath } from 'node:url'
import { configDefaults, defineConfig, mergeConfig } from 'vitest/config'
import viteConfigFn from './vite.config.ts'

// `vite.config.ts` exporta una FUNCION (necesita `mode` para decidir
// `__KRONOQR_TEST_HOOKS__`, RF-KI-07, tarea 3.12, decision 16): se invoca a
// mano con `mode: 'test'` -Vitest, igual que el E2E con `--mode test`, no es
// el build de produccion- antes de fusionarla con los ajustes de pruebas.
const viteConfig = await viteConfigFn({ mode: 'test', command: 'serve' })

export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      // K1: `node` de serie y jsdom SOLO en el fichero que monta componentes o
      // toca el DOM, con `// @vitest-environment jsdom` en su primera linea.
      // Con jsdom global se creaba 63 veces y se llevaba el 72 % del tiempo de
      // la suite, tambien en las pruebas de dominio que no lo usan. Un fichero
      // nuevo que necesite `document`, `window` o `localStorage` falla en el
      // acto con `ReferenceError` y se arregla con esa linea.
      environment: 'node',
      // V21-1-KI-1: un `[Vue warn]` hace fallar la prueba que lo emite.
      setupFiles: ['tests/unit/support/failOnVueWarnings.ts'],
      include: ['tests/unit/**/*.spec.ts'],
      exclude: [...configDefaults.exclude, 'tests/e2e/**'],
      root: fileURLToPath(new URL('./', import.meta.url)),
      coverage: {
        provider: 'v8',
        reporter: ['text-summary', 'html'],
        include: ['src/**/*.ts', 'src/**/*.vue'],
        // main.ts solo monta la aplicacion: cubrirlo exigiria montar el DOM real
        // sin comprobar ninguna regla.
        exclude: ['src/main.ts', 'src/**/*.d.ts'],
        // Umbral del doc 02 §9.2 para frontend. Se comprueba desde hoy para que
        // no haya que "activarlo" cuando ya sea tarde.
        thresholds: { lines: 70, functions: 70, branches: 70, statements: 70 },
      },
    },
  }),
)
