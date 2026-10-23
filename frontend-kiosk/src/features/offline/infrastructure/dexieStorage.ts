// La cola en IndexedDB con Dexie 4 (RF-KI-03).
//
// NUNCA `localStorage`: es sincrono —bloquea el hilo que tiene que pintar la
// confirmacion en 300 ms—, no tiene transacciones y cabe en 5 MB. Una cola de
// fichajes es registro legal sin escribir; necesita las tres cosas.
//
// EL ESQUEMA NACE COMPLETO (v1). `intent` esta desde el principio aunque en la
// Fase 1 valga siempre `'auto'` (ADR-024): subir la version del esquema con la
// cola cargada obliga a migrar peticiones de fichaje pendientes en tablets que
// pueden estar sin red, y eso es exactamente lo que no queremos hacer nunca.
//
// EL PIN (tarea 1.12) NO SUBE LA VERSION DEL ESQUEMA. `kind`, `employee_code` y
// `pin_sealed` no son columnas indexadas: Dexie guarda el objeto completo tal
// cual, y una fila QR escrita por una version anterior de la app simplemente no
// tiene `kind`. `normalizeRecord()` la trata como `'qr'` al leerla: es la unica
// forma sin la que un fichaje encolado antes de esta tarea dejaria de
// reconocerse tras una actualizacion, y eso es exactamente lo que la regla dura
// 19 prohibe.
//
// LA v2 (RN-22, ADR-047) SI SUBE LA VERSION, pero solo para AÑADIR la tabla
// `discarded`: no toca ni indexa nada de `scans`, asi que no migra ningun
// fichaje pendiente. Los descartados viven en la misma base para que mover un
// fichaje de `scans` a `discarded` sea una sola transaccion.
//
// QUE NO SE PERSISTE: EL ESTADO «EN VUELO». Si la tablet se apaga mientras una
// peticion viaja, un elemento marcado como «enviandose» en disco quedaria
// atrapado para siempre. Aqui el arrendamiento vive solo en memoria: tras un
// reinicio todo vuelve a estar pendiente, y reenviar es seguro porque el
// `scan_id` es la clave de idempotencia (regla dura 8). Preferimos un reenvio
// que el servidor deduplica a un fichaje que nadie vuelve a mirar.

import Dexie, { type Table } from 'dexie'
import type {
  DiscardedScanRecord,
  EncryptedRosterRecord,
  QueuedScanRecord,
  QueueStorage,
} from './queueStorage'
import type { ScanIntent } from '@/shared/api/types'
import type { QueueCipher, SealedBlob } from './queueCipher'

export const DATABASE_NAME = 'kronoqr-kiosk'

/**
 * Una fila tal como esta en disco cuando se cifra (A1): lo que gobierna orden y
 * reintento sigue en claro e indexado; lo sensible va en `enc` (ver `queueCipher.ts`).
 * Las filas de antes del cifrado siguen siendo `QueuedScanRecord` en claro hasta que
 * la migracion de arranque las sustituye (misma clave primaria: no queda copia en claro).
 */
interface SealedQueuedRow {
  readonly kind: 'qr' | 'pin'
  readonly scan_id: string
  readonly occurred_at: string
  readonly intent: ScanIntent
  readonly device_id: string
  readonly attempts: number
  readonly next_attempt_at: number
  readonly enqueued_at: number
  readonly enc: SealedBlob
}

interface SealedDiscardedRow {
  readonly kind: 'qr' | 'pin'
  readonly scan_id: string
  readonly occurred_at: string
  readonly http_status: number
  readonly problem_type: string | null
  readonly discarded_at: string
  readonly attempts: number
  readonly next_attempt_at: number
  readonly enc: SealedBlob
}

type StoredQueuedRow = QueuedScanRecord | SealedQueuedRow
type StoredDiscardedRow = DiscardedScanRecord | SealedDiscardedRow

function isSealedQueued(row: StoredQueuedRow): row is SealedQueuedRow {
  return 'enc' in row
}

function isSealedDiscarded(row: StoredDiscardedRow): row is SealedDiscardedRow {
  return 'enc' in row
}

interface KioskDatabase extends Dexie {
  scans: Table<StoredQueuedRow, string>
  roster: Table<EncryptedRosterRecord, string>
  discarded: Table<StoredDiscardedRow, string>
}

