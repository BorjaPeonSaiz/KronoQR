/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/client" />

/** Version de la PWA, inyectada por Vite desde `package.json` (RF-KI-07, §10.5). */
declare const __APP_VERSION__: string

interface ImportMetaEnv {
  /**
   * Responsable del tratamiento que se muestra en el aviso de privacidad
   * (RF-KI-09, RL-09). Es CONFIGURACION: cambia con cada cliente y por eso no
   * puede vivir en el codigo (ADR-017, regla dura 13).
   */
  readonly VITE_PRIVACY_CONTROLLER?: string
  /** URL de la politica de privacidad completa (capa 2 del aviso). */
  readonly VITE_PRIVACY_POLICY_URL?: string
  /** Origen de la API. Vacio = mismo origen, que es lo normal en el quiosco. */
  readonly VITE_API_BASE_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
