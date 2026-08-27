// UUID v7 (RFC 9562) generado en el cliente.
//
// Es la clave de idempotencia de todo fichaje (regla dura 8, RF-AT-07): el
// mismo `scan_id` viaja como `Idempotency-Key` en el envio original y en cada
// reintento, y por eso **nace al encolar, no al enviar**.
//
// v7 y no v4 por lo que dice el documento 02 §6: es ordenable temporalmente, lo
// que mantiene la localidad del indice en `scan_events` y evita la
// fragmentacion de paginas que produciria un v4 aleatorio con millones de filas.
//
// No se usa `crypto.randomUUID()` porque genera v4.

/** Rellena el buffer con bytes aleatorios criptograficos. */
export type RandomBytesFiller = (target: Uint8Array) => void

const cryptoRandomBytes: RandomBytesFiller = (target) => {
  globalThis.crypto.getRandomValues(target)
}

const HEX = Array.from({ length: 256 }, (_, byte) => byte.toString(16).padStart(2, '0'))

/**
 * @param nowMs milisegundos desde epoch que se codifican en los 48 bits altos.
 * @param fillRandom inyectable para que las pruebas puedan fijar el resultado.
 */
export function uuidV7(
  nowMs: number = Date.now(),
  fillRandom: RandomBytesFiller = cryptoRandomBytes,
): string {
  const bytes = new Uint8Array(16)
  fillRandom(bytes)

  // 48 bits de marca de tiempo en milisegundos, big-endian.
  const timestamp = Math.floor(nowMs)
  bytes[0] = Math.floor(timestamp / 2 ** 40) & 0xff
  bytes[1] = Math.floor(timestamp / 2 ** 32) & 0xff
  bytes[2] = Math.floor(timestamp / 2 ** 24) & 0xff
  bytes[3] = Math.floor(timestamp / 2 ** 16) & 0xff
  bytes[4] = Math.floor(timestamp / 2 ** 8) & 0xff
  bytes[5] = timestamp & 0xff

  // Version 7 en el nibble alto del byte 6.
  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x70
  // Variante RFC 4122 (10xx) en los dos bits altos del byte 8.
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80

  let out = ''
  for (let index = 0; index < 16; index += 1) {
    if (index === 4 || index === 6 || index === 8 || index === 10) {
      out += '-'
    }
    out += HEX[bytes[index] ?? 0]
  }

  return out
}

/** Comprobacion de forma, util en pruebas y en la validacion de la cola. */
export function isUuidV7(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value)
}
