// Deteccion de un dispositivo desvinculado desde el panel (RF-PD-06, doc 01
// §8.1: «purga al desvincular el dispositivo»).
//
// POR QUE NO BASTA CON UN 401 SUELTO. El quiosco vive de picos de red
// intermitente: un `401` aislado puede ser un token que estaba rotando en el
// instante exacto de la peticion, no uno revocado. Pero DOS respuestas
// `unauthorized` SEGUIDAS, sin ningun exito de por medio, en el latido, en el
// padron o al sincronizar la cola, ya no es una carrera de rotacion: es que el
// panel ha pulsado «Desvincular». La regla dura 19 exige que la tablet no se
// quede atrapada, y quedarse fichando contra un token muerto durante horas
// —o, peor, desvincularse por una intermitencia de wifi— son las dos formas de
// fallar esto.
//
// NUNCA POR UN FALLO DE RED. `offline`, `network`, `timeout`, `throttled`,
// `server` y `malformed` no cuentan hacia el umbral, PERO TAMPOCO lo reinician:
// son ambiguos en las dos direcciones, así que se ignoran. Solo un `ok` de
// verdad (autenticado) resetea el contador a cero; solo `unauthorized` lo
// aumenta.
//
// QUE HACE Y QUE NO HACE ESTE MODULO. Cuenta. Nada mas. No limpia el token, no
// purga el padron ni navega: eso lo hace quien lo instancia (`useOfflineQueue`
// para el token y el padron; la pantalla, para navegar), porque este modulo no
// sabe nada de `localStorage`, de Dexie ni del router. Sin esa separacion,
// probar el conteo exigiria simular IndexedDB y el DOM para nada.
//
// POR QUE `reportAuthenticated` TAMBIEN REARMA `triggered`. El controlador de
// la cola es un SINGLETON que sobrevive a la navegacion y no se destruye entre
// pantallas (comentario de `useOfflineQueue.ts`): en un turno de 8 horas cabe
// mas de un ciclo de desvincular-y-volver-a-emparejar sobre la misma tablet
// sin recargar la pagina. Si `triggered` se quedara fijo para siempre tras la
// primera desvinculacion, un segundo token revocado mas tarde en el MISMO
// turno pasaria desapercibido —justo el callejon sin salida que esto existe
// para evitar—. Un `ok` de verdad solo puede llegar tras un emparejamiento
// nuevo (con token nuevo), asi que rearmar aqui es seguro: no hay forma de que
// un canal se autentique mientras el dispositivo sigue revocado.

/** Dos respuestas seguidas y sin nada de por medio, no una: ver la cabecera. */
export const DEFAULT_REVOCATION_THRESHOLD = 2

export interface DeviceRevocationWatcherOptions {
  readonly threshold?: number
  /** Se llama UNA sola vez, al cruzar el umbral. Nunca de nuevo tras eso. */
  readonly onRevoked: () => void
}

export interface DeviceRevocationWatcher {
  /** Una respuesta `401`/`403` de heartbeat, roster o sincronizacion. */
  reportUnauthorized(): void
  /** Una respuesta CORRECTA de cualquiera de esos tres canales. Reinicia el conteo. */
  reportAuthenticated(): void
}

export function createDeviceRevocationWatcher(
  options: DeviceRevocationWatcherOptions,
): DeviceRevocationWatcher {
  const threshold = options.threshold ?? DEFAULT_REVOCATION_THRESHOLD
  let consecutiveUnauthorized = 0
  /** Una vez disparado, no se vuelve a disparar: ya se esta navegando a `/pair`. */
  let triggered = false

  return {
    reportUnauthorized() {
      if (triggered) return
      consecutiveUnauthorized += 1
      if (consecutiveUnauthorized < threshold) return
      triggered = true
      options.onRevoked()
    },

    reportAuthenticated() {
      consecutiveUnauthorized = 0
      triggered = false
    },
  }
}
