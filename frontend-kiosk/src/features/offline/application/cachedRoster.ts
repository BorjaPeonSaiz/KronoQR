// El padron cacheado: la mitad del §6 que permite saludar por el nombre sin red.
//
// EL INDICE VIVE EN MEMORIA Y LA COPIA EN DISCO VA CIFRADA. Es la unica forma
// de cumplir las dos cosas a la vez: `displayNameFor` es SINCRONA porque esta
// en el camino de los 300 ms, y RL-12 exige que en reposo el padron este
// cifrado. Se descifra una vez al arrancar y se indexa; a partir de ahi cada
// escaneo es un `sha256` sobre 22 caracteres y una busqueda en un `Map`.
//
// QUE SE BUSCA. El `token_hash` del contrato es el SHA-256 del TOKEN de la
// tarjeta, que es el tercer segmento del payload `FH1.<key_id>.<token>.<sig>`
// (doc 02 §5.1, y §5.2 paso 4: el servidor busca la credencial por el hash del
// token). El quiosco calcula ese mismo hash.
//
// QUE PASA SI NO LO RECONOCE. Nada que impida fichar. Devuelve `null`, la
// pantalla dice «Fichaje registrado · Pendiente de validar» y el escaneo viaja
// igual. Un padron desactualizado —alguien que entro ayer y todavia no esta en
// la copia— no puede dejar a nadie sin fichar (regla dura 19).
//
// PURGA AL DESVINCULAR (doc 01 §8.1). No hace falta que nadie llame a nada: si
// el token del dispositivo desaparece o cambia, la copia no se puede descifrar
// —la clave se deriva de el— y se borra en el arranque siguiente. Tambien se
// purga explicitamente con `purge()` cuando el emparejamiento (tarea 1.11) lo
// pida.

import type { RosterLookupPort } from '@/features/scan/application/ports'
import { parseCredentialPayload } from '@/features/scan/domain/credentialPayload'
import type { ApiClient } from '@/shared/api/client'
import type { KioskRosterEntry } from '@/shared/api/types'
import { sha256Hex } from '@/shared/crypto/sha256'
import type { QueueStorage } from '../infrastructure/queueStorage'
import type { RosterCryptoDeps } from '../infrastructure/rosterCipher'
import { openRoster, sealRoster } from '../infrastructure/rosterCipher'

/** Cada media hora. Una tarjeta recien impresa entra en el padron sin reiniciar nada. */
export const ROSTER_REFRESH_MS = 30 * 60 * 1_000

export type RosterDiagnostic =
  'roster.decrypt_failed' | 'roster.fetch_failed' | 'roster.not_cacheable'

export interface CachedRosterOptions {
  readonly api: ApiClient
  readonly storage: () => QueueStorage
  /** Token del dispositivo. `null` mientras la tablet no esta emparejada. */
  readonly deviceToken: () => string | null
  readonly crypto?: RosterCryptoDeps
  readonly onDiagnostic?: (code: RosterDiagnostic, context: Record<string, string | number>) => void
}

export interface CachedRoster {
  readonly port: RosterLookupPort
  /** Carga el indice desde la copia cifrada. Purga si no se puede abrir. */
  load(): Promise<void>
  /** Pide el padron al servidor y renueva la copia cifrada. */
  refresh(): Promise<boolean>
  purge(): Promise<void>
  /** `generated_at` de la copia en uso, para la pantalla de diagnostico (RF-KI-08). */
  generatedAt(): string | null
  size(): number
  /**
   * Clave publica X25519 con la que el quiosco cierra el PIN antes de encolarlo
   * (RF-AT-11). `null` si la instalacion no ofrece esta via (ADR-017) o si
   * todavia no se ha cargado ningun padron.
   */
  pinSealingPublicKey(): string | null
  /**
   * `true` en cuanto termina el PRIMER `refresh()` contra el servidor, con
   * exito o sin el. Antes de eso, `pinSealingPublicKey() === null` es
   * ambiguo: puede ser que la instalacion no ofrezca PIN, o que simplemente
   * todavia no se haya preguntado al servidor. Despues, ya no lo es: `null`
   * significa, sin duda, «esta instalacion no ofrece fichaje por PIN».
   */
  settled(): boolean
}

