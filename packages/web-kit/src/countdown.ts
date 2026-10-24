// Cuenta atras real. El `Retry-After` de un 503 se mostraba como un texto fijo
// («en 30 segundos») que no cambiaba nunca. Aqui el valor baja de verdad, medido contra el reloj
// y no contando ticks (una pestaña en segundo plano frena los temporizadores y la cuenta se
// desviaria), y se detiene al llegar a 0 o al salir del ambito del componente.
import { onScopeDispose, readonly, ref, watch, type Ref } from 'vue'

/**
 * Segundos que faltan. `null` mientras la fuente es `null` (no hay espera que contar). Cada vez
 * que la fuente cambia, la cuenta vuelve a empezar desde el nuevo valor.
 */
export function useCountdown(seconds: () => number | null): Readonly<Ref<number | null>> {
  const remaining = ref<number | null>(null)
  let timer: ReturnType<typeof setInterval> | null = null

  function stop(): void {
    if (timer !== null) clearInterval(timer)
    timer = null
  }

  watch(
    seconds,
    (initial) => {
      stop()
      if (initial === null) {
        remaining.value = null
        return
      }
      const start = Math.max(0, Math.ceil(initial))
      remaining.value = start
      if (start === 0) return

      const deadline = Date.now() + start * 1000
      timer = setInterval(() => {
        remaining.value = Math.max(0, Math.ceil((deadline - Date.now()) / 1000))
        if (remaining.value === 0) stop()
      }, 1000)
    },
    { immediate: true },
  )

  onScopeDispose(stop)

  return readonly(remaining)
}
