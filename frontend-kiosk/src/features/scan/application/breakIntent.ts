// Estado del boton «Pausa» del quiosco (RF-AT-12, tarea 3.5, decision 5 del
// ADR-024, plan 06 -> "Tarea 3.5" -> "Decisiones tomadas").
//
// «La eleccion en el quiosco: un boton "Pausa" que se ARMA antes de pasar la
// tarjeta, no una pregunta despues.» Un toque lo arma, el SIGUIENTE fichaje
// (tarjeta o PIN) que de verdad se encola se envia con `intent: 'break_start'`,
// y se desarma a los `BREAK_ARM_TIMEOUT_MS`, en cuanto ese fichaje se encola,
// ante un escaneo que NO se encola (ilegible o repetido, ver `scanPipeline.ts`
// -> `onUnqueuedRead`) o al cambiar de pantalla (`useBreakIntent.ts`). Nadie
// mas anade un paso: quien entra, sale o vuelve de la pausa solo pasa la
// tarjeta (`auto`) y el servidor resuelve (`ScanIntentPolicy` en el backend).
//
// LA INTENCION ES DE LA TABLET, NO DE LA PERSONA (revision de la segunda
// vuelta, seguridad): en una cola de cambio de turno, otro empleado puede
// pasar SU tarjeta dentro de los 10 s de armado. Por eso el arme NO sobrevive
// a un cambio de pantalla, ni a un escaneo que no llega a fichar nada: el
// margen de confusion se cierra en cuanto deja de estar claro que quien va a
// fichar es quien acaba de tocar el boton.
//
// SINGLETON POR TABLET, igual que el controlador de la cola offline
// (`useOfflineQueue.ts`) y el reporter de errores (`errorReporter.ts`): NO
// para que el arme sobreviva a la navegacion (ver arriba: no lo hace), sino
// para que dos pantallas montadas a la vez -no ocurre hoy con un unico router
// de una sola vista activa, pero es la MISMA garantia que ya dan los otros
// singletons- vean siempre el mismo estado. Los oyentes son un `Set`, no una
// unica opcion capturada en la primera llamada -el mismo fallo que la
// revision de la 3.3 corrigio en `onDeviceRevoked`-: cada pantalla que se
// monta se suscribe la suya, y el orden de montaje no importa.
//
// LOGICA PURA, sin Vue: se prueba con `vi.useFakeTimers()` igual que
// `useScanSession.ts`. La unica pieza de framework es el composable delgado
// de `composables/useBreakIntent.ts` (que anade el desarme al desmontar).

import type { ScanIntent } from '@/shared/api/types'

/** Tiempo que el boton permanece armado sin fichar (decision 5 de la tarea 3.5). */
export const BREAK_ARM_TIMEOUT_MS = 10_000

export interface BreakIntentControllerOptions {
  readonly armedForMs?: number
}

export interface BreakIntentController {
  isArmed(): boolean
  arm(): void
  disarm(): void
  /**
   * Intencion para el PROXIMO fichaje: `'break_start'` si esta armado,
   * `'auto'` si no. Desarma como efecto de leerla -"tras ese fichaje" de la
   * decision 5-, asi que solo se llama una vez por escaneo o PIN, justo al
   * encolar (nunca en una simple lectura de estado).
   */
  consumeIntent(): ScanIntent
  /** Se suscribe a cada cambio de armado. Devuelve la funcion para desengancharse. */
  onChange(listener: (armed: boolean) => void): () => void
  dispose(): void
}

export function createBreakIntentController(
  options: BreakIntentControllerOptions = {},
): BreakIntentController {
  const armedForMs = options.armedForMs ?? BREAK_ARM_TIMEOUT_MS
  let armed = false
  let timer: ReturnType<typeof setTimeout> | null = null
  const listeners = new Set<(armed: boolean) => void>()

  function clearTimer(): void {
    if (timer === null) return
    clearTimeout(timer)
    timer = null
  }

  function setArmed(value: boolean): void {
    if (armed === value) return
    armed = value
    for (const listener of listeners) listener(armed)
  }

  function disarm(): void {
    clearTimer()
    setArmed(false)
  }

  function arm(): void {
    clearTimer()
    setArmed(true)
    timer = setTimeout(() => {
      timer = null
      setArmed(false)
    }, armedForMs)
  }

  return {
    isArmed: () => armed,
    arm,
    disarm,
    consumeIntent() {
      if (!armed) return 'auto'
      disarm()
      return 'break_start'
    },
    onChange(listener) {
      listeners.add(listener)
      return () => {
        listeners.delete(listener)
      }
    },
    dispose() {
      clearTimer()
      listeners.clear()
    },
  }
}

let singleton: BreakIntentController | null = null

/** Una tablet, un boton «Pausa». Ver la cabecera de este fichero. */
export function getBreakIntentController(
  options: BreakIntentControllerOptions = {},
): BreakIntentController {
  singleton ??= createBreakIntentController(options)
  return singleton
}

/** Solo pruebas: fuerza un controlador nuevo entre casos. */
export function resetBreakIntentController(): void {
  singleton?.dispose()
  singleton = null
}
