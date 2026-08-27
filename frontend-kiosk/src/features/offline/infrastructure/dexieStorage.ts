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
// QUE NO SE PERSISTE: EL ESTADO «EN VUELO». Si la tablet se apaga mientras una
// peticion viaja, un elemento marcado como «enviandose» en disco quedaria
// atrapado para siempre. Aqui el arrendamiento vive solo en memoria: tras un
// reinicio todo vuelve a estar pendiente, y reenviar es seguro porque el
// `scan_id` es la clave de idempotencia (regla dura 8). Preferimos un reenvio
// que el servidor deduplica a un fichaje que nadie vuelve a mirar.

import Dexie, { type Table } from 'dexie'
import type { EncryptedRosterRecord, QueuedScanRecord, QueueStorage } from './queueStorage'

export const DATABASE_NAME = 'kronoqr-kiosk'

interface KioskDatabase extends Dexie {
  scans: Table<QueuedScanRecord, string>
  roster: Table<EncryptedRosterRecord, string>
}

export function openKioskDatabase(name: string = DATABASE_NAME): KioskDatabase {
  const db = new Dexie(name) as KioskDatabase
  db.version(1).stores({
    // `&scan_id` = clave primaria unica: encolar dos veces el mismo escaneo no
    // crea dos filas. `occurred_at` indexado porque es el orden de drenaje.
    scans: '&scan_id, occurred_at, next_attempt_at',
    roster: '&id',
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

export function createDexieQueueStorage(db: KioskDatabase): QueueStorage {
  return {
    durable: true,

    async add(record) {
      try {
        await db.transaction('rw', db.scans, async () => {
          await db.scans.add(record)
        })
        return true
      } catch (error) {
        // Clave duplicada: el escaneo ya estaba encolado. No es un fallo.
        if (error instanceof Dexie.ConstraintError) return false
        throw error
      }
    },

    async list(limit) {
      const rows = await db.scans.orderBy('occurred_at').limit(limit).toArray()
      return rows.map(normalizeRecord)
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

    async clear() {
      await db.transaction('rw', db.scans, async () => {
        await db.scans.clear()
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
