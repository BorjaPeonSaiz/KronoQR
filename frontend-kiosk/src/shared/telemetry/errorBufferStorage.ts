// Donde sobreviven los errores pendientes de enviar (R3-KI-03).
//
// POR QUE. El buffer de `errorReporter.ts` vive en memoria y sale con el latido
// (cada 60 s). Si la tablet se reinicia o la pagina se recarga entre medias se
// pierde justo lo que mas interesa: lo que precede a un cuelgue o a una recarga
// forzada. Con esto, lo pendiente se recupera al arrancar y sale en el primer
// latido.
//
// UNA BASE PROPIA, NO LA DE LA COLA. La cola de fichajes es registro legal sin
// escribir y su esquema no se toca por un buffer de diagnostico: una migracion
// fallida aqui no puede alcanzarla. Son unos pocos KB (maximo 50 eventos) en una
// sola fila.
//
// PRIVACIDAD. Lo que se guarda es lo que ya paso por `sanitizeContext`: codigo,
// version, `device_id` y contexto tecnico. Nada de nombres ni de tarjetas (regla
// dura 21). Se vacia al confirmarlo el servidor (`acknowledge`).

import Dexie, { type Table } from 'dexie'
import type { ClientErrorEvent } from './errorReporter'

export const ERROR_BUFFER_DATABASE = 'kronoqr-kiosk-errors'

export interface ErrorBufferStorage {
  load(): Promise<readonly ClientErrorEvent[]>
  save(events: readonly ClientErrorEvent[]): Promise<void>
}

interface BufferRow {
  readonly id: 'current'
  readonly events: readonly ClientErrorEvent[]
}

interface ErrorBufferDatabase extends Dexie {
  buffer: Table<BufferRow, string>
}

export function createDexieErrorBufferStorage(
  name: string = ERROR_BUFFER_DATABASE,
): ErrorBufferStorage {
  let database: ErrorBufferDatabase | null = null

  function open(): ErrorBufferDatabase {
    if (database === null) {
      const created = new Dexie(name) as ErrorBufferDatabase
      created.version(1).stores({ buffer: '&id' })
      database = created
    }
    return database
  }

  return {
    async load() {
      const row = await open().buffer.get('current')
      return row?.events ?? []
    },

    async save(events) {
      const db = open()
      await db.transaction('rw', db.buffer, async () => {
        if (events.length === 0) await db.buffer.delete('current')
        else await db.buffer.put({ id: 'current', events: [...events] })
      })
    },
  }
}
