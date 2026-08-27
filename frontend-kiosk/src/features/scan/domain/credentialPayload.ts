// Verificacion LOCAL del formato del payload de la tarjeta (doc 02 §5.1).
//
//   FH1.<key_id>.<token>.<sig>
//
// QUE SE COMPRUEBA AQUI Y QUE NO. Aqui solo el FORMATO. La FIRMA no se verifica
// en el quiosco y no se verificara nunca: exige la clave HMAC, que no sale del
// servidor (regla dura 10). Un quiosco que pudiera validar firmas seria un
// quiosco del que se pueden fabricar tarjetas.
//
// PARA QUE SIRVE ENTONCES. Para descartar lo que evidentemente no es una tarjeta
// de KronoQR —la URL del wifi, un codigo de un albaran, el QR de un menu— sin
// gastar una entrada de la cola ni una peticion. Es el primer paso del diagrama
// del §6.
//
// POR QUE NO SE VALIDAN LAS LONGITUDES EXACTAS. El §5.1 dice `key_id` de 2
// caracteres, `token` de 22 y `sig` de 16. Comprobarlo aqui haria que el dia que
// esos tamanos cambien —rotacion a una firma mas larga, por ejemplo— TODAS las
// tablets del parque dejasen de fichar hasta que alguien las actualizara, y eso
// choca de frente con la regla dura 19. La version del esquema es el prefijo
// `FH1`: si el formato cambia de verdad, cambia el prefijo. Aqui se comprueba lo
// estructural —cuatro segmentos, prefijo correcto, alfabeto base64url, longitud
// dentro del techo del contrato— y del resto responde el servidor, que es quien
// tiene la clave.

/** Prefijo y version del esquema. Renombrarlo invalidaria tarjetas ya impresas. */
export const CREDENTIAL_PREFIX = 'FH1'

/** Techo de `ScanRequest.qr_payload` en el contrato. Proteccion de recursos. */
export const MAX_PAYLOAD_LENGTH = 128

export interface CredentialPayload {
  readonly raw: string
  readonly keyId: string
  readonly token: string
  readonly signature: string
}

const BASE64URL_SEGMENT = /^[A-Za-z0-9_-]+$/

export function parseCredentialPayload(raw: string): CredentialPayload | null {
  if (typeof raw !== 'string') return null

  const value = raw.trim()
  if (value.length === 0 || value.length > MAX_PAYLOAD_LENGTH) return null

  const segments = value.split('.')
  if (segments.length !== 4) return null

  const [prefix, keyId, token, signature] = segments
  if (prefix !== CREDENTIAL_PREFIX) return null
  if (keyId === undefined || token === undefined || signature === undefined) return null

  for (const segment of [keyId, token, signature]) {
    if (!BASE64URL_SEGMENT.test(segment)) return null
  }

  return { raw: value, keyId, token, signature }
}

export function isCredentialPayload(raw: string): boolean {
  return parseCredentialPayload(raw) !== null
}
