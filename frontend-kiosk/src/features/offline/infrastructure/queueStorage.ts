// El puerto de persistencia de la cola, y su respaldo en memoria.
//
// La implementacion de verdad es Dexie sobre IndexedDB (`dexieStorage.ts`).
// Esta interfaz existe por dos motivos, y ninguno es «por si cambiamos de base
// de datos»:
//
//  1. Que la logica de la cola —orden, reintentos, borrado solo tras
//     confirmacion— se pueda probar sin navegador.
//  2. Que una tablet con IndexedDB inutilizable (modo privado, almacenamiento
//     lleno, politica del dispositivo) SIGA FICHANDO. Sin respaldo, un fallo al
//     abrir la base de datos convertiria la regla dura 19 en una intencion.
//     El respaldo pierde la cola si se reinicia la tablet, y por eso se avisa;
//     pero perderla al reiniciar es infinitamente mejor que no dejar fichar.

import type { ScanIntent } from '@/shared/api/types'

/**
 * Una fila de la cola. **`intent` esta desde la v1 del esquema** (ADR-024):
 * cambiar el esquema de IndexedDB con la cola cargada obliga a migrar fichajes
 * pendientes —registro legal sin escribir— en tablets que pueden estar sin red.
 * En esta fase el quiosco escribe siempre `'auto'`.
 */
export interface QueuedScanRecord {
  readonly scan_id: string
  readonly qr_payload: string
  readonly occurred_at: string
  readonly intent: ScanIntent
  readonly device_id: string
  /** Envios fallidos. Gobierna el retroceso exponencial. */
  readonly attempts: number
  /** Epoch ms a partir del cual vuelve a ser elegible. `0` = ahora mismo. */
  readonly next_attempt_at: number
  /** Epoch ms del encolado. Solo diagnostico: el registro legal usa `occurred_at`. */
  readonly enqueued_at: number
}

export interface RetrySchedule {
  readonly scan_id: string
  readonly attempts: number
  readonly next_attempt_at: number
}

/** Padron cifrado en reposo (RL-12). Fuera del sobre solo va la fecha. */
export interface EncryptedRosterRecord {
  readonly id: 'current'
  // `Uint8Array<ArrayBuffer>` y no `Uint8Array` a secas: desde TypeScript 5.7
  // el tipo es generico sobre el buffer, y `BufferSource` —lo que aceptan
  // `subtle.encrypt` y `subtle.decrypt`— excluye `SharedArrayBuffer`. Fijarlo
  // aqui evita una conversion forzada en cada llamada a WebCrypto.
  readonly salt: Uint8Array<ArrayBuffer>
  readonly iv: Uint8Array<ArrayBuffer>
  readonly ciphertext: Uint8Array<ArrayBuffer>
  /** `KioskRoster.generated_at`, en claro para la pantalla de diagnostico (RF-KI-08). */
  readonly generated_at: string
}

export interface QueueStorage {
  /** `false` si ya estaba (mismo `scan_id`): encolar dos veces no duplica. */
  add(record: QueuedScanRecord): Promise<boolean>
  /** Hasta `limit` filas, ordenadas por `occurred_at`. */
  list(limit: number): Promise<QueuedScanRecord[]>
  count(): Promise<number>
  /** Borrado transaccional. Solo se llama tras confirmacion explicita del servidor. */
  remove(scanIds: readonly string[]): Promise<void>
  reschedule(schedules: readonly RetrySchedule[]): Promise<void>
  clear(): Promise<void>
  readRoster(): Promise<EncryptedRosterRecord | null>
  writeRoster(record: EncryptedRosterRecord): Promise<void>
  clearRoster(): Promise<void>
  /** `true` si sobrevive a un reinicio de la tablet. */
  readonly durable: boolean
  close(): void
}

/**
 * Respaldo en memoria. Ordena y trocea igual que la real para que el
 * comportamiento observable no dependa de si IndexedDB esta disponible.
 */
export function createMemoryQueueStorage(): QueueStorage {
  const rows = new Map<string, QueuedScanRecord>()
  let roster: EncryptedRosterRecord | null = null

  return {
    durable: false,

    async add(record) {
      if (rows.has(record.scan_id)) return false
      rows.set(record.scan_id, record)
      return true
    },

    async list(limit) {
      return [...rows.values()]
        .sort((left, right) => (left.occurred_at < right.occurred_at ? -1 : 1))
        .slice(0, limit)
    },

    async count() {
      return rows.size
    },

    async remove(scanIds) {
      for (const scanId of scanIds) rows.delete(scanId)
    },

    async reschedule(schedules) {
      for (const schedule of schedules) {
        const current = rows.get(schedule.scan_id)
        if (current === undefined) continue
        rows.set(schedule.scan_id, {
          ...current,
          attempts: schedule.attempts,
          next_attempt_at: schedule.next_attempt_at,
        })
      }
    },

    async clear() {
      rows.clear()
    },

    async readRoster() {
      return roster
    },

    async writeRoster(record) {
      roster = record
    },

    async clearRoster() {
      roster = null
    },

    close() {
      // No hay nada que soltar.
    },
  }
}
