#!/usr/bin/env node
//
// KronoQR — fixture de interoperabilidad del sobre del PIN, JS -> PHP (PIN-04,
// verificacion de la 2.1.0, RF-AT-11).
//
// El quiosco sella el PIN con `sealPin` (libsodium-wrappers, WebAssembly) y el
// servidor lo abre con `SodiumSealedPinOpener` (ext-sodium de PHP). Son dos
// libsodium distintas, en dos lenguajes, y nada garantizaba que lo que sella
// una lo abra la otra: base64 estandar frente a URL-safe, PIN como cadena
// frente a bytes, clave publica derivada o no. Este guion deja un sobre REAL,
// producido por el `sealPin` de produccion (no por una copia), para que la
// prueba de PHP lo abra.
//
// LA CLAVE ES DE PRUEBA Y SE DERIVA DE UNA FRASE, NO SE VERSIONA. En el
// fixture no hay ninguna clave privada: solo la frase `seed_phrase`. La pareja
// X25519 sale de
//
//   seed   = SHA-256(seed_phrase)                       (32 bytes)
//   pareja = crypto_box_seed_keypair(seed)
//
// y en PHP se reconstruye igual:
//
//   $seed    = hash('sha256', $fixture['seed_phrase'], true);
//   $secret  = sodium_crypto_box_secretkey(sodium_crypto_box_seed_keypair($seed));
//   config(['identity.pin.sealing.secret_key' => base64_encode($secret)]);
//
// Esa frase es publica y la pareja que genera NO sirve para nada fuera de las
// pruebas: ninguna instalacion la usa (cada una genera la suya, ver la cabecera
// de `SodiumSealedPinOpener`).
//
// El sobre cambia en cada ejecucion (clave efimera por llamada): regenerarlo
// no rompe nada, y `tests/unit/pinSealingInterop.spec.ts` comprueba que el
// sobre versionado se abre con la pareja de la frase.
//
//   node scripts/generate-pin-sealing-fixture.mjs
//
// Codigos de salida: 0 fichero escrito; 1 error.

import { createHash } from 'node:crypto'
import { writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import sodium from 'libsodium-wrappers'
import { sealPin } from '../src/features/pin/infrastructure/pinSealing.ts'

const SEED_PHRASE = 'kronoqr/pin-sealing-interop/v1'
const PIN = '483920'
const OUTPUT = fileURLToPath(new URL('../tests/fixtures/pin-sealing-interop.json', import.meta.url))

await sodium.ready
const seed = new Uint8Array(createHash('sha256').update(SEED_PHRASE, 'utf8').digest())
const pair = sodium.crypto_box_seed_keypair(seed)
const x25519Public = sodium.to_base64(pair.publicKey, sodium.base64_variants.ORIGINAL)

const pinSealed = await sealPin(PIN, x25519Public)

const fixture = {
  description:
    'Sobre del PIN sellado por sealPin (libsodium-wrappers) para abrirlo con SodiumSealedPinOpener. Pareja de PRUEBA derivada de seed_phrase; ver scripts/generate-pin-sealing-fixture.mjs.',
  seed_phrase: SEED_PHRASE,
  derivation: 'crypto_box_seed_keypair(SHA-256(seed_phrase))',
  x25519_public_base64: x25519Public,
  pin: PIN,
  pin_sealed: pinSealed,
  sealed_with: 'libsodium-wrappers 0.8.4, sealPin() de src/features/pin/infrastructure',
}

writeFileSync(OUTPUT, `${JSON.stringify(fixture, null, 2)}\n`, 'utf8')
process.stdout.write(`fixture escrito en ${OUTPUT}\n`)