export function openKioskDatabase(name: string = DATABASE_NAME): KioskDatabase {
  const db = new Dexie(name) as KioskDatabase
  db.version(1).stores({
    // `&scan_id` = clave primaria unica: encolar dos veces el mismo escaneo no
    // crea dos filas. `occurred_at` indexado porque es el orden de drenaje.
    scans: '&scan_id, occurred_at, next_attempt_at',
    roster: '&id',
  })
  // v2 (RN-22, ADR-047): la lista de fichajes descartados pendientes de aviso. Solo
  // AÑADE una tabla: `scans` y `roster` se heredan tal cual de la v1, asi que una
  // cola cargada en una tablet sin red sobrevive a la actualizacion sin migrar
  // ni una fila (ver el comentario de la cabecera).
  db.version(2).stores({
    discarded: '&scan_id, discarded_at, next_attempt_at',
  })
  return db
}

/** Forma que puede tener de verdad una fila leida de disco, sin la garantia del tipo. */
interface RawQueuedScanRecord {
  readonly kind?: 'qr' | 'pin'
  readonly [key: string]: unknown
}

/**
 * Ver la cabecera: una fila sin `kind` es de antes de la tarea 1.12 y es QR.
 *
 * El tipo estatico de `QueuedScanRecord` dice que `kind` SIEMPRE existe, asi
 * que comprobarlo tal cual hace que TypeScript de por inalcanzable la rama que
 * trata precisamente el dato real de una version anterior a esta — de ahi el
 * escape a `RawQueuedScanRecord`, deliberado y unico sitio del fichero donde
 * se hace.
 */
function normalizeRecord(record: QueuedScanRecord): QueuedScanRecord {
  const raw = record as unknown as RawQueuedScanRecord
  if (raw.kind !== undefined) return record
  return { ...raw, kind: 'qr' } as unknown as QueuedScanRecord
}

/** Lo sensible de una fila de la cola, serializado para cifrarlo. */
function secretOfQueued(record: QueuedScanRecord): string {
  return JSON.stringify(
    record.kind === 'qr'
      ? { qr_payload: record.qr_payload }
      : { employee_code: record.employee_code, pin_sealed: record.pin_sealed },
  )
}

function secretOfDiscarded(entry: DiscardedScanRecord): string {
  return JSON.stringify(
    entry.kind === 'qr' ? { qr_payload: entry.qr_payload } : { employee_code: entry.employee_code },
  )
}

function parseSecret(secret: string): Record<string, unknown> | null {
  try {
    const parsed: unknown = JSON.parse(secret)
    return typeof parsed === 'object' && parsed !== null
      ? (parsed as Record<string, unknown>)
      : null
  } catch {
    return null
  }
}

function text(value: unknown): string | null {
  return typeof value === 'string' ? value : null
}

export interface DexieQueueStorageOptions {
  /** Sin cifrador, las filas se guardan en claro (pruebas, y el camino de regla dura 19). */
  readonly cipher?: QueueCipher
  /** Filas cifradas que no se pudieron abrir (clave perdida): se conservan y se cuentan aqui. */
  readonly onUnreadable?: (count: number) => void
}

