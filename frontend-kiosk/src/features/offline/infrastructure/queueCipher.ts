// Cifrado en reposo de la cola de fichajes y de la lista de descartados (doc 05:
// «lo guardado en la tablet va cifrado»).
//
// QUE SE CIFRA. Lo sensible de cada fila: el `qr_payload` (la credencial portadora de
// la tarjeta), el `employee_code` y el `pin_sealed`. Lo que gobierna el orden y el
// reintento (`scan_id`, `occurred_at`, `next_attempt_at`...) queda en claro: Dexie
// lo indexa y la cola no puede ordenar lo que no puede leer sin descifrar cada fila.
//
// POR QUE LA CLAVE NO SE DERIVA DEL TOKEN (como en `rosterCipher.ts`). El padron se
// puede tirar y volver a pedir al reemparejar; la cola NO: son fichajes sin enviar,
// registro legal, y SOBREVIVEN a la desvinculacion (regla dura 19). Con una clave
// derivada del token, un reemparejamiento dejaria ilegible todo lo pendiente. Aqui la
// clave es AES-GCM de 256 bits, generada una vez por instalacion, NO EXTRAIBLE
// (`extractable: false`) y guardada como `CryptoKey` en su propia base de IndexedDB.
//
// QUE PROTEGE Y QUE NO. Protege contra quien lee las filas con una herramienta del
// navegador o desde una copia del perfil sin ejecutar la aplicacion: el texto de las
// tarjetas no esta en claro en ninguna fila. No protege contra quien controla la
// aplicacion en marcha (puede pedir a WebCrypto que descifre), ni garantiza que el
// material de la clave sea inaccesible para quien tenga el disco entero: decir lo
// contrario seria vender humo. Mismo alcance que `rosterCipher.ts`.
//
// REGLA DURA 19. Si no hay WebCrypto, o la clave no se puede crear o guardar, la fila
// se encola EN CLARO como siempre y se avisa (sin datos personales): perder un
// fichaje por no poder cifrarlo seria peor. Una fila cifrada que ya no se puede abrir
// (clave perdida) se CONSERVA en disco, no se borra, y se reporta.
//
// AAD. El `scan_id` va como dato autenticado adicional: una fila cifrada no se puede
// pegar en otra.

import Dexie, { type Table } from 'dexie'

export const QUEUE_KEY_DATABASE = 'kronoqr-kiosk-queue-key'
const IV_BYTES = 12

/**
 * Lo que queda en disco en lugar del texto en claro. En base64 y no como bytes: un
 * texto sobrevive a cualquier clonado de IndexedDB, y la fila queda legible como JSON.
 */
export interface SealedBlob {
  readonly v: 1
  readonly iv: string
  readonly ct: string
}

function toBase64(bytes: Uint8Array): string {
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)
  return btoa(binary)
}

function fromBase64(text: string): Uint8Array<ArrayBuffer> {
  const binary = atob(text)
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index)
  return bytes
}

export interface QueueKeyStore {
  /** `null` = todavia no hay clave. Lanza si el almacen no responde. */
  load(): Promise<CryptoKey | null>
  /**
   * Guarda la clave SOLO si no habia otra (dos manejadores arrancando a la vez no
   * pueden crear dos). Devuelve la que quedo guardada.
   */
  saveIfAbsent(key: CryptoKey): Promise<CryptoKey>
}

export interface QueueCipher {
  /** `null` si no se puede cifrar ahora: quien llama guarda en claro (regla dura 19). */
  seal(scanId: string, plaintext: string): Promise<SealedBlob | null>
  /** `null` si no se puede abrir: la fila se conserva. */
  open(scanId: string, blob: SealedBlob): Promise<string | null>
}

export interface QueueCipherOptions {
  /** `null` = sin WebCrypto (pruebas); ausente = el global. */
  readonly subtle?: SubtleCrypto | null | undefined
  readonly keyStore?: QueueKeyStore
  readonly randomBytes?: ((length: number) => Uint8Array<ArrayBuffer>) | undefined
  /** Una vez por motivo y sesion, sin datos personales. */
  readonly onUnavailable?: (reason: string) => void
}

const encoder = new TextEncoder()
const decoder = new TextDecoder()

interface KeyRow {
  readonly id: 'queue'
  readonly key: CryptoKey
}

interface KeyDatabase extends Dexie {
  keys: Table<KeyRow, string>
}

export function createDexieQueueKeyStore(name: string = QUEUE_KEY_DATABASE): QueueKeyStore {
  let database: KeyDatabase | null = null
  function open(): KeyDatabase {
    if (database === null) {
      const created = new Dexie(name) as KeyDatabase
      created.version(1).stores({ keys: '&id' })
      database = created
    }
    return database
  }

  return {
    async load() {
      const row = await open().keys.get('queue')
      return row?.key ?? null
    },

    async saveIfAbsent(key) {
      const db = open()
      return db.transaction('rw', db.keys, async () => {
        const existing = await db.keys.get('queue')
        if (existing !== undefined) return existing.key
        await db.keys.add({ id: 'queue', key })
        return key
      })
    },
  }
}

export function createQueueCipher(options: QueueCipherOptions = {}): QueueCipher {
  const subtle =
    options.subtle === null ? null : (options.subtle ?? globalThis.crypto?.subtle ?? null)
  const keyStore = options.keyStore ?? createDexieQueueKeyStore()
  const random =
    options.randomBytes ??
    ((length: number) => globalThis.crypto.getRandomValues(new Uint8Array(length)))
  const reported = new Set<string>()
  let keyPromise: Promise<CryptoKey | null> | null = null

  function unavailable(reason: string): null {
    if (!reported.has(reason)) {
      reported.add(reason)
      options.onUnavailable?.(reason)
    }
    return null
  }

  async function resolveKey(): Promise<CryptoKey | null> {
    if (subtle === null) return unavailable('no_webcrypto')
    try {
      const stored = await keyStore.load()
      if (stored !== null) return stored
      const fresh = await subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, [
        'encrypt',
        'decrypt',
      ])
      // Si no se puede GUARDAR, no se usa: lo cifrado con una clave que no sobrevive
      // al reinicio se perderia con ella.
      return await keyStore.saveIfAbsent(fresh)
    } catch {
      return unavailable('key_unavailable')
    }
  }

  function key(): Promise<CryptoKey | null> {
    // Un fallo NO se cachea para siempre: el siguiente intento vuelve a probar.
    keyPromise ??= resolveKey().then((resolved) => {
      if (resolved === null) keyPromise = null
      return resolved
    })
    return keyPromise
  }

  return {
    async seal(scanId, plaintext) {
      const resolved = await key()
      if (resolved === null || subtle === null) return null
      try {
        const iv = random(IV_BYTES)
        const ciphertext = await subtle.encrypt(
          { name: 'AES-GCM', iv, additionalData: encoder.encode(scanId) },
          resolved,
          encoder.encode(plaintext),
        )
        return { v: 1, iv: toBase64(iv), ct: toBase64(new Uint8Array(ciphertext)) }
      } catch {
        return unavailable('encrypt_failed')
      }
    },

    async open(scanId, blob) {
      const resolved = await key()
      if (resolved === null || subtle === null) return null
      try {
        const plaintext = await subtle.decrypt(
          { name: 'AES-GCM', iv: fromBase64(blob.iv), additionalData: encoder.encode(scanId) },
          resolved,
          fromBase64(blob.ct),
        )
        return decoder.decode(plaintext)
      } catch {
        return unavailable('decrypt_failed')
      }
    },
  }
}
