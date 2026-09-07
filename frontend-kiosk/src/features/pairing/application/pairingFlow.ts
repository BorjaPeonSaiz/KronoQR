// Maquina de estados del emparejamiento (RF-PD-06, tarea 5.6).
//
// idle -> requesting -> waiting(code, expiresAt) -> paired
//                             |            ^
//                             `-- expired -+-- rejected --'
//                                  (siempre vuelve a `requesting` SOLA)
//
// REGLA DURA 19, LITERAL AQUI. La tablet nunca se queda en un estado sin
// salida: una caducidad, un rechazo o un fallo de RED pidiendo el codigo se
// resuelven por si solos, sin que nadie toque la pantalla. Solo tres cosas
// paran esta maquina: que se llegue a `paired`, que alguien llame a `stop()`
// (desmontar la pantalla) o que se pida un codigo nuevo a mano con
// `requestNewCode()` (boton «Generar otro codigo»).
//
// QUE SI ES UN CALLEJON SIN SALIDA Y QUE NO. Un rechazo de `claim`
// (`PairingRejected`, regla dura 17: la causa es indistinguible, y no hace
// falta distinguirla) descarta la solicitud y pide una nueva. Un fallo de
// TRANSPORTE del sondeo (red, `429`, `5xx`, respuesta mal formada) NO es un
// rechazo: la solicitud sigue viva, así que se sigue sondeando en el mismo
// sitio. Tratarlo como rechazo tirarìa codigos validos por una intermitencia
// de wifi, y el limitador de `claim` es por `pairing_id`: pedir uno nuevo en
// cada parpadeo de red lo agotaria sin necesidad.
//
// GENERACIONES, NO BANDERAS SUELTAS. Cada `start()`/`stop()`/`requestNewCode()`
// incrementa `generation`. Una promesa en vuelo (una peticion HTTP que tarda)
// comprueba su generacion al volver: si ha cambiado, no toca el estado ni
// programa nada mas. Es lo mismo que hacen `syncRunner.ts` y `useQrScanner.ts`
// para no dejar un temporizador o un `then()` zombi actuando sobre un
// controlador que ya no es el vigente.

import type { ApiClient, ApiResult } from '@/shared/api/client'
import type { PairingClaim, PairingCompleted, PairingRejected } from '@/shared/api/types'
import type { Clock } from '@/shared/time/clock'
import { systemClock } from '@/shared/time/clock'

export type PairingRequestReason = 'initial' | 'expired' | 'rejected' | 'manual'

export type PairingState =
  | { readonly kind: 'idle' }
  | { readonly kind: 'requesting'; readonly reason: PairingRequestReason }
  | {
      readonly kind: 'waiting'
      readonly pairingId: string
      readonly pairingSecret: string
      readonly code: string
      readonly expiresAt: string
      readonly pollIntervalSeconds: number
    }
  | {
      readonly kind: 'paired'
      readonly device: PairingCompleted['device']
      readonly token: PairingCompleted['token']
    }

export interface PairingFlowOptions {
  readonly api: Pick<ApiClient, 'requestPairing' | 'claimPairing'>
  readonly appVersion: string
  readonly clock?: Clock
  readonly setTimer?: (handler: () => void, delayMs: number) => number
  readonly clearTimer?: (handle: number) => void
  /** Reintento tras un fallo de RED pidiendo codigo. 5 s por defecto. */
  readonly requestRetryDelayMs?: number
  readonly onStateChange: (state: PairingState) => void
  /** Se llama UNA vez, justo al entrar en `paired`. Nunca en un reintento. */
  readonly onPaired: (result: PairingCompleted) => void
}

export interface PairingFlow {
  /** Idempotente: si ya esta en marcha, no hace nada. */
  start(): void
  /** Para el sondeo y cualquier reintento programado. No cambia el estado. */
  stop(): void
  /** Boton «Generar otro codigo»: descarta lo que hubiera y pide uno nuevo YA. */
  requestNewCode(): void
}

const DEFAULT_REQUEST_RETRY_DELAY_MS = 5_000

