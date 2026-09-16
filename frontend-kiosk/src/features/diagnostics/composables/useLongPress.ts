// Pulsacion larga sobre el reloj, para abrir la pantalla de diagnostico
// (RF-KI-08, tarea 3.3, decision 8).
//
// PURO EN EL SENTIDO QUE IMPORTA AQUI: no toca el DOM ni monta nada, solo
// decide CUANDO disparar a partir de eventos de puntero. El boton que lo usa
// (`ClockDiagnosticsTrigger.vue`) es un `<button>` real: pulsarlo brevemente
// no hace nada (no compite con fichar), y solo mantenerlo pulsado el tiempo
// completo abre el diagnostico.
//
// POR QUE POINTER Y NO CLICK/TOUCH POR SEPARADO. `pointerdown`/`pointerup`
// cubren dedo y raton con el mismo codigo, que es lo que hace falta para
// probarlo en Playwright (cursor) y para que funcione igual con guantes en la
// tablet real. `pointercancel` y `pointerleave` cancelan la cuenta: un dedo
// que se levanta fuera del boton, o una interrupcion del sistema (notificacion,
// bloqueo), no puede dejar un temporizador vivo que dispare mas tarde solo.
//
// PROGRAMACION INYECTABLE. `schedule`/`cancel` son `setTimeout`/`clearTimeout`
// por defecto: la prueba unitaria los sustituye (o usa temporizadores falsos de
// Vitest) para no esperar 3 s de verdad por cada caso.

const DEFAULT_DURATION_MS = 3_000

export interface UseLongPressOptions {
  readonly onLongPress: () => void
  readonly durationMs?: number
  readonly schedule?: (callback: () => void, ms: number) => ReturnType<typeof setTimeout>
  readonly cancel?: (handle: ReturnType<typeof setTimeout>) => void
}

export interface LongPressHandlers {
  onPointerDown(): void
  onPointerUp(): void
  onPointerCancel(): void
  onPointerLeave(): void
}

export function useLongPress(options: UseLongPressOptions): LongPressHandlers {
  const durationMs = options.durationMs ?? DEFAULT_DURATION_MS
  const schedule = options.schedule ?? ((callback, ms) => setTimeout(callback, ms))
  const cancel = options.cancel ?? ((handle) => clearTimeout(handle))

  let timer: ReturnType<typeof setTimeout> | null = null

  function clear(): void {
    if (timer === null) return
    cancel(timer)
    timer = null
  }

  return {
    onPointerDown() {
      // Una pulsacion nueva reinicia la cuenta: no se acumulan pulsaciones
      // cortas repetidas para sumar los 3 s.
      clear()
      timer = schedule(() => {
        timer = null
        options.onLongPress()
      }, durationMs)
    },
    onPointerUp: clear,
    onPointerCancel: clear,
    onPointerLeave: clear,
  }
}
