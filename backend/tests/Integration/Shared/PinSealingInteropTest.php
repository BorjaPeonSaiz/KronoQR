<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Adapter\SodiumSealedPinOpener;
use Tests\Architecture\Support\Repo;

/*
 * **Interoperabilidad del sobre del PIN, JS -> PHP** (PIN-04, verificacion de
 * la 2.1.0, RF-AT-11, RS-12).
 *
 * El quiosco sella el PIN con `sealPin` (libsodium-wrappers, WebAssembly) y el
 * servidor lo abre con `SodiumSealedPinOpener` (ext-sodium). Son dos libsodium
 * en dos lenguajes; las pruebas de cada lado solo demostraban que cada uno se
 * entendia consigo mismo. Aqui se abre un sobre REAL producido por el `sealPin`
 * de produccion (`frontend-kiosk/scripts/generate-pin-sealing-fixture.mjs`).
 *
 * La pareja X25519 es de PRUEBA y se deriva de una frase publica versionada en
 * el fixture: no hay ninguna clave privada en el repositorio. Se reconstruye
 * con la misma receta que la cabecera del guion.
 *
 * Se lee el fixture de `frontend-kiosk/` y no una copia en `backend/`: una
 * copia se quedaria vieja en silencio al regenerar el sobre.
 */

/** Ruta, relativa a la raiz del repositorio, del fixture que escribe el quiosco. */
const PIN_SEALING_INTEROP_FIXTURE = 'frontend-kiosk/tests/fixtures/pin-sealing-interop.json';

/**
 * El fixture ya decodificado. Si falta, la prueba FALLA con un mensaje claro:
 * saltarla dejaria sin cubrir justo el camino que existe para cubrir.
 *
 * @return array{seed_phrase: string, pin: string, pin_sealed: string, x25519_public_base64: string}
 */
function pinSealingInteropFixture(): array
{
    $ruta = Repo::file(PIN_SEALING_INTEROP_FIXTURE);

    expect(is_file($ruta))->toBeTrue(
        PIN_SEALING_INTEROP_FIXTURE.' no existe o no es visible en '.$ruta.'. Se genera con '
        .'`node frontend-kiosk/scripts/generate-pin-sealing-fixture.mjs`. Dentro del contenedor, si el '
        .'fichero esta en disco, es el bind mount de Docker Desktop: la CI es la referencia.',
    );

    /** @var array{seed_phrase: string, pin: string, pin_sealed: string, x25519_public_base64: string} $fixture */
    $fixture = json_decode((string) file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);

    return $fixture;
}

/** Configura la clave secreta de PRUEBA derivada de la frase del fixture. */
function pinSealingInteropConfigureKey(string $fraseSemilla): void
{
    $semilla = hash('sha256', $fraseSemilla, true);
    $secreta = sodium_crypto_box_secretkey(sodium_crypto_box_seed_keypair($semilla));

    config(['identity.pin.sealing.secret_key' => base64_encode($secreta)]);
}

it('abre con SodiumSealedPinOpener el sobre sellado por el sealPin real del quiosco', function (): void {
    $fixture = pinSealingInteropFixture();
    pinSealingInteropConfigureKey($fixture['seed_phrase']);

    $opener = new SodiumSealedPinOpener;

    expect($opener->open($fixture['pin_sealed']))->toBe('483920')
        ->and($fixture['pin'])->toBe('483920');
})->group('RF-AT-11', 'RS-12');

it('publica la misma clave publica con la que sello el quiosco', function (): void {
    // Si PHP derivara otra clave publica de la misma secreta, el padron del
    // quiosco repartiria una clave con la que nada de lo sellado se abre.
    $fixture = pinSealingInteropFixture();
    pinSealingInteropConfigureKey($fixture['seed_phrase']);

    expect((new SodiumSealedPinOpener)->publicKey())->toBe($fixture['x25519_public_base64']);
})->group('RF-AT-11', 'RS-12');

it('no abre el sobre del quiosco con otra clave secreta', function (): void {
    $fixture = pinSealingInteropFixture();
    pinSealingInteropConfigureKey('otra-frase-que-no-es-la-del-fixture');

    expect((new SodiumSealedPinOpener)->open($fixture['pin_sealed']))->toBeNull();
})->group('RF-AT-11', 'RS-12');
