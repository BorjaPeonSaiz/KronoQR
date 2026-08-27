// SHA-256 SINCRONO, en TypeScript puro.
//
// POR QUE NO `crypto.subtle.digest`. Porque `subtle` es asincrono y esto se usa
// en el camino de los 300 ms: `RosterLookupPort.displayNameFor` es sincrona a
// proposito (ver `features/scan/application/ports.ts`), y un `await` contra
// WebCrypto por cada escaneo es exactamente lo que esa firma existe para
// impedir. Un `await` no bloquea, pero mete la resolucion del nombre en un turno
// posterior del bucle de eventos, y entonces la confirmacion ya no puede
// pintarse en el mismo turno que la decodificacion.
//
// PARA QUE SE USA. Para una sola cosa: calcular el `token_hash` del padron
// (`KioskRosterEntry.token_hash` = SHA-256 hex del token de la tarjeta) sobre lo
// que acaba de leer la camara, y buscarlo en el indice en memoria.
//
// QUE NO ES. No es una primitiva de seguridad de este cliente. El quiosco no
// verifica firmas —no tiene la clave HMAC y no la tendra nunca (regla dura 10)—
// y este hash no autoriza nada: solo decide si sabemos saludar por el nombre.
// El cifrado real del padron usa AES-GCM de WebCrypto, no esto.

const K = new Uint32Array([
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
])

const HEX = Array.from({ length: 256 }, (_, byte) => byte.toString(16).padStart(2, '0'))

function rotr(value: number, shift: number): number {
  return (value >>> shift) | (value << (32 - shift))
}

/** SHA-256 de una secuencia de bytes, en hexadecimal minusculas. */
export function sha256HexOfBytes(input: Uint8Array): string {
  const bitLength = input.length * 8

  // Relleno: 0x80, ceros hasta 56 mod 64, y la longitud en 64 bits big-endian.
  const paddedLength = (((input.length + 8) >> 6) + 1) << 6
  const message = new Uint8Array(paddedLength)
  message.set(input)
  message[input.length] = 0x80

  const view = new DataView(message.buffer)
  // La longitud cabe de sobra en 32 bits: aqui solo se hashean tokens de 22
  // caracteres. Los 32 bits altos quedan a cero.
  view.setUint32(paddedLength - 4, bitLength >>> 0, false)
  view.setUint32(paddedLength - 8, Math.floor(bitLength / 2 ** 32), false)

  const state = new Uint32Array([
    0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
  ])
  const schedule = new Uint32Array(64)

  for (let offset = 0; offset < paddedLength; offset += 64) {
    for (let index = 0; index < 16; index += 1) {
      schedule[index] = view.getUint32(offset + index * 4, false)
    }
    for (let index = 16; index < 64; index += 1) {
      const previous = schedule[index - 15] ?? 0
      const distant = schedule[index - 2] ?? 0
      const s0 = rotr(previous, 7) ^ rotr(previous, 18) ^ (previous >>> 3)
      const s1 = rotr(distant, 17) ^ rotr(distant, 19) ^ (distant >>> 10)
      schedule[index] = ((schedule[index - 16] ?? 0) + s0 + (schedule[index - 7] ?? 0) + s1) >>> 0
    }

    let a = state[0] ?? 0
    let b = state[1] ?? 0
    let c = state[2] ?? 0
    let d = state[3] ?? 0
    let e = state[4] ?? 0
    let f = state[5] ?? 0
    let g = state[6] ?? 0
    let h = state[7] ?? 0

    for (let index = 0; index < 64; index += 1) {
      const s1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25)
      const choose = (e & f) ^ (~e & g)
      const temp1 = (h + s1 + choose + (K[index] ?? 0) + (schedule[index] ?? 0)) >>> 0
      const s0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22)
      const majority = (a & b) ^ (a & c) ^ (b & c)
      const temp2 = (s0 + majority) >>> 0

      h = g
      g = f
      f = e
      e = (d + temp1) >>> 0
      d = c
      c = b
      b = a
      a = (temp1 + temp2) >>> 0
    }

    state[0] = ((state[0] ?? 0) + a) >>> 0
    state[1] = ((state[1] ?? 0) + b) >>> 0
    state[2] = ((state[2] ?? 0) + c) >>> 0
    state[3] = ((state[3] ?? 0) + d) >>> 0
    state[4] = ((state[4] ?? 0) + e) >>> 0
    state[5] = ((state[5] ?? 0) + f) >>> 0
    state[6] = ((state[6] ?? 0) + g) >>> 0
    state[7] = ((state[7] ?? 0) + h) >>> 0
  }

  let out = ''
  for (let index = 0; index < 8; index += 1) {
    const word = state[index] ?? 0
    out +=
      (HEX[(word >>> 24) & 0xff] ?? '') +
      (HEX[(word >>> 16) & 0xff] ?? '') +
      (HEX[(word >>> 8) & 0xff] ?? '') +
      (HEX[word & 0xff] ?? '')
  }
  return out
}

const encoder = new TextEncoder()

/** SHA-256 de una cadena UTF-8, en hexadecimal minusculas. */
export function sha256Hex(input: string): string {
  return sha256HexOfBytes(encoder.encode(input))
}
