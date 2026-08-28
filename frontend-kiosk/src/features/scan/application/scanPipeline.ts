// La tuberia del escaneo. Es el corazon de la regla dura 19.
//
// `handleDecoded` es SINCRONA y devuelve la confirmacion que hay que pintar.
// Ni un `await`, ni una promesa, ni una consulta a red en el camino: eso es lo
// que garantiza los 300 ms del §6 (RNF-P-03) por construccion y no por suerte.
// El envio al servidor sale despues, en segundo plano, y su resultado llega por
// `onSettled` para refrescar la pantalla con el total real del dia.
//
// Orden exacto del diagrama del §6:
//   decodificar -> verificar formato FH1 -> resolver nombre en el padron
//   cacheado -> encolar {scan_id, payload, occurred_at, device_id, intent}
//   -> confirmar en pantalla
//
// El empleado nunca espera a la red. Si falla, se reintenta; el empleado ya se
// ha ido.

import type { Clock } from '@/shared/time/clock'
import { systemClock, toUtcIso } from '@/shared/time/clock'
import { uuidV7 } from '@/shared/ids/uuidV7'
import { parseCredentialPayload } from '../domain/credentialPayload'
import type { ScanConfirmation } from '../domain/scanOutcome'
import type { QueuedScan, RosterLookupPort, ScanSubmissionPort } from './ports'
import { emptyRoster } from './ports'
import { settleFrom } from './settleFrom'

/**
 * Cadencia real de `onDecoded` para una tarjeta SOSTENIDA delante del
 * objetivo (medida, no supuesta):
 *
 *   - `useQrScanner.ts` configura ZXing con `delayBetweenScanSuccess: 800`
 *     (`DELAY_BETWEEN_SUCCESS_MS`), verificado en
 *     `tests/unit/useQrScanner.spec.ts` ("limita el ritmo de intentos").
 *   - `BrowserCodeReader.scanOneResult` (node_modules/@zxing/browser/esm/
 *     readers/BrowserCodeReader.js, funcion `loop`) invoca el callback en
 *     cuanto decodifica con exito y SOLO ENTONCES reprograma el siguiente
 *     intento pasado `delayBetweenScanSuccess`: `setTimeout(loop,
 *     options.delayBetweenScanSuccess)`. El tiempo de decodificar un
 *     fotograma es despreciable frente a eso.
 *
 * Es decir: una tarjeta que no se mueve produce un `onDecoded` cada ~800 ms,
 * NO en continuo. `HELD_GAP_MS` es el hueco de AUSENCIA (ningun `onDecoded`
 * del mismo payload) que hace falta ver para dejar de considerar que es la
 * MISMA presentacion: con margen de mas de 2x esa cadencia para telefonos
 * lentos y fotogramas perdidos.
 *
 * Mientras el hueco entre dos lecturas del mismo payload sea menor que esto,
 * `handleDecoded` no vuelve a aceptar el payload pase el tiempo que pase — ni
 * siquiera tras `LOCAL_REPEAT_WINDOW_MS` —: es la MISMA tarjeta, apoyada, no
 * un reescaneo nuevo. Esto es lo que evita que una tarjeta olvidada delante
 * de la camara siga generando `scan_id`, filas en la cola y peticiones cada
 * pocos segundos indefinidamente.
 */
export const HELD_GAP_MS = 2_000

/**
 * Ventana en la que un payload leido de nuevo, tras haber estado AUSENTE al
 * menos `HELD_GAP_MS` (ver arriba), se sigue ignorando por ser el mismo
 * gesto: alguien retira la tarjeta y la vuelve a acercar sin darse cuenta, o
 * dos lecturas del mismo frame llegan separadas durante la retirada.
 *
 * No es el anti-rebote de RF-AT-06, que es del servidor y tiene consecuencias
 * de negocio. Esto es puramente optico y, a diferencia de `HELD_GAP_MS`, se
 * mide desde el ultimo escaneo ACEPTADO, no desde la ultima lectura: pasada
 * esta ventana (y con la tarjeta ya ausente segun `HELD_GAP_MS`), la MISMA
 * tarjeta vuelve a producir un fichaje si se presenta de nuevo — nunca
 * silencio, porque quien la retiro y la vuelve a apoyar espera respuesta.
 *
 * TIENE que ser MENOR que la confirmacion mas larga en pantalla
 * (`CONFIRMATION_DISPLAY_MS`, hasta 5000 ms): si alguien retira la tarjeta en
 * cuanto ve su confirmacion y la vuelve a apoyar poco despues, tiene que
 * haber respuesta antes de que la confirmacion anterior se retire sola.
 */
export const LOCAL_REPEAT_WINDOW_MS = 2_500

