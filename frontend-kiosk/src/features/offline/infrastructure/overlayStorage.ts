// Disco que se lee pero ya no se escribe, mas un respaldo en memoria.
//
// EL CASO. IndexedDB sigue abriendose y respondiendo a lecturas, pero cada
// escritura falla —cuota agotada, disco lleno— tras las reaperturas de ADR-047.
// Antes la cola caia a memoria PURA y daba el disco por perdido: `claim()`
// devolvia `[]` (RN-21: lo de memoria no puede adelantar a filas que no se ven)
// y las filas que YA estaban en disco no se enviaban hasta recargar la pagina. Se
// quedaba sin salida justo lo que la liberaria: enviar y borrar.
//
// QUE HACE. Un unico almacen sobre dos: el disco (que se lee, y del que se borra)
// y la memoria (donde entra lo que el disco no admite). Es el mismo puerto
// `QueueStorage`, asi que `scanQueue` no cambia su logica:
//
//  - `list`/`count`/`listDiscarded` ven LAS DOS mitades, ordenadas por
//    `occurred_at`: el orden del drenaje (RN-21) se mantiene entero, y el
//    recuento es un hecho (el disco SE VE), no un «desconocido».
//  - `add` prueba primero el disco: en cuanto el drenaje libera sitio, los
//    fichajes nuevos vuelven a ser duraderos. Si falla, entra en memoria.
//  - `remove`/`reschedule`/`discard`... van a la mitad donde vive la fila. Si la
//    escritura en disco falla, la excepcion SUBE: quien llama (`confirm`,
//    `retryLater`) ya sabe que el borrado no llego.
//  - No es duradero (`durable: false`): un reinicio perderia lo de memoria, y la
//    puerta de actualizacion lo trata como cola en memoria.
//
// Si el disco deja de LEERSE tambien, se avisa con `onDiskLost` y desde entonces
// se comporta como la memoria pura de siempre.

import type {
  DiscardedScanMeta,
  FetchedDiscarded,
  FetchedScans,
  QueuedScanMeta,
  DiscardedScanRecord,
  EncryptedRosterRecord,
  QueuedScanRecord,
  QueueStorage,
  RetrySchedule,
} from './queueStorage'

/**
 * La escritura en el DISCO fallo (no la de memoria). `scanQueue` lo distingue para no
 * declarar «sin almacenamiento» una cola cuya memoria funciona perfectamente.
 */
export class OverlayDiskWriteError extends Error {
  constructor(readonly original: unknown) {
    super('overlay_disk_write_failed')
    this.name = 'OverlayDiskWriteError'
  }
}

export interface OverlayQueueStorage extends QueueStorage {
  /** `true` si la ultima escritura en el disco funciono (se puede volver a el). */
  diskWritable(): boolean
  /** La mitad que vive en memoria: lo que un reinicio perderia y hay que migrar al volver el disco. */
  readonly memoryPart: QueueStorage
  /** El disco que sigue leyendose. */
  readonly diskPart: QueueStorage
}

export interface OverlayOptions {
  /** El disco ha dejado de leerse tambien: ya solo queda la memoria. */
  readonly onDiskLost?: (reason: unknown) => void
}

function byOccurredAt(left: { occurred_at: string }, right: { occurred_at: string }): number {
  if (left.occurred_at === right.occurred_at) return 0
  return left.occurred_at < right.occurred_at ? -1 : 1
}

/**
 * Metadatos y filas por id de un almacen, con el rodeo por `list()` si el almacen no
 * los ofrece (dobles de prueba). Los reutiliza `scanQueue.ts`.
 */
export async function metaOf(target: QueueStorage, limit: number): Promise<QueuedScanMeta[]> {
  if (target.listMeta !== undefined) return target.listMeta(limit)
  return (await target.list(limit)).map((row) => ({
    scan_id: row.scan_id,
    kind: row.kind,
    occurred_at: row.occurred_at,
    attempts: row.attempts,
    next_attempt_at: row.next_attempt_at,
  }))
}

export async function fetchMany(
  target: QueueStorage,
  scanIds: readonly string[],
): Promise<FetchedScans> {
  if (scanIds.length === 0) return { records: [], unreadable: [] }
  if (target.getMany !== undefined) return target.getMany(scanIds)
  const wanted = new Set(scanIds)
  const rows = await target.list(Number.MAX_SAFE_INTEGER)
  return { records: rows.filter((row) => wanted.has(row.scan_id)), unreadable: [] }
}

export async function discardedMetaOf(
  target: QueueStorage,
  limit: number,
): Promise<DiscardedScanMeta[]> {
  if (target.listDiscardedMeta !== undefined) return target.listDiscardedMeta(limit)
  return (await target.listDiscarded(limit)).map((entry) => ({
    scan_id: entry.scan_id,
    discarded_at: entry.discarded_at,
    attempts: entry.attempts,
    next_attempt_at: entry.next_attempt_at,
  }))
}

