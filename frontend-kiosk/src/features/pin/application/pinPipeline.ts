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

import type { QueuedPinScan, ScanSubmissionPort } from '@/features/scan/application/ports'
import type { ScanConfirmation } from '@/features/scan/domain/scanOutcome'
import { settleFrom } from '@/features/scan/application/settleFrom'
import type { Clock } from '@/shared/time/clock'
import { systemClock, toUtcIso } from '@/shared/time/clock'
import { uuidV7 } from '@/shared/ids/uuidV7'
import { sealPin } from '../infrastructure/pinSealing'

export interface PinPipelineOptions {
  readonly submission: ScanSubmissionPort
  readonly deviceId: string
  /** `pin_sealing_public_key` del padron. Nunca `null`: la pantalla no existe si lo es. */
  readonly publicKey: string
  readonly clock?: Clock
  readonly newScanId?: () => string
  readonly seal?: (pin: string, publicKey: string) => Promise<string>
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
   */
  submit(employeeCode: string, pin: string): Promise<ScanConfirmation>
}

export function createPinPipeline(options: PinPipelineOptions): PinPipeline {
  const clock = options.clock ?? systemClock
  const newScanId = options.newScanId ?? (() => uuidV7(clock.now().getTime()))
  const seal = options.seal ?? sealPin

  function settle(confirmation: ScanConfirmation): void {
    options.onSettled?.(confirmation)
  }

  async function dispatch(scan: QueuedPinScan, occurredAt: Date): Promise<void> {
    let result
    try {
      result = await options.submission.submit(scan)
    } catch (error) {
      options.onError?.('submit_failed', {
        reason: error instanceof Error ? error.name : 'unknown',
      })
      return
    }

    const confirmation = settleFrom(result, scan.scan_id, occurredAt)
    if (confirmation === null) {
      // Sigue en la cola, sellado. La pantalla ya dice «pendiente».
      return
    }
    settle(confirmation)
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

      // Fuera del camino critico: se lanza y no se espera.
      void dispatch(scan, occurredAt)

      // Ni entrada ni salida todavia: como en el QR, eso lo decide el servidor.
      return { kind: 'pending', scanId, occurredAt, displayName: null }
    },
  }
}