export function createDexieQueueStorage(
  db: KioskDatabase,
  storageOptions: DexieQueueStorageOptions = {},
): QueueStorage {
  const cipher = storageOptions.cipher ?? null

  async function sealQueued(record: QueuedScanRecord): Promise<StoredQueuedRow> {
    if (cipher === null) return record
    const enc = await cipher.seal(record.scan_id, secretOfQueued(record))
    // Sin cifrado disponible, en claro: un fichaje perdido es peor (regla dura 19).
    if (enc === null) return record
    return {
      kind: record.kind,
      scan_id: record.scan_id,
      occurred_at: record.occurred_at,
      intent: record.intent,
      device_id: record.device_id,
      attempts: record.attempts,
      next_attempt_at: record.next_attempt_at,
      enqueued_at: record.enqueued_at,
      enc,
    }
  }

  async function sealDiscarded(entry: DiscardedScanRecord): Promise<StoredDiscardedRow> {
    if (cipher === null) return entry
    const enc = await cipher.seal(entry.scan_id, secretOfDiscarded(entry))
    if (enc === null) return entry
    return {
      kind: entry.kind,
      scan_id: entry.scan_id,
      occurred_at: entry.occurred_at,
      http_status: entry.http_status,
      problem_type: entry.problem_type,
      discarded_at: entry.discarded_at,
      attempts: entry.attempts,
      next_attempt_at: entry.next_attempt_at,
      enc,
    }
  }

  /** `null` = cifrada y no se pudo abrir. */
  async function openQueued(row: StoredQueuedRow): Promise<QueuedScanRecord | null> {
    if (!isSealedQueued(row)) return normalizeRecord(row)
    if (cipher === null) return null
    const secret = await cipher.open(row.scan_id, row.enc)
    const fields = secret === null ? null : parseSecret(secret)
    if (fields === null) return null
    const base = {
      scan_id: row.scan_id,
      occurred_at: row.occurred_at,
      intent: row.intent,
      device_id: row.device_id,
      attempts: row.attempts,
      next_attempt_at: row.next_attempt_at,
      enqueued_at: row.enqueued_at,
    }
    if (row.kind === 'pin') {
      const code = text(fields['employee_code'])
      const sealed = text(fields['pin_sealed'])
      return code === null || sealed === null
        ? null
        : { kind: 'pin', employee_code: code, pin_sealed: sealed, ...base }
    }
    const payload = text(fields['qr_payload'])
    return payload === null ? null : { kind: 'qr', qr_payload: payload, ...base }
  }

  async function openDiscarded(row: StoredDiscardedRow): Promise<DiscardedScanRecord | null> {
    if (!isSealedDiscarded(row)) return row
    if (cipher === null) return null
    const secret = await cipher.open(row.scan_id, row.enc)
    const fields = secret === null ? null : parseSecret(secret)
    if (fields === null) return null
    const base = {
      scan_id: row.scan_id,
      occurred_at: row.occurred_at,
      http_status: row.http_status,
      problem_type: row.problem_type,
      discarded_at: row.discarded_at,
      attempts: row.attempts,
      next_attempt_at: row.next_attempt_at,
    }
    if (row.kind === 'pin') {
      const code = text(fields['employee_code'])
      return code === null ? null : { kind: 'pin', employee_code: code, ...base }
    }
    const payload = text(fields['qr_payload'])
    return payload === null ? null : { kind: 'qr', qr_payload: payload, ...base }
  }

  /**
   * Migracion de arranque (A1): las filas en claro de versiones anteriores se cifran y
   * la version en claro se SUSTITUYE (misma clave primaria). El cifrado va fuera de la
   * transaccion de IndexedDB (WebCrypto la cerraria); dentro solo se comprueba que la
   * fila siga siendo la misma y sin cifrar antes de escribir. Una fila que no se puede
   * cifrar se queda como esta: nunca se pierde un fichaje.
   */
  async function migrate(): Promise<void> {
    if (cipher === null) return
    try {
      const clearQueued = (await db.scans.toArray()).filter(
        (row): row is QueuedScanRecord => !isSealedQueued(row),
      )
      const sealedQueued: SealedQueuedRow[] = []
      for (const row of clearQueued) {
        const sealed = await sealQueued(normalizeRecord(row))
        if (isSealedQueued(sealed)) sealedQueued.push(sealed)
      }
      if (sealedQueued.length > 0) {
        await db.transaction('rw', db.scans, async () => {
          for (const sealed of sealedQueued) {
            const current = await db.scans.get(sealed.scan_id)
            if (current !== undefined && !isSealedQueued(current)) await db.scans.put(sealed)
          }
        })
      }

      const clearDiscarded = (await db.discarded.toArray()).filter(
        (row): row is DiscardedScanRecord => !isSealedDiscarded(row),
      )
      const sealedDiscarded: SealedDiscardedRow[] = []
      for (const row of clearDiscarded) {
        const sealed = await sealDiscarded(row)
        if (isSealedDiscarded(sealed)) sealedDiscarded.push(sealed)
      }
      if (sealedDiscarded.length > 0) {
        await db.transaction('rw', db.discarded, async () => {
          for (const sealed of sealedDiscarded) {
            const current = await db.discarded.get(sealed.scan_id)
            if (current !== undefined && !isSealedDiscarded(current)) await db.discarded.put(sealed)
          }
        })
      }
    } catch {
      // Un fallo aqui deja las filas como estaban (en claro, legibles y enviables).
    }
  }

  // Una sola vez por manejador, antes de la primera operacion sobre las filas.
  let migration: Promise<void> | null = null
  function ready(): Promise<void> {
    migration ??= migrate()
    return migration
  }

  return {
    durable: true,

    async add(record) {
      await ready()
      const stored = await sealQueued(record)
      try {
        await db.transaction('rw', db.scans, async () => {
          await db.scans.add(stored)
        })
        return true
      } catch (error) {
        // Clave duplicada: el escaneo ya estaba encolado. No es un fallo.
        if (error instanceof Dexie.ConstraintError) return false
        throw error
      }
    },

    async list(limit) {
      await ready()
      const rows = await db.scans.orderBy('occurred_at').limit(limit).toArray()
      const opened: QueuedScanRecord[] = []
      let unreadable = 0
      for (const row of rows) {
        const record = await openQueued(row)
        if (record === null) unreadable += 1
        else opened.push(record)
      }
      // Una fila cifrada que no se abre NO se borra: se conserva y se cuenta.
      if (unreadable > 0) storageOptions.onUnreadable?.(unreadable)
      return opened
    },

    async count() {
      return db.scans.count()
    },

    async remove(scanIds) {
      if (scanIds.length === 0) return
      await db.transaction('rw', db.scans, async () => {
        await db.scans.bulkDelete([...scanIds])
      })
    },

    async reschedule(schedules) {
      if (schedules.length === 0) return
      await db.transaction('rw', db.scans, async () => {
        for (const schedule of schedules) {
          await db.scans.update(schedule.scan_id, {
            attempts: schedule.attempts,
            next_attempt_at: schedule.next_attempt_at,
          })
        }
      })
    },

    async discard(scanId, entry) {
      await ready()
      const stored = await sealDiscarded(entry)
      // UNA transaccion: el fichaje nunca esta en las dos listas ni en ninguna.
      await db.transaction('rw', db.scans, db.discarded, async () => {
        await db.discarded.put(stored)
        await db.scans.delete(scanId)
      })
    },

    async putDiscarded(entry) {
      await ready()
      const stored = await sealDiscarded(entry)
      await db.transaction('rw', db.discarded, async () => {
        await db.discarded.put(stored)
      })
    },

    async listDiscarded(limit) {
      await ready()
      const rows = await db.discarded.orderBy('discarded_at').limit(limit).toArray()
      const opened: DiscardedScanRecord[] = []
      let unreadable = 0
      for (const row of rows) {
        const entry = await openDiscarded(row)
        if (entry === null) unreadable += 1
        else opened.push(entry)
      }
      if (unreadable > 0) storageOptions.onUnreadable?.(unreadable)
      return opened
    },

    async countDiscarded() {
      return db.discarded.count()
    },

    async removeDiscarded(scanIds) {
      if (scanIds.length === 0) return
      await db.transaction('rw', db.discarded, async () => {
        await db.discarded.bulkDelete([...scanIds])
      })
    },

    async rescheduleDiscarded(schedules) {
      if (schedules.length === 0) return
      await db.transaction('rw', db.discarded, async () => {
        for (const schedule of schedules) {
          await db.discarded.update(schedule.scan_id, {
            attempts: schedule.attempts,
            next_attempt_at: schedule.next_attempt_at,
          })
        }
      })
    },

    async clear() {
      // Desvinculacion (F10 del dictamen de seguridad): la lista de descartados
      // guarda el `qr_payload` hasta que el servidor acusa el aviso, y una tablet que
      // se desvincula no puede conservar credenciales leidas. Se vacia JUNTO con la
      // cola, en la misma transaccion. Fuera de este camino solo `removeDiscarded`
      // (tras el acuse) borra un descartado.
      await db.transaction('rw', db.scans, db.discarded, async () => {
        await db.scans.clear()
        await db.discarded.clear()
      })
    },

    async readRoster() {
      return (await db.roster.get('current')) ?? null
    },

    async writeRoster(record) {
      await db.transaction('rw', db.roster, async () => {
        await db.roster.put(record)
      })
    },

    async clearRoster() {
      await db.transaction('rw', db.roster, async () => {
        await db.roster.clear()
      })
    },

    close() {
      db.close()
    },
  }
}
