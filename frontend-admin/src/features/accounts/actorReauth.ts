// Reautenticacion de quien actua (RF-ID-10, revision de seguridad del 12c).
//
// Crear una cuenta, restablecer una contrasena y retirar un segundo factor son
// los tres actos que mas se parecen a «dame esa cuenta»: una sesion robada o un
// panel desbloqueado en recepcion no bastan. El administrador que actua vuelve a
// demostrar quien es en el propio dialogo de confirmacion, con el codigo de su
// segundo factor (`actor_totp_code`) o, si su cuenta no tiene ninguno
// confirmado, con su contrasena actual (`actor_current_password`).
//
// Ni el codigo ni la contrasena se guardan: viven en el `ref` del dialogo y
// mueren con el.
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, onUnmounted, ref, watch } from 'vue'
import type { Ref } from 'vue'
import { useSessionStore } from '@/features/auth/session.store'

/** Campos de reautenticacion que el servidor acepta en las tres operaciones. */
export interface ActorReauth {
  actor_totp_code?: string
  actor_current_password?: string
}

export type ActorReauthField = 'actor_totp_code' | 'actor_current_password'

export function useActorReauth() {
  const session = useSessionStore()

  // `ManagementUser` aun no declara si la cuenta tiene segundo factor; se lee
  // con tolerancia. Sin el dato se pide el codigo: `admin` lo lleva siempre
  // (RS-06).
  const usesPassword = computed(() => {
    const flag = (session.user as { two_factor_enabled?: boolean } | null)?.two_factor_enabled

    return flag === false
  })
  const field = computed<ActorReauthField>(() =>
    usesPassword.value ? 'actor_current_password' : 'actor_totp_code',
  )
  const value = ref('')
  const valid = computed(() =>
    usesPassword.value ? value.value !== '' : /^[0-9]{6}$/.test(value.value),
  )

  function payload(): ActorReauth {
    return usesPassword.value
      ? { actor_current_password: value.value }
      : { actor_totp_code: value.value }
  }

  return { usesPassword, field, value, valid, payload }
}

/**
 * Cuenta atras de un `429` (`Retry-After`). Mientras `remaining > 0` el dialogo
 * no deja reenviar: reintentar antes solo alarga el bloqueo.
 */
export function useRetryCountdown(error: Ref<unknown>) {
  const remaining = ref(0)
  let timer: ReturnType<typeof setInterval> | undefined

  function stop(): void {
    if (timer !== undefined) {
      clearInterval(timer)
      timer = undefined
    }
  }

  watch(error, (current) => {
    stop()
    remaining.value =
      isApiError(current) && current.kind === 'rateLimited' ? (current.retryAfterSeconds ?? 0) : 0

    if (remaining.value > 0) {
      timer = setInterval(() => {
        remaining.value = Math.max(remaining.value - 1, 0)

        if (remaining.value === 0) {
          stop()
        }
      }, 1000)
    }
  })

  onUnmounted(stop)

  return { remaining }
}
