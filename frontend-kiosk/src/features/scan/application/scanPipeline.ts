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
 * Ventana en la que un MISMO payload leido de nuevo se ignora sin producir nada.
 *
 * No es el anti-rebote de RF-AT-06, que es del servidor y tiene consecuencias
 * de negocio. Esto es puramente optico: la camara decodifica varias veces por
 * segundo y la tarjeta sigue delante del objetivo mientras la persona la retira.
 * Sin esta ventana, un solo gesto generaria diez `scan_id` distintos, diez filas
 * en la cola y diez peticiones — y el servidor las contestaria todas con
 * `debounced`, que es ruido con coste.
 */
export const LOCAL_REPEAT_WINDOW_MS = 2_500

export interface ScanPipelineOptions {
  readonly submission: ScanSubmissionPort
  readonly deviceId: string
  readonly roster?: RosterLookupPort
  readonly clock?: Clock
  readonly newScanId?: () => string
  readonly repeatWindowMs?: number
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

  let lastPayload: string | null = null
  let lastPayloadAtMs = 0

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
        // ventana anti-repeticion: un QR ajeno delante del objetivo no puede
        // hacer sonar el pitido de error diez veces por segundo.
        if (lastPayload === rawText && nowMs - lastPayloadAtMs < repeatWindowMs) return null
        lastPayload = rawText
        lastPayloadAtMs = nowMs
        return { kind: 'unreadable', scanId: newScanId(), occurredAt }
      }

      if (lastPayload === payload.raw && nowMs - lastPayloadAtMs < repeatWindowMs) return null
      lastPayload = payload.raw
      lastPayloadAtMs = nowMs

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
