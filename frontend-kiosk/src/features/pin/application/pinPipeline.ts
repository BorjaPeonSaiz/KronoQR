// La tuberia del fichaje por PIN (RF-AT-11). Hermana de
// `features/scan/application/scanPipeline.ts`, con una diferencia de fondo: el
// escaneo de tarjeta decodifica y confirma en el MISMO turno del bucle de
// eventos (sincrono, RNF-P-03); el PIN tiene un paso previo que es
// inevitablemente async — sellarlo — porque `crypto_box_seal` corre sobre
// WebAssembly. No es red: es trabajo local, y `warmUpSealing()` (llamado al
// montar la pantalla) hace que para cuando llega el sexto digito ya este
// caliente y el sellado tarde microsegundos, no milisegundos.
//
// Fuera de eso, la disciplina es identica: `submit()` encola (via el mismo
// `ScanSubmissionPort` de la 1.9) y confirma ANTES de saber que dira el
// servidor; el envio real sigue en segundo plano y llega por `onSettled`.
//
// LA DIFERENCIA QUE SI IMPORTA: EL QR SE PUEDE VALIDAR EN LOCAL, EL PIN NO.
// El nombre del QR sale del padron cacheado (regla dura 19): pintar
// «pendiente» de inmediato es honesto, es lo unico que se sabe. El PIN viaja
// SELLADO — solo el servidor lo abre — asi que «pendiente» antes de saber
// nada seria enseñar una confirmacion que parece un exito y luego, si el
// servidor rechaza, sustituirla por un rechazo: eso es lo que este fichero
// evita. Mientras hay red se pinta `verifying` («Comprobando…») y solo se
// pasa a `pending` si no hay contestacion en `PIN_VERIFY_TIMEOUT_MS` o si no
// hay red en absoluto (regla dura 19: nunca se espera a la red, nunca se
// bloquea el fichaje).
//
// EL UMBRAL DE GRACIA (`PIN_VERIFY_GRACE_MS`). En el despliegue habitual — un
// servidor on-premise en la misma VLAN que la tablet (ADR-002/ADR-017) — la
// respuesta llega en 50-200 ms. Pintar «Comprobando…» y sustituirlo por el
// desenlace real en ese margen no es honesto-y-completo, es un parpadeo: dos
// pintados y dos sonidos por un unico fichaje. Por eso `submit()` da al
// servidor una ventana corta ANTES de resolver: si contesta dentro de ella,
// `submit()` devuelve directamente el desenlace real (un solo pintado, un
// solo sonido) y `onSettled` no se invoca para ese resultado — la funcion
// que llama YA tiene la version final. Si no contesta a tiempo (o solo se
// sabe que sigue «deferred» en la cola, sin veredicto), se sigue el diseno
// de siempre: `verifying` de inmediato y el desenlace, o el aplazamiento a
// `pending`, por `onSettled`.

import type { QueuedPinScan, ScanSubmissionPort } from '@/features/scan/application/ports'
import type { ScanConfirmation } from '@/features/scan/domain/scanOutcome'
import { PIN_VERIFY_TIMEOUT_MS } from '@/features/scan/domain/scanOutcome'
import { settleFrom } from '@/features/scan/application/settleFrom'
import type { Clock } from '@/shared/time/clock'
import { systemClock, toUtcIso } from '@/shared/time/clock'
import { uuidV7 } from '@/shared/ids/uuidV7'
import { sealPin } from '../infrastructure/pinSealing'

export { PIN_VERIFY_TIMEOUT_MS }

/**
 * Cuanto tiempo espera `submit()`, antes de resolver, a ver si el servidor ya
 * contesto. No es el plazo total de la via del PIN (ese sigue siendo
 * `PIN_VERIFY_TIMEOUT_MS`, contado desde que `verifying` aparece en
 * pantalla): es solo el margen para no enseñar «Comprobando…» cuando la
 * respuesta iba a llegar de todos modos antes de que nadie pudiera leerlo.
 * Vive aqui, no en `scanOutcome.ts`, porque es un detalle de esta tuberia, no
 * una duracion de pantalla.
 */
export const PIN_VERIFY_GRACE_MS = 300

