/// <reference types="vite/client" />

// Misma forma que declara cada SPA consumidora (`frontend-admin/env.d.ts`,
// `frontend-portal/env.d.ts`). TypeScript combina declaraciones de una interfaz
// global identicas sin conflicto, asi que este fichero solo hace falta para que
// el paquete se pueda comprobar de forma aislada (`npm run type-check` dentro de
// `packages/web-kit`) sin depender de que una aplicacion lo arrastre.
interface ImportMetaEnv {
  /**
   * Origen de la API. Vacio en desarrollo y en produccion, donde cada SPA se
   * sirve desde el mismo dominio que la API: la instalacion es del cliente y no
   * hay CORS que atravesar (ADR-017, nada especifico de un cliente en el codigo).
   */
  readonly VITE_API_BASE_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
