// Identidad tecnica del dispositivo para telemetria.
//
// OJO CON EL ALCANCE: el `device_id` DEFINITIVO lo emite el servidor al
// emparejar la tablet (`/api/v1/kiosk/pair`), que es otra tarea y otro modulo
// (`features/pairing/`). Lo de aqui es un identificador local, estable entre
// recargas, para que un error reportado antes del emparejamiento —o en una
// tablet que se ha desemparejado— siga siendo atribuible a un aparato concreto.
// Cuando exista el emparejamiento, `resolveDeviceId` leera de el y esto quedara
// como respaldo.
//
// `localStorage` aqui SI es adecuado, y no contradice la prohibicion de usarlo
// para la cola: esto es una preferencia tecnica de 36 bytes que se puede perder
// sin consecuencias. La cola es registro legal sin escribir y va a IndexedDB.

import { uuidV7 } from '@/shared/ids/uuidV7'

const DEVICE_ID_KEY = 'kronoqr.kiosk.device_id'

/**
 * Token de dispositivo emitido al emparejar. **Lo escribe la tarea 1.11**
 * (`features/pairing/`); aqui solo se lee.
 *
 * Se lee y no se inventa: de este token se DERIVA la clave del padron cacheado
 * (RL-12, doc 02 §7.1). Sin token no hay clave, y sin clave no se cachea nada
 * — que es la respuesta correcta, no un problema. Ver `cachedRoster.ts`.
 */
const DEVICE_TOKEN_KEY = 'kronoqr.kiosk.device_token'

/** Version de la PWA. La inyecta Vite desde `package.json` (ver `vite.config.ts`). */
export const APP_VERSION: string = __APP_VERSION__

function safeStorage(): Storage | null {
  try {
    return globalThis.localStorage
  } catch {
    // Modo privado, almacenamiento deshabilitado por politica del dispositivo…
    // Nada de esto puede tumbar el quiosco.
    return null
  }
}

/** `null` mientras la tablet no este emparejada. Nunca viaja en telemetria. */
export function readDeviceToken(): string | null {
  const storage = safeStorage()
  if (storage === null) return null

  try {
    const stored = storage.getItem(DEVICE_TOKEN_KEY)
    return stored === null || stored === '' ? null : stored
  } catch {
    return null
  }
}

export function resolveDeviceId(): string {
  const storage = safeStorage()
  if (storage === null) return 'unpaired'

  try {
    const stored = storage.getItem(DEVICE_ID_KEY)
    if (stored !== null && stored !== '') return stored

    const fresh = uuidV7()
    storage.setItem(DEVICE_ID_KEY, fresh)
    return fresh
  } catch {
    return 'unpaired'
  }
}