export interface PinPipelineOptions {
  readonly submission: ScanSubmissionPort
  readonly deviceId: string
  /** `pin_sealing_public_key` del padron. Nunca `null`: la pantalla no existe si lo es. */
  readonly publicKey: string
  readonly clock?: Clock
  readonly newScanId?: () => string
  readonly seal?: (pin: string, publicKey: string) => Promise<string>
  /**
   * `true` si no hay red. Por defecto se asume que la hay (`false`): sin
   * conexion no tiene sentido esperar `PIN_VERIFY_TIMEOUT_MS` para llegar a la
   * misma conclusion honesta — «pendiente» — que ya se sabe de antemano.
   */
  readonly isOffline?: () => boolean
  /** Llega cuando el servidor contesta. Puede no llegar nunca: es opcional por diseno. */
  readonly onSettled?: (confirmation: ScanConfirmation) => void
  readonly onError?: (
    code: 'seal_failed' | 'submit_failed',
    context: Record<string, string | number | boolean>,
  ) => void
}

export interface PinPipeline {
  /**
   * @returns la confirmacion a pintar. Nunca lanza: un sellado que falla se
   *          convierte en el mismo rechazo generico que cualquier otra causa
   *          (regla dura 17), nunca en una pantalla rota delante de una cola.
   *
   *          Con red: si el servidor contesta dentro de `PIN_VERIFY_GRACE_MS`
   *          con un desenlace real, ESE es el que se devuelve (un unico
   *          pintado, sin pasar por «Comprobando…»). Si no, `verifying`
   *          («Comprobando…»); el desenlace real, o el aplazamiento a
   *          `pending` si se queda sin respuesta a tiempo, llega despues por
   *          `onSettled`. Sin red: `pending` directamente, sin esperar
   *          ninguna de las dos ventanas.
   */
  submit(employeeCode: string, pin: string): Promise<ScanConfirmation>
}

/** Centinela propio: `dispatch()` puede resolver a `null` (sigue en cola) de
 *  forma legitima, asi que la carrera necesita un valor que nunca coincida
 *  con un desenlace real para distinguir «vencio el plazo». */
const VERIFY_TIMED_OUT = Symbol('pin-verify-timed-out')

/** Mismo motivo que `VERIFY_TIMED_OUT`, para la ventana de gracia. */
const GRACE_TIMED_OUT = Symbol('pin-verify-grace-timed-out')

