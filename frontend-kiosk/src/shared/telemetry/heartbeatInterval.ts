// Cadencia del latido, en un modulo SIN dependencias: lo importan tambien los
// E2E (`tests/e2e/kiosk-upgrade.spec.ts`), que corren en Node y no pueden
// cargar `heartbeat.ts` -arrastra `deviceIdentity.ts`, que lee
// `__APP_VERSION__`, una constante que solo existe dentro del build de Vite-.

/** Cada minuto. La alerta del doc 01 §9.3 dispara a los 10 min sin latido. */
export const DEFAULT_HEARTBEAT_INTERVAL_MS = 60_000
