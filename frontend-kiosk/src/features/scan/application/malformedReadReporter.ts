// Cuenta las lecturas que no son una tarjeta KronoQR y las reporta con freno
// (R3-KI-04).
//
// Un QR ajeno repetido o una tarjeta dañada no dejaba rastro tecnico: el lector
// descartaba lo ilegible en silencio. Ahora cada lectura ilegible cuenta, pero
// el informe sale como mucho una vez por ventana: una persona que enseña varios
// QR ajenos seguidos no puede llenar el buffer de errores (50) y desplazar
// errores de verdad. Lo suprimido se suma al siguiente informe (`count`).
//
// NUNCA lleva el contenido leido: puede ser la URL del wifi, un albaran o la
// tarjeta de otra instalacion (regla dura 17, regla dura 21). Solo su longitud.

import type { Clock } from '@/shared/time/clock'
import { systemClock } from '@/shared/time/clock'

/** Como mucho un informe por minuto. */
export const MALFORMED_REPORT_WINDOW_MS = 60_000

export interface MalformedReadContext {
  readonly length: number
  /** Lecturas ilegibles desde el informe anterior, esta incluida. */
  readonly count: number
  readonly message: 'malformed_payload'
}

export function createMalformedReadReporter(
  report: (context: MalformedReadContext) => void,
  options: { readonly clock?: Clock; readonly windowMs?: number } = {},
): (length: number) => void {
  const clock = options.clock ?? systemClock
  const windowMs = options.windowMs ?? MALFORMED_REPORT_WINDOW_MS
  let lastReportAt: number | null = null
  let counted = 0

  return (length) => {
    counted += 1
    const nowMs = clock.now().getTime()
    if (lastReportAt !== null && nowMs - lastReportAt < windowMs) return
    lastReportAt = nowMs
    const count = counted
    counted = 0
    report({ length, count, message: 'malformed_payload' })
  }
}
