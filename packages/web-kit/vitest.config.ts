import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['tests/unit/**/*.spec.ts'],
    root: fileURLToPath(new URL('./', import.meta.url)),
    coverage: {
      provider: 'v8',
      reporter: ['text-summary', 'html'],
      // Solo la logica pura tiene suite propia aqui. Los cinco componentes de
      // Vue (`src/components/*.vue`) no llevan prueba de componente en el
      // paquete: los ejercitan las vistas de cada SPA que los consume (igual que
      // pasaba en `frontend-admin` antes de esta migracion). Medir su cobertura
      // aqui solo daria un 0 % que no informa de nada.
      include: ['src/**/*.ts'],
      exclude: ['src/**/*.d.ts'],
      // Mismo umbral que cada SPA (doc 02 §9.2).
      thresholds: { lines: 70, functions: 70, branches: 70, statements: 70 },
    },
  },
})