export function createPinPipeline(options: PinPipelineOptions): PinPipeline {
  const clock = options.clock ?? systemClock
  const newScanId = options.newScanId ?? (() => uuidV7(clock.now().getTime()))
  const seal = options.seal ?? sealPin
  const isOffline = options.isOffline ?? (() => false)

  function settle(confirmation: ScanConfirmation): void {
    options.onSettled?.(confirmation)
  }

  /**
   * Envia el escaneo ya encolado. Nunca lanza: un fallo de transporte o de
   * la cola se traduce en `null`, exactamente igual que un `deferred` del
   * servidor — en los dos casos lo unico honesto es «sigue sin decidirse».
   */
  async function dispatch(scan: QueuedPinScan, occurredAt: Date): Promise<ScanConfirmation | null> {
    let result
    try {
      result = await options.submission.submit(scan)
    } catch (error) {
      options.onError?.('submit_failed', {
        reason: error instanceof Error ? error.name : 'unknown',
      })
      return null
    }

    return settleFrom(result, scan.scan_id, occurredAt)
  }

  /**
   * Corre `dispatch()` contra el reloj: si contesta antes de
   * `PIN_VERIFY_TIMEOUT_MS`, gana esa respuesta (aunque sea `null`, «sigue en
   * cola»); si no, gana el plazo. El temporizador se limpia siempre, gane
   * quien gane, para no dejar un `setTimeout` colgado ocho horas de turno.
   */
  async function verifyWithTimeout(
    dispatchPromise: Promise<ScanConfirmation | null>,
  ): Promise<ScanConfirmation | null | typeof VERIFY_TIMED_OUT> {
    let timer!: ReturnType<typeof setTimeout>
    const timeout = new Promise<typeof VERIFY_TIMED_OUT>((resolve) => {
      timer = setTimeout(() => resolve(VERIFY_TIMED_OUT), PIN_VERIFY_TIMEOUT_MS)
    })

    try {
      return await Promise.race([dispatchPromise, timeout])
    } finally {
      clearTimeout(timer)
    }
  }

  /**
   * La ventana de gracia (ver comentario de cabecera). Devuelve el desenlace
   * si `dispatchPromise` ya lo sabe DENTRO de la gracia — incluido `null`
   * («deferred», sigue en cola: no es un desenlace real, pero tampoco hay
   * nada mas que esperar de ESTA promesa, asi que no tiene sentido agotar el
   * resto de la gracia sin motivo) — o `GRACE_TIMED_OUT` si se agota antes.
   * El temporizador se limpia siempre, gane quien gane.
   */
  async function raceGrace(
    dispatchPromise: Promise<ScanConfirmation | null>,
  ): Promise<ScanConfirmation | null | typeof GRACE_TIMED_OUT> {
    let timer!: ReturnType<typeof setTimeout>
    const timeout = new Promise<typeof GRACE_TIMED_OUT>((resolve) => {
      timer = setTimeout(() => resolve(GRACE_TIMED_OUT), PIN_VERIFY_GRACE_MS)
    })

    try {
      return await Promise.race([dispatchPromise, timeout])
    } finally {
      clearTimeout(timer)
    }
  }

  return {
    async submit(employeeCode, pin) {
      const occurredAt = clock.now()
      const scanId = newScanId()

      let pinSealed: string
      try {
        pinSealed = await seal(pin, options.publicKey)
      } catch (error) {
        // El PIN en claro NUNCA sale de aqui, ni siquiera en el contexto de
        // error: solo el nombre tecnico del fallo (regla dura 21).
        options.onError?.('seal_failed', {
          reason: error instanceof Error ? error.name : 'unknown',
        })
        return { kind: 'rejected', scanId, occurredAt }
      }

      const scan: QueuedPinScan = {
        kind: 'pin',
        scan_id: scanId,
        employee_code: employeeCode,
        pin_sealed: pinSealed,
        occurred_at: toUtcIso(occurredAt),
        intent: 'auto',
        device_id: options.deviceId,
      }

      const dispatchPromise = dispatch(scan, occurredAt)
      const pendingConfirmation: ScanConfirmation = {
        kind: 'pending',
        scanId,
        occurredAt,
        displayName: null,
      }

      if (isOffline()) {
        // Regla dura 19: nunca se espera a la red. Sin conexion, «Comprobando…»
        // no aportaria nada honesto: no hay nadie al otro lado ahora mismo.
        // Se pinta «pendiente» YA; si el envio prospera mas tarde (la cola se
        // drena sola al volver la red), el desenlace real llega por `onSettled`
        // exactamente igual que hoy.
        void dispatchPromise.then((confirmation) => {
          if (confirmation !== null) settle(confirmation)
        })
        return pendingConfirmation
      }

      // Hay red: antes de decidir que pintar, se le da al servidor la
      // ventana de gracia. Si contesta con un desenlace REAL dentro de ella
      // (no un mero «sigue en cola»), ese es el veredicto final: se devuelve
      // directamente, sin pasar por «Comprobando…» y sin tocar `onSettled`
      // — un unico pintado, un unico sonido, tal y como lo veria el QR.
      const graceOutcome = await raceGrace(dispatchPromise)
      if (graceOutcome !== GRACE_TIMED_OUT && graceOutcome !== null) {
        return graceOutcome
      }

      // O bien se agoto la gracia sin respuesta, o bien la respuesta que
      // llego solo decia «sigue en cola» (`deferred`, sin veredicto): en los
      // dos casos lo honesto desde aqui es lo de siempre — avisar que se
      // esta comprobando y dejar que la carrera contra `PIN_VERIFY_TIMEOUT_MS`
      // decida, en segundo plano, si se sustituye por el desenlace real o
      // por «pendiente» — pero eso llega por `onSettled`, fuera del camino
      // critico de esta llamada.
      void verifyWithTimeout(dispatchPromise).then((outcome) => {
        if (outcome !== null && outcome !== VERIFY_TIMED_OUT) {
          settle(outcome)
          return
        }

        // Vencio el plazo, o el envio ya sabe que sigue en cola (`null`): en
        // los dos casos lo honesto es «pendiente».
        settle(pendingConfirmation)

        if (outcome === VERIFY_TIMED_OUT) {
          // La respuesta puede llegar mas tarde: se aplica igual que hoy.
          void dispatchPromise.then((confirmation) => {
            if (confirmation !== null) settle(confirmation)
          })
        }
      })

      return { kind: 'verifying', scanId, occurredAt }
    },
  }
}
