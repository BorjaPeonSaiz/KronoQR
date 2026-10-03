/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/client" />

/** Version de la PWA, inyectada por Vite desde `package.json` (RF-KI-07, §10.5). */
declare const __APP_VERSION__: string

/**
 * `true` salvo en un build con `mode: 'production'` (RF-KI-07, tarea 3.12,
 * decision 16). GUARDA DE COMPILACION del gancho de pruebas
 * (`src/sw/testHooks.ts`): al ser un literal booleano conocido en tiempo de
 * build (`vite.config.ts` -> `define`), el minificador elimina como codigo
 * muerto el `if` que instala `window.__kronoqrTest` cuando este valor es
 * `false` -el bundle de produccion no lleva NI EL CODIGO del gancho, no solo
 * la guarda de ejecucion (`testHooksEnabled()`, que sigue existiendo por si
 * alguien construyera con otro `mode` sin darse cuenta)-.
 */
declare const __KRONOQR_TEST_HOOKS__: boolean

interface ImportMetaEnv {
  // El responsable y la politica del aviso de privacidad (RF-KI-09) ya NO son
  // variables de entorno: vienen de `GET /api/v1/branding` (`privacy_notice`).
  /** Origen de la API. Vacio = mismo origen, que es lo normal en el quiosco. */
  readonly VITE_API_BASE_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
