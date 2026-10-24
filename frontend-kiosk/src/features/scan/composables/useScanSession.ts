// Orquestacion de una sesion de escaneo: camara -> tuberia -> pantalla + sonido.
//
// Aqui es donde se mide y se cumple el presupuesto de los 300 ms del doc 02 §6:
// la parte del quiosco de RNF-P-01 (de la lectura a la confirmacion en
// pantalla), no la decodificacion de RNF-P-03.
// `handleDecoded` es sincrona y se pinta lo que devuelve en el mismo turno del
// bucle de eventos. `lastLatencyMs` deja el numero medido a la vista del E2E y
// de la pantalla de diagnostico, para que el presupuesto sea una comprobacion y
// no una promesa.
//
// PANTALLA INMEDIATA, SONIDO CON EL DESENLACE (RF-AT-05). La
// confirmacion visual sale al instante y MUDA. El tono lo decide el servidor:
//
//   - `settle` aceptado dentro de `SCAN_SOUND_SERVER_WAIT_MS` -> entry / exit
//     (debounced -> notice); rechazo -> error.
//   - plazo vencido, o sin red de antemano -> `pending`, una sola vez. La
//     respuesta que llegue despues actualiza la pantalla y NO suena.
//   - lo que se decide en local (una lectura que no es tarjeta KronoQR) suena
//     a error en el acto: no hay nada que esperar.
//   - nunca dos tonos por `scanId`: `soundedScanId` lo impide, y el
//     temporizador se cancela en cuanto suena el tono real. UNICA excepcion:
//     si ya sono `pending` y el desenlace final es un rechazo, suena `error`
//     (quien oyo «guardado» no puede quedarse creyendo que ficho).
//   - si una lectura local suena justo cuando se cierra la espera de otra
//     persona, su tono se desfasa `LOCAL_TONE_STAGGER_MS` para no mezclarse.
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
   * Pinta una confirmacion NUEVA. La usa el fichaje por PIN, que
   * no decodifica una camara: teclea, sella y llama aqui directamente en vez de
   * pasar por `accept()`. La tuberia del PIN ya ha esperado al servidor, asi
   * que aqui suena el desenlace tal cual, salvo `verifying` («Comprobando…»),
   * que es mudo: el tono llega con el desenlace.
   */
  present(confirmation: ScanConfirmation): void
  /** Aplica el desenlace real del servidor sobre la confirmacion en pantalla. */
  settle(confirmation: ScanConfirmation): void
  /**
   * El envio ha vuelto sin desenlace (fallo de red explicito, cola por delante):
   * suena `pending` ya, sin esperar al plazo del servidor. No hace
   * nada si ese escaneo ya sono, o si su espera se cerro antes.
   */
  markPending(scanId: string): void
  dismiss(): void
}

/** Separacion entre el `pending` de una espera cerrada y el tono local que le sigue. */
const LOCAL_TONE_STAGGER_MS = 250

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
  let soundedTone: FeedbackTone | null = null
  /** Tono local diferido (ver `accept`): como mucho uno vivo. */
  let deferredSound: ReturnType<typeof setTimeout> | null = null

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
    if (soundedScanId === scanId) {
      // Unica excepcion: ya sono `pending` («guardado») y el desenlace final es
      // un rechazo. Callar dejaria a la persona creyendo que ficho; suena `error`.
      // Nunca dos tonos de exito: `pending` -> entry/exit/notice sigue sin sonar.
      if (soundedTone !== 'pending' || tone !== 'error') return
    }
    soundedScanId = scanId
    soundedTone = tone
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
      const displacing = soundWait !== null && soundWait.scanId !== next.scanId
      show(next)
      if (next.kind === 'pending') {
        waitForServer(next)
      } else if (displacing) {
        // Decidido en local, pero el `pending` de la espera anterior acaba de
        // sonar en este mismo turno (`show`): se desfasa para no mezclar dos
        // tonos. Desfase y no silencio, para que la persona anterior oiga su
        // «guardado» y la siguiente su error.
        const scanId = next.scanId
        const tone = toneFor(next)
        if (deferredSound !== null) clearTimeout(deferredSound)
        deferredSound = setTimeout(() => {
          deferredSound = null
          playOnce(scanId, tone)
        }, LOCAL_TONE_STAGGER_MS)
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

    markPending(scanId) {
      if (soundWait?.scanId !== scanId) return
      playOnce(scanId, 'pending')
    },

    dismiss() {
      clearTimer()
      if (deferredSound !== null) clearTimeout(deferredSound)
      deferredSound = null
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
