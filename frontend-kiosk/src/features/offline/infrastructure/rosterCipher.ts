// Cifrado del padron cacheado (RL-12, doc 02 §7.1 capa Cliente).
//
// QUE SE GUARDA. Lo minimo del §7.3 y ni un campo mas: hash del token de la
// tarjeta y nombre de pila con inicial. Ni codigo de empleado, ni departamento,
// ni la plantilla completa. Cada campo de mas es un campo que se filtra entero
// si alguien se lleva la tablet de la pared.
//
// COMO SE CIFRA. AES-256-GCM con clave DERIVADA DEL TOKEN DEL DISPOSITIVO por
// HKDF-SHA256. La clave no se guarda en ningun sitio: se vuelve a derivar del
// token en cada arranque.
//
// QUE PROTEGE Y QUE NO. Protege contra quien se lleva la tablet y extrae el
// perfil del navegador **sin el token del dispositivo** —el token vive en su
// propio almacen y se revoca desde el panel—, y contra que el padron quede en
// claro en una copia de seguridad del aparato. No protege contra quien controla
// la aplicacion en marcha: eso no lo protege ningun cifrado del lado del
// cliente, y decir lo contrario seria vender humo.
//
// SIN TOKEN NO HAY CACHE. Si el dispositivo no esta emparejado no hay clave, y
// entonces **no se guarda nada**: el quiosco funciona con el padron vacio y
// confirma «pendiente de validar», que es la degradacion honesta del §6.
// Guardar el padron cifrado con una clave inventada y almacenada al lado seria
// cumplir RL-12 en la forma y no en el fondo.

import type { KioskRosterEntry } from '@/shared/api/types'
import type { EncryptedRosterRecord } from './queueStorage'

const HKDF_INFO = 'kronoqr.kiosk.roster.v1'
const SALT_BYTES = 16
const IV_BYTES = 12

export interface RosterCryptoDeps {
  /** `null` = no hay WebCrypto (se comprueba en pruebas); ausente = usar el global. */
  readonly subtle?: SubtleCrypto | null | undefined
  readonly randomBytes?: ((length: number) => Uint8Array<ArrayBuffer>) | undefined
}

/** Forma compacta en claro dentro del sobre: `h` = token_hash, `n` = display_name. */
interface SealedEntry {
  readonly h: string
  readonly n: string
}

const encoder = new TextEncoder()
const decoder = new TextDecoder()

function resolveSubtle(deps: RosterCryptoDeps): SubtleCrypto | null {
  if (deps.subtle === null) return null
  return deps.subtle ?? globalThis.crypto?.subtle ?? null
}

function resolveRandom(deps: RosterCryptoDeps): (length: number) => Uint8Array<ArrayBuffer> {
  return (
    deps.randomBytes ??
    ((length: number) => globalThis.crypto.getRandomValues(new Uint8Array(length)))
  )
}

async function deriveKey(
  subtle: SubtleCrypto,
  deviceToken: string,
  salt: Uint8Array<ArrayBuffer>,
): Promise<CryptoKey> {
  const material = await subtle.importKey('raw', encoder.encode(deviceToken), 'HKDF', false, [
    'deriveKey',
  ])
  return subtle.deriveKey(
    { name: 'HKDF', hash: 'SHA-256', salt, info: encoder.encode(HKDF_INFO) },
    material,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt', 'decrypt'],
  )
}

function isSealedEntry(value: unknown): value is SealedEntry {
  if (typeof value !== 'object' || value === null) return false
  const candidate = value as Record<string, unknown>
  return typeof candidate['h'] === 'string' && typeof candidate['n'] === 'string'
}

/**
 * Cifra el padron. Devuelve `null` si el navegador no expone WebCrypto: sin
 * cifrado NO se cachea, porque un padron en claro en IndexedDB incumple RL-12.
 */
export async function sealRoster(
  entries: readonly KioskRosterEntry[],
  generatedAt: string,
  deviceToken: string,
  deps: RosterCryptoDeps = {},
): Promise<EncryptedRosterRecord | null> {
  const subtle = resolveSubtle(deps)
  if (subtle === null || deviceToken === '') return null

  const random = resolveRandom(deps)
  const salt = random(SALT_BYTES)
  const iv = random(IV_BYTES)
  const key = await deriveKey(subtle, deviceToken, salt)

  const sealed: SealedEntry[] = entries.map((entry) => ({
    h: entry.token_hash,
    n: entry.display_name,
  }))
  const plaintext = encoder.encode(JSON.stringify(sealed))

  const ciphertext = await subtle.encrypt({ name: 'AES-GCM', iv }, key, plaintext)

  return {
    id: 'current',
    salt,
    iv,
    ciphertext: new Uint8Array(ciphertext),
    generated_at: generatedAt,
  }
}

/**
 * Descifra el padron. Devuelve `null` si no se puede — token distinto (la
 * tablet se ha reemparejado), sobre manipulado o WebCrypto ausente. Quien
 * llama debe PURGAR la cache en ese caso: un padron que no se puede abrir con
 * el token actual no es de este dispositivo.
 */
export async function openRoster(
  record: EncryptedRosterRecord,
  deviceToken: string,
  deps: RosterCryptoDeps = {},
): Promise<KioskRosterEntry[] | null> {
  const subtle = resolveSubtle(deps)
  if (subtle === null || deviceToken === '') return null

  try {
    const key = await deriveKey(subtle, deviceToken, record.salt)
    const plaintext = await subtle.decrypt(
      { name: 'AES-GCM', iv: record.iv },
      key,
      record.ciphertext,
    )
    const parsed: unknown = JSON.parse(decoder.decode(plaintext))
    if (!Array.isArray(parsed)) return null

    const entries: KioskRosterEntry[] = []
    for (const item of parsed) {
      if (!isSealedEntry(item)) continue
      entries.push({ token_hash: item.h, display_name: item.n })
    }
    return entries
  } catch {
    return null
  }
}
