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
