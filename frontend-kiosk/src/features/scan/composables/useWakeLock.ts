// Screen Wake Lock API (RF-KI-01).
//
// La tablet no puede suspenderse: una pantalla apagada delante de una cola a las
// 06:00 es un quiosco averiado a efectos practicos, porque nadie sabe si hay que
// tocarla, esperar o avisar a recepcion.
//
// EL REINTENTO NO ES OPCIONAL. El sistema operativo SUELTA el bloqueo cada vez
// que la pagina deja de ser visible —al bloquear el aparato, al mostrar el
// dialogo de permisos, al aparecer una notificacion a pantalla completa— y NO lo
// devuelve solo. Sin volver a pedirlo al recuperar el foco, el bloqueo dura
// hasta el primer parpadeo del dia y ya no vuelve.
//
// Y NO PUEDE IMPEDIR FICHAR. Si el navegador no lo soporta o lo deniega, se
// anota y se sigue escaneando (regla dura 19).

import type { Ref } from 'vue'
import { onUnmounted, readonly, ref } from 'vue'
import { errorTypeOf } from '@/shared/telemetry/errorType'

export interface UseWakeLockOptions {
  readonly onDenied?: (context: Record<string, string | number | boolean>) => void
}

export interface WakeLockController {
  readonly active: Readonly<Ref<boolean>>
  readonly supported: boolean
  request(): Promise<void>
  release(): Promise<void>
}

export function useWakeLock(options: UseWakeLockOptions = {}): WakeLockController {
  const active = ref(false)
  const supported = typeof navigator !== 'undefined' && 'wakeLock' in navigator

  let sentinel: WakeLockSentinel | null = null
  let disposed = false
  let requesting = false

  async function request(): Promise<void> {
    if (!supported || disposed || requesting) return
    if (sentinel !== null && !sentinel.released) return
    // Pedirlo con la pagina oculta falla siempre: no se malgasta el intento.
    if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return

    requesting = true
    try {
      sentinel = await navigator.wakeLock.request('screen')
      active.value = true
      sentinel.addEventListener('release', () => {
        active.value = false
      })
    } catch (error) {
      active.value = false
      sentinel = null
      options.onDenied?.({ error_type: errorTypeOf(error) })
    } finally {
      requesting = false
    }
  }

  async function release(): Promise<void> {
    const current = sentinel
    sentinel = null
    active.value = false
    if (current === null) return
    try {
      await current.release()
    } catch {
      // Ya liberado por el sistema. Nada que hacer.
    }
  }

  function reacquire(): void {
    if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
      // La especificacion dice que el sistema SUELTA el bloqueo al ocultarse el
      // documento. Se suelta tambien la referencia, porque si no, al volver
      // seguiria pareciendo que hay uno vivo y no se pediria otro: ese es
      // exactamente el fallo por el que la pantalla se apaga a media manana y ya
      // no vuelve a encenderse sola.
      sentinel = null
      active.value = false
      return
    }
    void request()
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', reacquire)
  }
  if (typeof window !== 'undefined') {
    window.addEventListener('focus', reacquire)
  }

  onUnmounted(() => {
    disposed = true
    if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', reacquire)
    if (typeof window !== 'undefined') window.removeEventListener('focus', reacquire)
    void release()
  })

  return { active: readonly(active), supported, request, release }
}
