// Orquestacion de una sesion de escaneo: camara -> tuberia -> pantalla + sonido.
//
// Aqui es donde se mide y se cumple el presupuesto de los 300 ms (RNF-P-03).
// `handleDecoded` es sincrona y se pinta lo que devuelve en el mismo turno del
// bucle de eventos. `lastLatencyMs` deja el numero medido a la vista del E2E y
// de la pantalla de diagnostico, para que el presupuesto sea una comprobacion y
// no una promesa.
//
// PANTALLA INMEDIATA, SONIDO CON EL DESENLACE (RF-AT-05, V7-KI-01). La
// confirmacion visual sale al instante y MUDA. El tono lo decide el servidor:
//
//   - `settle` aceptado dentro de `SCAN_SOUND_SERVER_WAIT_MS` -> entry / exit
//     (debounced -> notice); rechazo -> error.
//   - plazo vencido, o sin red de antemano -> `pending`, una sola vez. La
//     respuesta que llegue despues actualiza la pantalla y NO suena.
//   - lo que se decide en local (una lectura que no es tarjeta KronoQR) suena
//     a error en el acto: no hay nada que esperar.
//   - nunca dos tonos por `scanId`: `soundedScanId` lo impide, y el
//     temporizador se cancela en cuanto suena el tono real.
//
// Si el audio esta bloqueado o falla, se ignora: un sonido que no suena no
// puede impedir ni retrasar un fichaje (regla dura 19).
//
// Cuidado con las respuestas tardias: un `onSettled` de un escaneo anterior no
// puede pisar la confirmacion del que hay ahora en pantalla. Se compara por
// `scanId` antes de aplicar nada.

import type { Ref } from 'vue'
import { onUnmounted, readonly, ref, shallowRef } from 'vue'
import type { FeedbackTone, ScanConfirmation } from '../domain/scanOutcome'
import { CONFIRMATION_DISPLAY_MS, SCAN_SOUND_SERVER_WAIT_MS, toneFor } from '../domain/scanOutcome'
import type { ScanPipeline } from '../application/scanPipeline'
import type { ScanSound } from './useScanSound'

export interface UseScanSessionOptions {
  readonly pipeline: ScanPipeline
  readonly sound: ScanSound
  /** Inyectable para pruebas deterministas. */
  readonly monotonicNow?: () => number
  /**
   * `true` si no hay red AHORA. Con la red caida el servidor no va a decidir
   * dentro del plazo: `pending` suena en el acto en vez de esperar 2 s a saber
   * lo que ya se sabe.
   */
  readonly isOffline?: () => boolean
}

export interface ScanSession {
  readonly confirmation: Readonly<Ref<ScanConfirmation | null>>
  /** Milisegundos entre la decodificacion y la confirmacion en pantalla. */
  readonly lastLatencyMs: Readonly<Ref<number | null>>
  /** Punto de entrada del bucle de decodificacion. */
  accept(rawText: string): void
  /**
   * Pinta una confirmacion NUEVA. La usa el fichaje por PIN (tarea 1.12), que
   * no decodifica una camara: teclea, sella y llama aqui directamente en vez de
   * pasar por `accept()`. La tuberia del PIN ya ha esperado al servidor, asi
   * que aqui suena el desenlace tal cual, salvo `verifying` («Comprobando…»),
   * que es mudo: el tono llega con el desenlace.
   */
  present(confirmation: ScanConfirmation): void
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
  const isOffline = options.isOffline ?? (() => false)

  let dismissTimer: ReturnType<typeof setTimeout> | null = null
  /** Espera del servidor de UN escaneo concreto; como mucho hay una viva. */
  let soundWait: { readonly scanId: string; readonly timer: ReturnType<typeof setTimeout> } | null =
    null
  let soundedScanId: string | null = null

  function clearTimer(): void {
    if (dismissTimer === null) return
    clearTimeout(dismissTimer)
    dismissTimer = null
  }

  function cancelSoundWait(): void {
    if (soundWait === null) return
    clearTimeout(soundWait.timer)
    soundWait = null
  }

  /** El unico punto por el que suena algo: un tono por `scanId`, nunca dos. */
  function playOnce(scanId: string, tone: FeedbackTone): void {
    if (soundedScanId === scanId) return
    soundedScanId = scanId
    if (soundWait?.scanId === scanId) cancelSoundWait()
    try {
      options.sound.play(tone)
    } catch {
      // Audio bloqueado o roto: se ignora, sin error (regla dura 19).
    }
  }

  function show(next: ScanConfirmation): void {
    clearTimer()
    // Otra persona ficha antes de que el servidor decidiera el tono de la
    // anterior: su pantalla se retira, asi que se le cierra el sonido con
    // `pending` (queda registrado, el resultado se sabra luego) en vez de
    // dejarla sin ningun aviso. Con ello no queda ninguna espera huerfana.
    if (soundWait !== null && soundWait.scanId !== next.scanId) {
      playOnce(soundWait.scanId, 'pending')
    }
    confirmation.value = next
    dismissTimer = setTimeout(() => {
      // Solo se retira si sigue siendo la misma: entre medias puede haber
      // fichado otra persona.
      if (confirmation.value?.scanId === next.scanId) confirmation.value = null
      dismissTimer = null
    }, CONFIRMATION_DISPLAY_MS[next.kind])
  }

  /** Empieza a esperar al servidor para decidir el tono de `next` (pendiente de QR). */
  function waitForServer(next: ScanConfirmation): void {
    if (isOffline()) {
      playOnce(next.scanId, 'pending')
      return
    }
    cancelSoundWait()
    const scanId = next.scanId
    const timer = setTimeout(() => {
      soundWait = null
      playOnce(scanId, 'pending')
    }, SCAN_SOUND_SERVER_WAIT_MS)
    soundWait = { scanId, timer }
  }

  return {
    confirmation: confirmation as Readonly<Ref<ScanConfirmation | null>>,
    lastLatencyMs: readonly(lastLatencyMs),

    accept(rawText) {
      const startedAt = now()
      const next = options.pipeline.handleDecoded(rawText)
      if (next === null) return // Repeticion inmediata del mismo codigo.
      show(next)
      if (next.kind === 'pending') {
        waitForServer(next)
      } else {
        // Decidido en local (lectura que no es tarjeta): suena en el acto.
        playOnce(next.scanId, toneFor(next))
      }
      lastLatencyMs.value = Math.round(now() - startedAt)
    },

    present(next) {
      show(next)
      if (next.kind === 'verifying') return
      playOnce(next.scanId, toneFor(next))
    },

    settle(next) {
      const current = confirmation.value
      if (current === null || current.scanId !== next.scanId) return
      // Dentro del plazo suena el desenlace real; pasado el plazo `playOnce`
      // ya no deja sonar nada (ya sono `pending`) y solo cambia la pantalla.
      show(next)
      if (next.kind === 'verifying') return
      playOnce(next.scanId, toneFor(next))
    },

    dismiss() {
      clearTimer()
      cancelSoundWait()
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
