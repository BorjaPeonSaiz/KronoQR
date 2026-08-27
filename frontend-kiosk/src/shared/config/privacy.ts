// Configuracion del aviso de privacidad (RF-KI-09, RL-09).
//
// El responsable del tratamiento y la URL de la politica completa CAMBIAN con
// cada cliente. Por eso son variables de entorno y no constantes: nada
// especifico de un cliente vive en el codigo (ADR-017, regla dura 13), y vender
// a un hotel nuevo no puede exigir tocar el repositorio.
//
// Si faltan, el aviso NO desaparece: se muestra con una redaccion generica que
// sigue cumpliendo el art. 13 en capa 1 salvo por la identidad concreta, y la
// pantalla de diagnostico (RF-KI-08) tendra donde avisar de que falta
// configurar. Un aviso legal que se apaga solo porque falta un `.env` es peor
// que un aviso incompleto.

export interface PrivacyNoticeConfig {
  /** Nombre del responsable del tratamiento, o `null` si no se ha configurado. */
  readonly controller: string | null
  /** URL de la politica completa (capa 2), o `null`. */
  readonly policyUrl: string | null
}

function clean(value: string | undefined): string | null {
  const trimmed = (value ?? '').trim()
  return trimmed === '' ? null : trimmed
}

export function readPrivacyNoticeConfig(
  env: { VITE_PRIVACY_CONTROLLER?: string; VITE_PRIVACY_POLICY_URL?: string } = import.meta.env,
): PrivacyNoticeConfig {
  const url = clean(env.VITE_PRIVACY_POLICY_URL)

  return {
    controller: clean(env.VITE_PRIVACY_CONTROLLER),
    // Solo http(s). Una URL de `javascript:` en el aviso legal seria un XSS con
    // sello notarial.
    policyUrl: url !== null && /^https?:\/\//i.test(url) ? url : null,
  }
}
