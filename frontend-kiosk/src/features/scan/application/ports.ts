// Puertos del escaneo.
//
// Existen para que la tarea 1.9 pueda enchufar la cola de Dexie sin tocar ni la
// tuberia ni la interfaz, y para que las pruebas unitarias de esta tarea no
// necesiten IndexedDB ni un servidor.
//
// El reparto es el del diagrama del doc 02 §6:
//   1.8 (esta tarea)  decodificar -> verificar FH1 -> resolver nombre -> ENCOLAR
//                     -> confirmar en < 300 ms
//   1.9               que «encolar» sea IndexedDB transaccional, con reintentos,
//                     lotes ordenados por `occurred_at` e indicador de pendientes

import type { ScanAccepted, ScanDebounced, ScanIntent } from '@/shared/api/types'

/**
 * Lo comun a cualquier fichaje que entra en la cola, sea cual sea su via.
 * `scan_id` nace AL ENCOLAR, no al enviar (regla dura 8); `occurred_at` es el
 * instante real en UTC y no se recalcula al sincronizar (regla dura 9).
 */
interface QueuedScanBase {
  readonly scan_id: string
  readonly occurred_at: string
  /**
   * Nace ya en el registro de la cola (ADR-024). En esta fase el quiosco escribe
   * siempre `'auto'`; declararlo desde la v1 evita migrar una cola cargada de
   * fichajes sin sincronizar en tablets que pueden estar sin red.
   */
  readonly intent: ScanIntent
  readonly device_id: string
}

/** Fichaje por tarjeta QR (tarea 1.8/1.9). */
export interface QueuedQrScan extends QueuedScanBase {
  readonly kind: 'qr'
  readonly qr_payload: string
}

/**
 * Fichaje de respaldo por PIN (RF-AT-11, tarea 1.12). `pin_sealed` es el PIN
 * YA CERRADO con la clave publica de la instalacion (sobre de libsodium): el
 * PIN en claro no existe en ningun objeto que se serialice, ni de camino a la
 * cola ni de camino a la red. Ver `features/pin/infrastructure/pinSealing.ts`.
 */
export interface QueuedPinScan extends QueuedScanBase {
  readonly kind: 'pin'
  readonly employee_code: string
  readonly pin_sealed: string
}

/** Un escaneo listo para viajar. Es tambien la fila de la cola de la tarea 1.9. */
export type QueuedScan = QueuedQrScan | QueuedPinScan

export type ScanSubmissionResult =
  | { readonly kind: 'accepted'; readonly response: ScanAccepted }
  | { readonly kind: 'debounced'; readonly response: ScanDebounced }
  | { readonly kind: 'rejected' }
  /** Sin red o fallo transitorio: sigue en la cola y se reintentara (RF-KI-04). */
  | { readonly kind: 'deferred' }

/**
 * Donde va un escaneo despues de confirmarse en pantalla.
 *
 * Contrato de esta interfaz: **`submit` no puede lanzar y no puede tardar en
 * devolver el control**. La confirmacion ya se ha pintado cuando se llama.
 */
export interface ScanSubmissionPort {
  submit(scan: QueuedScan): Promise<ScanSubmissionResult>
}

/**
 * Resolucion del nombre contra el padron cacheado y cifrado (RF-KI-03, RL-12).
 * La implementacion real es de la tarea 1.9; aqui se consume.
 *
 * Sincrono a proposito: esta en el camino de los 300 ms. Un `await` contra
 * IndexedDB por cada escaneo es exactamente lo que no puede pasar.
 */
export interface RosterLookupPort {
  /** Nombre minimo (nombre de pila e inicial), o `null` si no lo reconoce. */
  displayNameFor(payload: string): string | null
}

/** Padron vacio: el quiosco encola igual y avisa «pendiente de validar» (§6). */
export const emptyRoster: RosterLookupPort = { displayNameFor: () => null }
