/// <reference types="vite/client" />

interface ImportMetaEnv {
  /**
   * Origen de la API. Vacio en desarrollo y en produccion, donde el portal se
   * sirve desde el mismo dominio que la API: la instalacion es del cliente y no
   * hay CORS que atravesar (ADR-017, nada especifico de un cliente en el codigo).
   */
  readonly VITE_API_BASE_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}

/**
 * Version del portal, inyectada por Vite desde package.json en compilacion.
 * Acompaña a cada error capturado (clientErrors) para saber en que version
 * aparecio. En Vitest no existe: el codigo que la lea debe tolerar su ausencia
 * con `typeof __APP_VERSION__ !== 'undefined'`.
 */
declare const __APP_VERSION__: string
