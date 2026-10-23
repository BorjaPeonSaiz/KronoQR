// Disco que se lee pero ya no se escribe, mas un respaldo en memoria (V2-KI-02).
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
//    `retryLater`) ya sabe que el borrado no llego (V7-RV-4).
//  - No es duradero (`durable: false`): un reinicio perderia lo de memoria, y la
//    puerta de actualizacion lo trata como cola en memoria.
//
// Si el disco deja de LEERSE tambien, se avisa con `onDiskLost` y desde entonces
// se comporta como la memoria pura de siempre.

import type {
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

function byOccurredAt(left: QueuedScanRecord, right: QueuedScanRecord): number {
  if (left.occurred_at === right.occurred_at) return 0
  return left.occurred_at < right.occurred_at ? -1 : 1
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
      const fromDisk = await readDisk<EncryptedRosterRecord | null>(null, () => disk.readRoster())
      return fromDisk ?? (await memory.readRoster())
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
