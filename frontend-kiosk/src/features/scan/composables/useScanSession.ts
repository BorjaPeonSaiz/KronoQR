// Orquestacion de una sesion de escaneo: camara -> tuberia -> pantalla + sonido.
//
// Aqui es donde se mide y se cumple el presupuesto de los 300 ms (RNF-P-03).
// `handleDecoded` es sincrona, se pinta lo que devuelve y se toca el sonido en
// el mismo turno del bucle de eventos. `lastLatencyMs` deja el numero medido a
// la vista del E2E y de la pantalla de diagnostico, para que el presupuesto sea
// una comprobacion y no una promesa.
//
// Cuidado con las respuestas tardias: un `onSettled` de un escaneo anterior no
// puede pisar la confirmacion del que hay ahora en pantalla. Se compara por
// `scanId` antes de aplicar nada.

import type { Ref } from 'vue'
import { onUnmounted, readonly, ref, shallowRef } from 'vue'
import type { ScanConfirmation } from '../domain/scanOutcome'
import { CONFIRMATION_DISPLAY_MS, toneFor } from '../domain/scanOutcome'
import type { ScanPipeline } from '../application/scanPipeline'
import type { ScanSound } from './useScanSound'

export interface UseScanSessionOptions {
  readonly pipeline: ScanPipeline
  readonly sound: ScanSound
  /** Inyectable para pruebas deterministas. */
  readonly monotonicNow?: () => number
}

export interface ScanSession {
  readonly confirmation: Readonly<Ref<ScanConfirmation | null>>
  /** Milisegundos entre la decodificacion y la confirmacion en pantalla. */
  readonly lastLatencyMs: Readonly<Ref<number | null>>
  /** Punto de entrada del bucle de decodificacion. */
  accept(rawText: string): void
  /** Aplica el desenlace real del servidor sobre la confirmacion en pantalla. */
  settle(confirmation: ScanConfirmation): void
  dismiss(): void
}

function defaultNow(): number {
  return typeof performance === 'undefined' ? Date.now() : performance.now()
}

export function useScanSession(options: UseScanSessionOptions): ScanSession {
  const confirmation = shallowRef<ScanConfirmation | null>(null)
  const lastLatencyMs = ref<number | null>(null)
  const now = options.monotonicNow ?? defaultNow

  let dismissTimer: ReturnType<typeof setTimeout> | null = null

  function clearTimer(): void {
    if (dismissTimer === null) return
    clearTimeout(dismissTimer)
    dismissTimer = null
  }

  function show(next: ScanConfirmation, withSound: boolean): void {
    clearTimer()
    confirmation.value = next
    if (withSound) options.sound.play(toneFor(next))
    dismissTimer = setTimeout(() => {
      // Solo se retira si sigue siendo la misma: entre medias puede haber
      // fichado otra persona.
      if (confirmation.value?.scanId === next.scanId) confirmation.value = null
      dismissTimer = null
    }, CONFIRMATION_DISPLAY_MS[next.kind])
  }

  return {
    confirmation: confirmation as Readonly<Ref<ScanConfirmation | null>>,
    lastLatencyMs: readonly(lastLatencyMs),

    accept(rawText) {
      const startedAt = now()
      const next = options.pipeline.handleDecoded(rawText)
      if (next === null) return // Repeticion inmediata del mismo codigo.
      show(next, true)
      lastLatencyMs.value = Math.round(now() - startedAt)
    },

    settle(next) {
      const current = confirmation.value
      if (current === null || current.scanId !== next.scanId) return
      // El sonido ya ha sonado al confirmar en local. Repetirlo al llegar la
      // respuesta del servidor haria sonar dos pitidos por un unico fichaje, y
      // el segundo llegaria cuando la persona ya se ha dado la vuelta.
      show(next, false)
    },

    dismiss() {
      clearTimer()
      confirmation.value = null
    },
  }
}

/** Limpieza del temporizador al desmontar. Se expone aparte para poder probarlo. */
export function useScanSessionWithCleanup(options: UseScanSessionOptions): ScanSession {
  const session = useScanSession(options)
  onUnmounted(() => session.dismiss())
  return session
}
