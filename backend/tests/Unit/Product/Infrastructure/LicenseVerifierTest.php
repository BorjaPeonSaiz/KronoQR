<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\LicenseRejection;
use App\Modules\Product\Infrastructure\Adapter\Ed25519LicenseVerifier;

/*
 * La verificacion de la firma (RF-PD-04, ADR-018).
 *
 * EL PAR SE GENERA AQUI, EN CADA EJECUCION. Nunca hay una clave privada fija en
 * el repositorio, ni siquiera «de pruebas»: seria un secreto versionado (§7.7,
 * RS-08) y ademas normalizaria la idea de que puede haberlos.
 *
 * VIVE EN `tests/Unit/Product/Infrastructure/` y no bajo `Domain/`, porque lo que
 * prueba es un ADAPTADOR. Sigue siendo unitaria —no toca base de datos, ni red,
 * ni framework: entra una cadena y sale un objeto de dominio, y sodium es una
 * extension de PHP, no una dependencia que haya que levantar—, pero el arbol de
 * pruebas tiene que decir la verdad sobre que capa se esta ejercitando.
 */

/**
 * Un par ed25519 nuevo.
 *
 * @return array{public: string, secret: non-empty-string}
 */
function mintFreshPair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [
        'public' => sodium_bin2hex(sodium_crypto_sign_publickey($pair)),
        'secret' => sodium_crypto_sign_secretkey($pair),
    ];
}

/**
 * El par de este fichero, generado **una vez por ejecucion**.
 *
 * Se memoiza por presupuesto: la suite unitaria tiene un techo de duracion
 * (doc 02 §9.2) y generar un par en cada fila de cada conjunto de datos lo
 * consume sin aportar nada — lo que se prueba en esos casos es el formato, no la
 * criptografia. **Sigue sin haber ningun par fijo en el repositorio** (§7.7,
 * RS-08), que es lo que importa; los casos que necesitan un emisor distinto
 * piden uno nuevo con {@see mintFreshPair()}.
 *
 * @return array{public: string, secret: non-empty-string}
 */
function mintPair(): array
{
    static $pair = null;

    if ($pair === null) {
        $pair = mintFreshPair();
    }

    return $pair;
}

/**
 * @param  non-empty-string  $secret
 * @param  array<string, mixed>  $claims
 */
function signKey(string $secret, array $claims): string
{
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $payload = $encode(json_encode($claims, JSON_THROW_ON_ERROR));

    return 'KQL1.'.$payload.'.'.$encode(sodium_crypto_sign_detached($payload, $secret));
}

/**
 * @return array<string, mixed>
 */
function verifiableClaims(): array
{
    return [
        'license_id' => 'lic-1',
        'customer_name' => 'Hotel de Pruebas, S.L.',
        'plan' => 'estandar',
        'max_employees' => 50,
        'max_devices' => 3,
        'features' => ['advanced_reports'],
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-12-31T23:59:59Z',
        'issued_at' => '2025-12-15T10:00:00Z',
    ];
}

it('acepta una clave firmada con la clave privada del par instalado', function (): void {
    $pair = mintPair();
    $verifier = new Ed25519LicenseVerifier($pair['public']);

    $result = $verifier->verify(signKey($pair['secret'], verifiableClaims()));

    expect($result->isVerified())->toBeTrue()
        ->and($result->license?->customerName)->toBe('Hotel de Pruebas, S.L.')
        ->and($result->rejection)->toBeNull();
})->group('RF-PD-04');

it('rechaza una clave con un solo byte alterado', function (): void {
    // La verificacion de ADR-018, literal. Se altera un caracter de la CARGA
    // UTIL, que es lo que alguien tocaria para regalarse mas empleados.
    $pair = mintPair();
    $key = signKey($pair['secret'], verifiableClaims());

    [$format, $payload, $signature] = explode('.', $key);
    $payload[10] = $payload[10] === 'A' ? 'B' : 'A';

    $result = (new Ed25519LicenseVerifier($pair['public']))->verify($format.'.'.$payload.'.'.$signature);

    expect($result->isVerified())->toBeFalse()
        ->and($result->rejection)->toBe(LicenseRejection::BadSignature);
})->group('RF-PD-04');

