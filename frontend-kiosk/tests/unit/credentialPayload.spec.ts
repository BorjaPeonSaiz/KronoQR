import { describe, expect, it } from 'vitest'
import {
  MAX_PAYLOAD_LENGTH,
  isCredentialPayload,
  parseCredentialPayload,
} from '@/features/scan/domain/credentialPayload'

const VALID = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'

describe('formato FH1 del payload de la tarjeta', () => {
  it('acepta el ejemplo literal del documento 02 §5.1', () => {
    expect(parseCredentialPayload(VALID)).toEqual({
      raw: VALID,
      keyId: 'a3',
      token: '7QK2mXpR9vLdN4tZbYcF1w',
      signature: 'k9Xm2pQrT5vN8wLa',
    })
  })

  it('descarta lo que no es una tarjeta de KronoQR', () => {
    expect(parseCredentialPayload('https://wifi.hotel.example')).toBeNull()
    expect(parseCredentialPayload('BEGIN:VCARD')).toBeNull()
    expect(parseCredentialPayload('')).toBeNull()
    expect(parseCredentialPayload('   ')).toBeNull()
  })

  it('exige el prefijo de version: es lo que permite migrar sin ambiguedad', () => {
    expect(parseCredentialPayload('FH2.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa')).toBeNull()
    expect(parseCredentialPayload('fh1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa')).toBeNull()
  })

  it('exige exactamente cuatro segmentos', () => {
    expect(parseCredentialPayload('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w')).toBeNull()
    expect(parseCredentialPayload('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.sig.extra')).toBeNull()
    expect(parseCredentialPayload('FH1.a3..k9Xm2pQrT5vN8wLa')).toBeNull()
  })

  it('exige alfabeto base64url en todos los segmentos', () => {
    expect(parseCredentialPayload('FH1.a3.7QK2mXpR9vLdN4tZbYcF1+.k9Xm2pQrT5vN8wLa')).toBeNull()
    expect(parseCredentialPayload('FH1.a3.7QK2 mXpR9vLdN4tZbY.k9Xm2pQrT5vN8wLa')).toBeNull()
  })

  it('respeta el techo de longitud del contrato', () => {
    const tooLong = `FH1.a3.${'a'.repeat(MAX_PAYLOAD_LENGTH)}.k9Xm2pQrT5vN8wLa`
    expect(parseCredentialPayload(tooLong)).toBeNull()
  })

  it('tolera espacios alrededor: un lector puede anadirlos', () => {
    expect(parseCredentialPayload(`  ${VALID}\n`)?.raw).toBe(VALID)
  })

  it('NO valida las longitudes exactas de key_id, token y firma', () => {
    // Deliberado (regla dura 19): si manana la firma pasa a 24 caracteres, las
    // tablets no pueden dejar de fichar hasta que alguien las actualice. La
    // autoridad sobre el payload es el servidor, que tiene la clave.
    expect(
      isCredentialPayload('FH1.abcd.tokenmaslargodelohabitual.firma-mas-larga-de-lo-normal'),
    ).toBe(true)
  })

  it('NO verifica la firma: eso exige una clave que no sale del servidor', () => {
    // Misma estructura, firma inventada: el quiosco la acepta y deja que el
    // servidor la rechace (regla dura 10).
    expect(isCredentialPayload('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.AAAAAAAAAAAAAAAA')).toBe(true)
  })
})
