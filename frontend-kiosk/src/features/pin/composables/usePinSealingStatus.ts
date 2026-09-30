// Estado del sellado del PIN, compartido por la pantalla de tarjeta y la del PIN.
//
// Por que existe (PIN-03). Sin esto, la pantalla de tarjeta ofrecia el boton
// «Ficha con tu codigo y PIN» en cuanto el padron traia la clave publica, aunque
// libsodium no pudiera arrancar (WebAssembly bloqueado, modulo roto): el
// empleado tecleaba codigo y seis digitos para acabar con un «codigo no valido»
// que no era suyo. Ahora el boton solo se ofrece si se PUEDE sellar, y si deja de
// poderse, desaparece en vez de quedar como una trampa.
//
// El estado es de MODULO (uno por pestana): la pantalla de tarjeta y la del PIN
// se montan y desmontan entre fichajes, y el resultado de la comprobacion no
// tiene por que repetirse en cada visita. Lo unico que se recuerda es el
// estado, nunca la promesa fallida (ver `warmUpSealing`).

import { onMounted, onUnmounted, readonly, ref } from 'vue'
import type { Ref } from 'vue'
import type { SealingStatus } from '../infrastructure/pinSealing'
import { warmUpSealing } from '../infrastructure/pinSealing'

/** `checking` = todavia no se sabe; no se oculta nada mientras tanto. */
export type PinSealingUiStatus = SealingStatus | 'checking'

/** Cada cuanto se reintenta mientras no se pueda sellar. Nunca mas rapido: es CPU en una tablet. */
export const SEALING_RETRY_MS = 30_000

const status = ref<PinSealingUiStatus>('checking')

export interface PinSealingStatusOptions {
  /** Se llama cuando se pasa a `unavailable` (una vez por transicion, sin datos). */
  readonly onUnavailable?: () => void
  readonly warmUp?: () => Promise<SealingStatus>
  readonly retryMs?: number
}

export interface PinSealingStatusHandle {
  readonly status: Readonly<Ref<PinSealingUiStatus>>
  check(): Promise<SealingStatus>
}

/** Solo para pruebas: vuelve al estado inicial del modulo. */
export function resetPinSealingStatus(): void {
  status.value = 'checking'
}

export function usePinSealingStatus(options: PinSealingStatusOptions = {}): PinSealingStatusHandle {
  const warmUp = options.warmUp ?? warmUpSealing
  const retryMs = options.retryMs ?? SEALING_RETRY_MS
  let timer: ReturnType<typeof setTimeout> | null = null
  let alive = true

  function clear(): void {
    if (timer === null) return
    clearTimeout(timer)
    timer = null
  }

  async function check(): Promise<SealingStatus> {
    clear()
    const next = await warmUp()
    if (next === 'unavailable' && status.value !== 'unavailable') options.onUnavailable?.()
    status.value = next
    // No se cachea el fallo: mientras no se pueda sellar, se vuelve a intentar.
    if (next === 'unavailable' && alive) {
      timer = setTimeout(() => {
        timer = null
        if (alive) void check()
      }, retryMs)
    }
    return next
  }

  onMounted(() => {
    void check()
  })

  onUnmounted(() => {
    alive = false
    clear()
  })

  return { status: readonly(status), check }
}