export interface ScanPipelineOptions {
  readonly submission: ScanSubmissionPort
  readonly deviceId: string
  readonly roster?: RosterLookupPort
  readonly clock?: Clock
  readonly newScanId?: () => string
  readonly repeatWindowMs?: number
  readonly heldGapMs?: number
  /** Llega cuando el servidor contesta. Puede no llegar nunca: es opcional por diseno. */
  readonly onSettled?: (confirmation: ScanConfirmation) => void
  readonly onError?: (
    code: 'submit_failed',
    context: Record<string, string | number | boolean>,
  ) => void
}

export interface ScanPipeline {
  /**
   * @returns la confirmacion a pintar, o `null` si la lectura se ha ignorado por
   *          ser una repeticion inmediata del mismo payload.
   */
  handleDecoded(rawText: string): ScanConfirmation | null
}

export function createScanPipeline(options: ScanPipelineOptions): ScanPipeline {
  const clock = options.clock ?? systemClock
  const roster = options.roster ?? emptyRoster
  const newScanId = options.newScanId ?? (() => uuidV7(clock.now().getTime()))
  const repeatWindowMs = options.repeatWindowMs ?? LOCAL_REPEAT_WINDOW_MS
  const heldGapMs = options.heldGapMs ?? HELD_GAP_MS

  // Memoria compartida entre el camino de tarjeta valida y el de payload
  // ilegible: son excluyentes por definicion (una lectura solo puede ser una
  // cosa u otra), asi que una unica pareja de claves basta para las dos.
  let lastSeenKey: string | null = null
  let lastSeenAtMs = 0
  let lastAcceptedKey: string | null = null
  let lastAcceptedAtMs = 0

  /**
   * @returns `true` si esta lectura hay que ignorarla: bien porque el mismo
   *          payload sigue llegando sin hueco (`HELD_GAP_MS`, tarjeta
   *          sostenida), bien porque, aun estando ausente un rato, sigue
   *          dentro de `LOCAL_REPEAT_WINDOW_MS` del ultimo aceptado.
   */
  function isRepeat(key: string, nowMs: number): boolean {
    const isSamePresentation = lastSeenKey === key && nowMs - lastSeenAtMs < heldGapMs
    lastSeenKey = key
    lastSeenAtMs = nowMs
    if (isSamePresentation) return true

    return lastAcceptedKey === key && nowMs - lastAcceptedAtMs < repeatWindowMs
  }

  function markAccepted(key: string, nowMs: number): void {
    lastAcceptedKey = key
    lastAcceptedAtMs = nowMs
  }

  function settle(confirmation: ScanConfirmation): void {
    options.onSettled?.(confirmation)
  }

  async function dispatch(
    scan: QueuedScan,
    occurredAt: Date,
    fallbackName: string | null,
  ): Promise<void> {
    let result
    try {
      result = await options.submission.submit(scan)
    } catch (error) {
      // Un puerto que incumple su contrato no puede tumbar el quiosco.
      options.onError?.('submit_failed', {
        reason: error instanceof Error ? error.name : 'unknown',
      })
      return
    }

    const confirmation = settleFrom(result, scan.scan_id, occurredAt)
    if (confirmation === null) {
      // Sigue en la cola. La pantalla ya dice «pendiente»: no se toca, porque
      // corregir una confirmacion que era correcta solo confunde. `fallbackName`
      // queda disponible para que la tarea 1.9 lo use al reintentar.
      void fallbackName
      return
    }
    settle(confirmation)
  }

  return {
    handleDecoded(rawText) {
      const occurredAt = clock.now()
      const nowMs = occurredAt.getTime()

      const payload = parseCredentialPayload(rawText)

      if (payload === null) {
        // Rechazo generico y sin causa (regla dura 17). Se aplica la misma
        // logica anti-repeticion: un QR ajeno delante del objetivo no puede
        // hacer sonar el pitido de error cada pocos segundos mientras siga ahi.
        if (isRepeat(rawText, nowMs)) return null
        markAccepted(rawText, nowMs)
        return { kind: 'unreadable', scanId: newScanId(), occurredAt }
      }

      if (isRepeat(payload.raw, nowMs)) return null
      markAccepted(payload.raw, nowMs)

      const scan: QueuedScan = {
        kind: 'qr',
        scan_id: newScanId(),
        qr_payload: payload.raw,
        occurred_at: toUtcIso(occurredAt),
        intent: 'auto',
        device_id: options.deviceId,
      }

      const displayName = roster.displayNameFor(payload.raw)

      // Fuera del camino critico: se lanza y no se espera.
      void dispatch(scan, occurredAt, displayName)

      return { kind: 'pending', scanId: scan.scan_id, occurredAt, displayName }
    },
  }
}