it('rechaza una clave con la firma alterada', function (): void {
    $pair = mintPair();
    $key = signKey($pair['secret'], verifiableClaims());

    [$format, $payload, $signature] = explode('.', $key);
    $signature[5] = $signature[5] === 'A' ? 'B' : 'A';

    expect((new Ed25519LicenseVerifier($pair['public']))->verify($format.'.'.$payload.'.'.$signature)->rejection)
        ->toBe(LicenseRejection::BadSignature);
})->group('RF-PD-04');

it('rechaza una clave firmada por otro emisor', function (): void {
    // La otra verificacion de ADR-018. Es la razon de que la firma sea
    // asimetrica y no HMAC: con clave simetrica, el secreto para verificar seria
    // el mismo que para emitir y viajaria en cada instalacion.
    $ours = mintPair();
    $theirs = mintFreshPair();

    $result = (new Ed25519LicenseVerifier($ours['public']))
        ->verify(signKey($theirs['secret'], verifiableClaims()));

    expect($result->isVerified())->toBeFalse()
        ->and($result->rejection)->toBe(LicenseRejection::BadSignature);
})->group('RF-PD-04');

it('rechaza lo que no tiene forma de clave', function (string $key): void {
    $pair = mintPair();

    expect((new Ed25519LicenseVerifier($pair['public']))->verify($key)->rejection)
        ->toBe(LicenseRejection::Malformed);
})->with([
    'vacia' => [''],
    'sin partes' => ['KQL1'],
    'con dos partes' => ['KQL1.eyJhIjoxfQ'],
    'con cuatro partes' => ['KQL1.a.b.c'],
    'formato desconocido' => ['KQL9.eyJhIjoxfQ.Zm9v'],
    'sin prefijo' => ['eyJhIjoxfQ.Zm9v'],
    'carga no base64url' => ['KQL1.no valido!.Zm9v'],
    // La firma tiene que medir 64 bytes. Una mas corta ni se pasa a sodium.
    'firma corta' => ['KQL1.eyJhIjoxfQ.Zm9v'],
])->group('RF-PD-04');

it('rechaza una carga util firmada que no sirve, y lo distingue de una firma mala', function (string $payload): void {
    // La firma cuadra: es un fallo de EMISION, no una manipulacion. Se
    // distinguen porque la accion siguiente del cliente es distinta —pedir una
    // clave nueva frente a volver a copiarla— y porque una es culpa nuestra.
    $pair = mintPair();
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $encoded = $encode($payload);
    $key = 'KQL1.'.$encoded.'.'.$encode(sodium_crypto_sign_detached($encoded, $pair['secret']));

    expect((new Ed25519LicenseVerifier($pair['public']))->verify($key)->rejection)
        ->toBe(LicenseRejection::InvalidPayload);
})->with([
    'no es JSON' => ['esto no es json'],
    'es una lista' => ['[1,2,3]'],
    'es un escalar' => ['42'],
    'le falta el cliente' => ['{"plan":"estandar"}'],
    'objeto vacio' => ['{}'],
])->group('RF-PD-04');

it('sin clave publica no verifica nada, y lo dice con un motivo propio', function (string $publicKey): void {
    // Una compilacion de desarrollo, o un despliegue al que le falta
    // `LICENSE_PUBLIC_KEY`. No es un problema de la clave del cliente y por eso
    // no se confunde con los otros tres: lo que hay que revisar es el
    // despliegue, no pedir otra clave.
    $pair = mintPair();

    expect((new Ed25519LicenseVerifier($publicKey))->verify(signKey($pair['secret'], verifiableClaims()))->rejection)
        ->toBe(LicenseRejection::NoPublicKey);
})->with([
    'vacia' => [''],
    'solo espacios' => ['   '],
    'no es hexadecimal' => ['no-es-hexadecimal-'.str_repeat('z', 46)],
    'longitud incorrecta' => [str_repeat('ab', 20)],
])->group('RF-PD-04');

it('no lanza nunca, pase lo que pase', function (): void {
    // El camino de LECTURA lo recorre el `FeatureGate` en cualquier pantalla del
    // panel. Una excepcion aqui seria un 500 por culpa de una fila de licencia,
    // que es exactamente lo que ADR-019 no quiere cerca del sistema.
    $verifier = new Ed25519LicenseVerifier(mintPair()['public']);

    foreach (['', 'basura', 'KQL1..', str_repeat('x', 10000), "KQL1.\n.\t"] as $input) {
        expect($verifier->verify($input)->isVerified())->toBeFalse();
    }
})->group('RF-PD-04');