export function createPairingFlow(options: PairingFlowOptions): PairingFlow {
  const clock = options.clock ?? systemClock
  const setTimer =
    options.setTimer ?? ((handler, delayMs) => setTimeout(handler, delayMs) as unknown as number)
  const clearTimer = options.clearTimer ?? ((handle) => clearTimeout(handle))
  const requestRetryDelayMs = options.requestRetryDelayMs ?? DEFAULT_REQUEST_RETRY_DELAY_MS

  let state: PairingState = { kind: 'idle' }
  let pollTimer: number | null = null
  let requestTimer: number | null = null
  let running = false
  /** Se incrementa en cada arranque/parada/peticion manual. Ver cabecera. */
  let generation = 0
  /**
   * El `poll_interval_seconds` de la ULTIMA solicitud que el servidor llego a
   * emitir en esta instancia de la maquina, si hubo alguna. Se usa como
   * cadencia de reintento cuando `POST /kiosk/pair` falla en transporte —por
   * ejemplo un `503` porque la instalacion tiene mas solicitudes vivas de las
   * que admite `kiosk.pairing.max_live_pending`—: si ya se conoce el ritmo que
   * pide esta instalacion, reintentar a ese ritmo es mas fiel que un `5 s` fijo
   * que nadie ha configurado. Sin ticket previo (primer arranque, tablet recien
   * instalada) se cae al valor por defecto.
   */
  let lastKnownPollIntervalSeconds: number | null = null

  function setState(next: PairingState): void {
    state = next
    options.onStateChange(next)
  }

  function clearTimers(): void {
    if (pollTimer !== null) {
      clearTimer(pollTimer)
      pollTimer = null
    }
    if (requestTimer !== null) {
      clearTimer(requestTimer)
      requestTimer = null
    }
  }

  function schedulePoll(myGeneration: number, pollIntervalSeconds: number): void {
    pollTimer = setTimer(() => {
      pollTimer = null
      void poll(myGeneration)
    }, pollIntervalSeconds * 1_000)
  }

  async function requestCode(myGeneration: number, reason: PairingRequestReason): Promise<void> {
    setState({ kind: 'requesting', reason })

    const result = await options.api.requestPairing({ app_version: options.appVersion })
    if (myGeneration !== generation) return // Detenido o superado mientras se pedia.

    if (result.outcome === 'ok') {
      const data = result.data
      lastKnownPollIntervalSeconds = data.poll_interval_seconds
      setState({
        kind: 'waiting',
        pairingId: data.pairing_id,
        pairingSecret: data.pairing_secret,
        code: data.code,
        expiresAt: data.expires_at,
        pollIntervalSeconds: data.poll_interval_seconds,
      })
      schedulePoll(myGeneration, data.poll_interval_seconds)
      return
    }

    // Fallo de transporte pidiendo el codigo (red, `429`, `503` -limite de
    // solicitudes vivas o espacio de codigos agotado, ver el contrato-,
    // `5xx`...). No es un rechazo: no hay nada que descartar, solo se
    // reintenta. La pantalla se queda en «requesting» — nunca en blanco —
    // mientras tanto. La cadencia es la del ultimo ticket conocido si lo hay,
    // o el valor por defecto si esta es la primera solicitud de la sesion.
    const retryDelayMs =
      lastKnownPollIntervalSeconds !== null
        ? lastKnownPollIntervalSeconds * 1_000
        : requestRetryDelayMs
    requestTimer = setTimer(() => {
      requestTimer = null
      void requestCode(myGeneration, reason)
    }, retryDelayMs)
  }

  async function poll(myGeneration: number): Promise<void> {
    if (myGeneration !== generation || state.kind !== 'waiting') return
    const { pairingId, pairingSecret, expiresAt, pollIntervalSeconds } = state

    // La caducidad la vigila tambien el CLIENTE: si ya paso `expires_at`, ni
    // se sondea, se pide un codigo nuevo ya (regla dura 19). El servidor la
    // vigila igual (`PairingRejected` si se sondeara de todos modos), pero
    // esperar a esa respuesta seria un viaje de red de mas para algo que la
    // propia tablet ya sabe.
    if (clock.now().getTime() >= Date.parse(expiresAt)) {
      void requestCode(myGeneration, 'expired')
      return
    }

    const result: ApiResult<PairingClaim, PairingRejected> = await options.api.claimPairing({
      pairing_id: pairingId,
      pairing_secret: pairingSecret,
    })
    if (myGeneration !== generation || state.kind !== 'waiting') return

    if (result.outcome === 'ok') {
      if (result.data.status === 'paired') {
        const { device, token } = result.data
        setState({ kind: 'paired', device, token })
        options.onPaired(result.data)
        return
      }
      // `pending`: nadie ha confirmado todavia. Se sigue sondeando en el
      // mismo sitio, con el MISMO `pairing_id`/`pairing_secret`.
      schedulePoll(myGeneration, pollIntervalSeconds)
      return
    }

    if (result.outcome === 'rejected') {
      // Unica respuesta de rechazo posible (regla dura 17): la solicitud ya no
      // sirve, sea cual sea la causa real. Se pide una nueva sola.
      void requestCode(myGeneration, 'rejected')
      return
    }

    // 'failed': red, tiempo agotado, `429`, `503` (mismo `ServiceUnavailable`
    // del contrato que `/kiosk/pair`), cualquier otro `5xx` o cuerpo mal
    // formado. Nada de esto es un rechazo: se sigue sondeando la MISMA
    // solicitud en el siguiente ciclo. Ni un `429` ni un `503` aqui deben
    // romper el sondeo ni gastar una solicitud nueva -- el limitador de
    // `claim` es por `pairing_id`, y la solicitud sigue viva en el servidor.
    schedulePoll(myGeneration, pollIntervalSeconds)
  }

  return {
    start() {
      if (running) return
      running = true
      generation += 1
      void requestCode(generation, 'initial')
    },

    stop() {
      running = false
      generation += 1
      clearTimers()
    },

    requestNewCode() {
      if (!running) return
      generation += 1
      clearTimers()
      void requestCode(generation, 'manual')
    },
  }
}
