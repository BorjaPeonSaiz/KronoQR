// Sobre cerrado del PIN (RF-AT-11, RL-12). El oraculo de descifrado es
// libsodium ABRIENDO el sobre con la clave privada de una pareja generada
// aqui mismo — no se confia en que `sealPin` diga la verdad sobre si mismo,
// se comprueba que lo que produce es un `crypto_box_seal` de verdad.

// Import por defecto: ver la nota en `pinSealing.ts` sobre por que `import *`
// deja `crypto_box_seal`/`crypto_box_keypair` como `undefined` en marcha.
import sodium from 'libsodium-wrappers'
import { beforeAll, describe, expect, it } from 'vitest'
import { PinSealingError, sealPin } from '@/features/pin/infrastructure/pinSealing'

let publicKeyBase64: string
let secretKey: Uint8Array
let publicKey: Uint8Array

beforeAll(async () => {
  await sodium.ready
  const keypair = sodium.crypto_box_keypair()
  publicKey = keypair.publicKey
  secretKey = keypair.privateKey
  publicKeyBase64 = sodium.to_base64(publicKey, sodium.base64_variants.ORIGINAL)
})

function openSealed(sealedBase64: string): string {
  const sealed = sodium.from_base64(sealedBase64, sodium.base64_variants.ORIGINAL)
  const opened = sodium.crypto_box_seal_open(sealed, publicKey, secretKey)
  return sodium.to_string(opened)
}

describe('sellado del PIN', () => {
  it('lo que produce se abre con la clave privada de la instalacion y recupera el PIN exacto', async () => {
    const sealed = await sealPin('483920', publicKeyBase64)

    expect(openSealed(sealed)).toBe('483920')
  })

  it('el PIN en claro no aparece en ningun sitio del resultado', async () => {
    const sealed = await sealPin('483920', publicKeyBase64)

    expect(sealed).not.toContain('483920')
    expect(sealed).not.toBe('483920')
  })

  it('es base64 estandar, del tamano que describe el contrato (54 bytes sellados)', async () => {
    const sealed = await sealPin('000000', publicKeyBase64)

    expect(sealed).toMatch(/^[A-Za-z0-9+/]+={0,2}$/)
    expect(sodium.from_base64(sealed, sodium.base64_variants.ORIGINAL)).toHaveLength(54)
  })

  it('dos sellados del MISMO PIN producen criptogramas distintos (clave efimera por llamada)', async () => {
    const first = await sealPin('483920', publicKeyBase64)
    const second = await sealPin('483920', publicKeyBase64)

    expect(first).not.toBe(second)
    expect(openSealed(first)).toBe('483920')
    expect(openSealed(second)).toBe('483920')
  })

  it('una clave publica con forma imposible se rechaza como fallo de sellado, no como excepcion rara', async () => {
    await expect(sealPin('483920', 'no-es-base64-valido')).rejects.toBeInstanceOf(PinSealingError)
  })

  it('nadie puede abrir el sobre sin la clave privada de la instalacion: otra pareja no lo abre', async () => {
    const sealed = await sealPin('483920', publicKeyBase64)
    const otherPair = sodium.crypto_box_keypair()

    expect(() =>
      sodium.crypto_box_seal_open(
        sodium.from_base64(sealed, sodium.base64_variants.ORIGINAL),
        publicKey,
        otherPair.privateKey,
      ),
    ).toThrow()
  })
})
