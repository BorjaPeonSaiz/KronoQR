// Plazos del dialogo del QR de la politica de privacidad (RF-KI-09, V7-KI-02).
//
// El quiosco es de pared y compartido: un dialogo que alguien abrio y se
// olvido no puede quedarse tapando la pantalla ni el siguiente fichaje.

/** Sin tocar nada durante este tiempo, el dialogo se cierra solo. */
export const PRIVACY_DIALOG_IDLE_CLOSE_MS = 30_000

/** Tope absoluto desde que se abre, aunque se siga tocando. */
export const PRIVACY_DIALOG_MAX_OPEN_MS = 120_000

/** Cuanto antes del cierre se avisa («Se cerrara en breve»). Una vez por cuenta atras. */
export const PRIVACY_DIALOG_WARNING_LEAD_MS = 10_000