export function createCachedRoster(options: CachedRosterOptions): CachedRoster {
  let index = new Map<string, string>()
  let generatedAt: string | null = null
  let pinSealingPublicKey: string | null = null
  let resolved = false

  function reindex(entries: readonly KioskRosterEntry[], stamp: string): void {
    const next = new Map<string, string>()
    for (const entry of entries) next.set(entry.token_hash.toLowerCase(), entry.display_name)
    index = next
    generatedAt = stamp
  }

  async function purge(): Promise<void> {
    index = new Map()
    generatedAt = null
    pinSealingPublicKey = null
    try {
      await options.storage().clearRoster()
    } catch {
      // Si no se puede borrar tampoco se puede leer: el indice ya esta vacio.
    }
  }

  return {
    port: {
      displayNameFor(payload) {
        if (index.size === 0) return null
        const parsed = parseCredentialPayload(payload)
        if (parsed === null) return null
        return index.get(sha256Hex(parsed.token)) ?? null
      },
    },

    async load() {
      const token = options.deviceToken()
      if (token === null || token === '') {
        // Sin token no hay clave, y sin clave no puede haber copia. Si quedaba
        // una de un emparejamiento anterior, fuera.
        await purge()
        return
      }

      let record
      try {
        record = await options.storage().readRoster()
      } catch {
        record = null
      }
      if (record === null) return

      const entries = await openRoster(record, token, options.crypto ?? {})
      if (entries === null) {
        options.onDiagnostic?.('roster.decrypt_failed', { purged: 1 })
        await purge()
        return
      }
      reindex(entries, record.generated_at)
      // La clave viaja en claro (ver `rosterCipher.ts`): se lee directamente del
      // registro, sin depender de que el descifrado del padron haya ido bien.
      pinSealingPublicKey = record.pin_sealing_public_key
    },

    async refresh() {
      // `resolved` se marca pase lo que pase a partir de aqui (exito, fallo o
      // sin token): es lo unico que permite distinguir «no se sabe todavia» de
      // «se sabe y no hay PIN» en `pinSealingPublicKey()`. Un `finally` en vez
      // de repetirlo en cada `return` es lo que garantiza que ningun camino
      // nuevo que se anada aqui pueda olvidarlo.
      try {
        const token = options.deviceToken()
        if (token === null || token === '') return false

        const result = await options.api.fetchRoster()
        if (result.outcome !== 'ok') {
          if (result.outcome === 'failed' && result.cause !== 'offline') {
            options.onDiagnostic?.('roster.fetch_failed', { cause: result.cause })
          }
          return false
        }

        // Se actualiza YA, aunque el sellado para disco falle despues: no es un
        // dato personal (RL-12 no lo alcanza) y el teclado de PIN de esta sesion
        // no tiene por que esperar a que la copia cifrada se pueda escribir.
        pinSealingPublicKey = result.data.pin_sealing_public_key

        const sealed = await sealRoster(
          result.data.entries,
          result.data.generated_at,
          token,
          result.data.pin_sealing_public_key,
          options.crypto ?? {},
        )
        if (sealed === null) {
          // Sin WebCrypto no se cachea NADA: un padron en claro en IndexedDB
          // incumpliria RL-12. El quiosco sigue fichando, sin nombre.
          options.onDiagnostic?.('roster.not_cacheable', { entries: result.data.entries.length })
          return false
        }

        try {
          await options.storage().writeRoster(sealed)
        } catch {
          // La copia no se ha podido guardar; el indice en memoria vale para esta
          // sesion y se volvera a pedir en la siguiente.
        }
        reindex(result.data.entries, result.data.generated_at)
        return true
      } finally {
        resolved = true
      }
    },

    purge,
    generatedAt: () => generatedAt,
    size: () => index.size,
    pinSealingPublicKey: () => pinSealingPublicKey,
    settled: () => resolved,
  }
}
