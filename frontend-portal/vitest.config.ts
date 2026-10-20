import { fileURLToPath } from 'node:url'
import { configDefaults, defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config.ts'

// La zona horaria del PROCESO se fija a una que no es ni la del centro (Madrid)
// ni UTC: una regresion que formatee con la zona del navegador deja de pasar por
// casualidad (R2-PO-01). Todo lo que se enseña usa la zona del centro que viaja
// en los datos.
export const TEST_PROCESS_TIME_ZONE = 'Pacific/Auckland'
process.env['TZ'] = TEST_PROCESS_TIME_ZONE

export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      env: { TZ: TEST_PROCESS_TIME_ZONE },
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