export async function fetchDiscarded(
  target: QueueStorage,
  scanIds: readonly string[],
): Promise<FetchedDiscarded> {
  if (scanIds.length === 0) return { records: [], unreadable: [] }
  if (target.getDiscardedMany !== undefined) return target.getDiscardedMany(scanIds)
  const wanted = new Set(scanIds)
  const rows = await target.listDiscarded(Number.MAX_SAFE_INTEGER)
  return { records: rows.filter((row) => wanted.has(row.scan_id)), unreadable: [] }
}

export function createOverlayQueueStorage(
  disk: QueueStorage,
  memory: QueueStorage,
  options: OverlayOptions = {},
): OverlayQueueStorage {
  let diskLost = false
  let writable = false

  /** Escritura que DEBE llegar al disco: si falla sube envuelta (ver `OverlayDiskWriteError`). */
  async function writeDisk(write: () => Promise<void>): Promise<void> {
    try {
      await write()
      writable = true
    } catch (error) {
      writable = false
      throw new OverlayDiskWriteError(error)
    }
  }

  /** Lectura del disco: si falla, el disco se da por perdido y se sigue con la memoria. */
  async function readDisk<T>(fallback: T, read: () => Promise<T>): Promise<T> {
    if (diskLost) return fallback
    try {
      return await read()
    } catch (error) {
      diskLost = true
      options.onDiskLost?.(error)
      return fallback
    }
  }

  async function memoryIds(): Promise<Set<string>> {
    const rows = await memory.list(Number.MAX_SAFE_INTEGER)
    return new Set(rows.map((row) => row.scan_id))
  }

  async function discardedMemoryIds(): Promise<Set<string>> {
    const rows = await memory.listDiscarded(Number.MAX_SAFE_INTEGER)
    return new Set(rows.map((row) => row.scan_id))
  }

  return {
    durable: false,
    memoryPart: memory,
    diskPart: disk,
    diskWritable: () => writable && !diskLost,

    async add(record) {
      if (!diskLost) {
        try {
          const added = await disk.add(record)
          writable = true
          return added
        } catch {
          // Sin sitio todavia: entra en memoria. El fichaje entra siempre.
          writable = false
        }
      }
      return memory.add(record)
    },

    async list(limit) {
      const [fromDisk, fromMemory] = await Promise.all([
        readDisk<QueuedScanRecord[]>([], () => disk.list(limit)),
        memory.list(limit),
      ])
      const seen = new Set(fromDisk.map((row) => row.scan_id))
      return [...fromDisk, ...fromMemory.filter((row) => !seen.has(row.scan_id))]
        .sort(byOccurredAt)
        .slice(0, limit)
    },

    async count() {
      const [fromDisk, fromMemory] = await Promise.all([
        readDisk(0, () => disk.count()),
        memory.count(),
      ])
      return fromDisk + fromMemory
    },

    async listMeta(limit) {
      const [fromDisk, fromMemory] = await Promise.all([
        readDisk<QueuedScanMeta[]>([], () => metaOf(disk, limit)),
        metaOf(memory, limit),
      ])
      const seen = new Set(fromDisk.map((row) => row.scan_id))
      return [...fromDisk, ...fromMemory.filter((row) => !seen.has(row.scan_id))]
        .sort(byOccurredAt)
        .slice(0, limit)
    },

    async getMany(scanIds) {
      const inMemory = await memoryIds()
      const mine = scanIds.filter((id) => inMemory.has(id))
      const onDisk = scanIds.filter((id) => !inMemory.has(id))
      const fromMemory = await fetchMany(memory, mine)
      const fromDisk = await readDisk<FetchedScans>({ records: [], unreadable: [] }, () =>
        fetchMany(disk, onDisk),
      )
      const byId = new Map(
        [...fromMemory.records, ...fromDisk.records].map((row) => [row.scan_id, row]),
      )
      return {
        records: scanIds.flatMap((id) => {
          const row = byId.get(id)
          return row === undefined ? [] : [row]
        }),
        unreadable: [...fromMemory.unreadable, ...fromDisk.unreadable],
      }
    },

    async listDiscardedMeta(limit) {
      const [fromDisk, fromMemory] = await Promise.all([
        readDisk<DiscardedScanMeta[]>([], () => discardedMetaOf(disk, limit)),
        discardedMetaOf(memory, limit),
      ])
      const seen = new Set(fromDisk.map((entry) => entry.scan_id))
      return [...fromDisk, ...fromMemory.filter((entry) => !seen.has(entry.scan_id))]
        .sort((left, right) => (left.discarded_at < right.discarded_at ? -1 : 1))
        .slice(0, limit)
    },

    async getDiscardedMany(scanIds) {
      const inMemory = await discardedMemoryIds()
      const mine = scanIds.filter((id) => inMemory.has(id))
      const onDisk = scanIds.filter((id) => !inMemory.has(id))
      const fromMemory = await fetchDiscarded(memory, mine)
      const fromDisk = await readDisk<FetchedDiscarded>({ records: [], unreadable: [] }, () =>
        fetchDiscarded(disk, onDisk),
      )
      const byId = new Map(
        [...fromMemory.records, ...fromDisk.records].map((row) => [row.scan_id, row]),
      )
      return {
        records: scanIds.flatMap((id) => {
          const row = byId.get(id)
          return row === undefined ? [] : [row]
        }),
        unreadable: [...fromMemory.unreadable, ...fromDisk.unreadable],
      }
    },

    async remove(scanIds) {
      if (scanIds.length === 0) return
      const inMemory = await memoryIds()
      const mine = scanIds.filter((id) => inMemory.has(id))
      const onDisk = scanIds.filter((id) => !inMemory.has(id))
      if (mine.length > 0) await memory.remove(mine)
      if (onDisk.length > 0 && !diskLost) await writeDisk(() => disk.remove(onDisk))
    },

    async reschedule(schedules: readonly RetrySchedule[]) {
      if (schedules.length === 0) return
      const inMemory = await memoryIds()
      const mine = schedules.filter((schedule) => inMemory.has(schedule.scan_id))
      const onDisk = schedules.filter((schedule) => !inMemory.has(schedule.scan_id))
      if (mine.length > 0) await memory.reschedule(mine)
      if (onDisk.length > 0 && !diskLost) await writeDisk(() => disk.reschedule(onDisk))
    },

    async discard(scanId, entry) {
      // Si la fila vive en memoria, el movimiento es atomico alli. Si vive en el
      // disco, se intenta alli: si no admite escritura, la excepcion sube y el
      // fichaje sigue donde estaba (nunca en las dos listas ni en ninguna).
      if ((await memoryIds()).has(scanId) || diskLost) {
        await memory.discard(scanId, entry)
        return
      }
      await writeDisk(() => disk.discard(scanId, entry))
    },

    async putDiscarded(entry) {
      if (!diskLost) {
        try {
          await disk.putDiscarded(entry)
          writable = true
          return
        } catch {
          // Sin sitio: a memoria.
          writable = false
        }
      }
      await memory.putDiscarded(entry)
    },

    async listDiscarded(limit) {
      const [fromDisk, fromMemory] = await Promise.all([
        readDisk<DiscardedScanRecord[]>([], () => disk.listDiscarded(limit)),
        memory.listDiscarded(limit),
      ])
      const seen = new Set(fromDisk.map((entry) => entry.scan_id))
      return [...fromDisk, ...fromMemory.filter((entry) => !seen.has(entry.scan_id))]
        .sort((left, right) => (left.discarded_at < right.discarded_at ? -1 : 1))
        .slice(0, limit)
    },

    async countDiscarded() {
      const [fromDisk, fromMemory] = await Promise.all([
        readDisk(0, () => disk.countDiscarded()),
        memory.countDiscarded(),
      ])
      return fromDisk + fromMemory
    },

    async removeDiscarded(scanIds) {
      if (scanIds.length === 0) return
      const inMemory = await discardedMemoryIds()
      const mine = scanIds.filter((id) => inMemory.has(id))
      const onDisk = scanIds.filter((id) => !inMemory.has(id))
      if (mine.length > 0) await memory.removeDiscarded(mine)
      if (onDisk.length > 0 && !diskLost) await writeDisk(() => disk.removeDiscarded(onDisk))
    },

    async rescheduleDiscarded(schedules: readonly RetrySchedule[]) {
      if (schedules.length === 0) return
      const inMemory = await discardedMemoryIds()
      const mine = schedules.filter((schedule) => inMemory.has(schedule.scan_id))
      const onDisk = schedules.filter((schedule) => !inMemory.has(schedule.scan_id))
      if (mine.length > 0) await memory.rescheduleDiscarded(mine)
      if (onDisk.length > 0 && !diskLost) await writeDisk(() => disk.rescheduleDiscarded(onDisk))
    },

    async clear() {
      // Desvinculacion: las dos mitades. Lo que falle en el disco sube.
      await memory.clear()
      if (!diskLost) await writeDisk(() => disk.clear())
    },

    async readRoster(): Promise<EncryptedRosterRecord | null> {
      // La memoria primero: solo tiene algo si el disco RECHAZO la ultima escritura, y
      // entonces es la copia mas nueva. Leer antes el disco devolveria un padron viejo
      // (o uno cifrado con el token anterior tras un relevo).
      const fromMemory = await memory.readRoster()
      if (fromMemory !== null) return fromMemory
      return readDisk<EncryptedRosterRecord | null>(null, () => disk.readRoster())
    },

    async writeRoster(record) {
      if (!diskLost) {
        try {
          await disk.writeRoster(record)
          await memory.clearRoster()
          return
        } catch {
          // Sin sitio: el padron vive en memoria hasta que el disco admita escrituras.
        }
      }
      await memory.writeRoster(record)
    },

    async clearRoster() {
      await memory.clearRoster()
      if (!diskLost) await disk.clearRoster()
    },

    close() {
      for (const part of [memory, disk]) {
        try {
          part.close()
        } catch {
          // Ya estaba cerrado.
        }
      }
    },
  }
}
