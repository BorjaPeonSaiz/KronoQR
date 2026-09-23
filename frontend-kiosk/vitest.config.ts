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
      environment: 'jsdom',
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
