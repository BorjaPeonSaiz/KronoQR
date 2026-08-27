// Registro del service worker (RF-KI-01).
//
// Precachea el *app shell* para que el quiosco arranque sin red: una tablet que
// se reinicia a las 05:50 con el router del hotel caido tiene que poder fichar a
// las 06:00.
//
// LA ACTUALIZACION NUNCA SE APLICA SOLA. `registerType: 'prompt'` en
// `vite.config.ts` y, aqui, `onNeedRefresh` que se limita a ANOTAR que hay
// version nueva. Una actualizacion que se aplica sola recarga la pagina, y si
// eso pasa a las 06:00 con quince personas en la cola, el quiosco esta muerto
// justo en el minuto que existe para cubrir.
//
// ALCANCE. La VENTANA de actualizacion configurable —«nunca durante un cambio de
// turno»— es RF-KI-07, tarea 3.12. Lo que hay aqui es la mitad que no se puede
// dejar para despues: que no se aplique nada sin que alguien lo decida, y que
// «alguien lo decida» no baste si el momento es malo.
//
// LA PUERTA (tarea 1.9, paso 11). `applyUpdate` consulta un guardian antes de
// recargar. El guardian que se le pasa hoy (`features/offline/domain/
// updateWindow.ts`) dice que no durante un cambio de turno y que no con
// fichajes sin sincronizar. Sin esa puerta, el dia que alguien llame a
// `applyUpdate` desde un boton de mantenimiento, la recarga puede caer a las
// 06:00 con quince personas en la cola.

import { errorTypeOf } from '@/shared/telemetry/errorType'

export interface ServiceWorkerRegistrationResult {
  /** Hay una version nueva esperando. No se aplica sin llamar a `applyUpdate`. */
  readonly needsRefresh: () => boolean
  /**
   * Aplica la version pendiente y recarga, **si el guardian lo permite**.
   * @returns `false` si no habia nada que aplicar o si el momento no es bueno.
   */
  readonly applyUpdate: () => Promise<boolean>
}

export interface RegisterServiceWorkerOptions {
  readonly onUpdateAvailable?: () => void
  readonly onOfflineReady?: () => void
  readonly onError?: (context: Record<string, string | number | boolean>) => void
  /** `false` = ahora no. Por defecto, se permite: quien no pasa guardian, decide. */
  readonly canApply?: () => boolean
}

export async function registerServiceWorker(
  options: RegisterServiceWorkerOptions = {},
): Promise<ServiceWorkerRegistrationResult> {
  let pending = false
  let update: ((reloadPage?: boolean) => Promise<void>) | null = null

  const canApply = options.canApply ?? ((): boolean => true)

  const noop: ServiceWorkerRegistrationResult = {
    needsRefresh: () => false,
    applyUpdate: async () => false,
  }

  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return noop

  try {
    const { registerSW } = await import('virtual:pwa-register')
    update = registerSW({
      immediate: true,
      onNeedRefresh() {
        pending = true
        options.onUpdateAvailable?.()
      },
      onOfflineReady() {
        options.onOfflineReady?.()
      },
      onRegisterError(error: unknown) {
        options.onError?.({ error_type: errorTypeOf(error) })
      },
    })
  } catch (error) {
    options.onError?.({ error_type: errorTypeOf(error) })
    return noop
  }

  return {
    needsRefresh: () => pending,
    applyUpdate: async () => {
      if (!pending || update === null) return false
      // Se consulta AQUI y no al recibir el aviso: entre «hay version nueva» y
      // «aplicala» pueden pasar horas, y lo que importa es el momento de la
      // recarga. `pending` no se limpia si se deniega — la version sigue ahi
      // esperando un momento mejor.
      if (!canApply()) return false
      pending = false
      await update(true)
      return true
    },
  }
}
