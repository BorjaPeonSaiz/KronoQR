// Fixture de interoperabilidad JS -> PHP del sobre del PIN (PIN-04, RF-AT-11).
//
// `tests/fixtures/pin-sealing-interop.json` lo escribe
// `scripts/generate-pin-sealing-fixture.mjs` con el `sealPin` de produccion, y
// lo abre la prueba de PHP de `SodiumSealedPinOpener`. Estas pruebas vigilan
// el lado JS: que el fixture versionado sigue siendo un sobre valido para la
// pareja de PRUEBA derivada de su frase, y que `sealPin` sigue produciendo
// sobres que esa misma pareja abre.

import { createHash } from 'node:crypto'
import sodium from 'libsodium-wrappers'
import { beforeAll, describe, expect, it } from 'vitest'
import { sealPin } from '@/features/pin/infrastructure/pinSealing'
import fixture from '../fixtures/pin-sealing-interop.json'

let pair: { publicKey: Uint8Array; privateKey: Uint8Array }

beforeAll(async () => {
  await sodium.ready
  const seed = new Uint8Array(createHash('sha256').update(fixture.seed_phrase, 'utf8').digest())
  pair = sodium.crypto_box_seed_keypair(seed)
})

function openWithFixturePair(sealedBase64: string): string {
  const sealed = sodium.from_base64(sealedBase64, sodium.base64_variants.ORIGINAL)
  return sodium.to_string(sodium.crypto_box_seal_open(sealed, pair.publicKey, pair.privateKey))
}

describe('fixture de interoperabilidad del sobre del PIN', () => {
  it('la clave publica del fixture es la que se deriva de su frase', () => {
    expect(sodium.to_base64(pair.publicKey, sodium.base64_variants.ORIGINAL)).toBe(
      fixture.x25519_public_base64,
    )
  })

  it('el sobre versionado se abre con la pareja de la frase y devuelve el PIN 483920', () => {
    expect(openWithFixturePair(fixture.pin_sealed)).toBe('483920')
  })

  it('sealPin con la clave publica del fixture produce un sobre que esa pareja abre', async () => {
    const sealed = await sealPin('483920', fixture.x25519_public_base64)

    expect(openWithFixturePair(sealed)).toBe('483920')
  })
})
