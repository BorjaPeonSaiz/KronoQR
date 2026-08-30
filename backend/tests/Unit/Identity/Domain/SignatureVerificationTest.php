<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Exception\InvalidSigningKey;
use App\Modules\Identity\Domain\ValueObject\QrKeyring;
use App\Modules\Identity\Domain\ValueObject\QrPayload;
use App\Modules\Identity\Domain\ValueObject\QrSigningKey;

/*
 * Verificacion de la firma HMAC (doc 02 §5.1 y §5.2, ADR-005, RF-QR-02).
 *
 * `php artisan test --filter=SignatureVerification` es una de las dos ordenes de
 * verificacion de la tarea 1.5.
 *
 * Suite unitaria a proposito: firmar y verificar es deterministico y no necesita
 * ni base de datos ni framework. Que se pueda probar asi es consecuencia de que
 * las claves entren por un puerto en lugar de leerse de la configuracion desde
 * dentro.
 */

/**
 * 32 bytes fijos, en base64. No es un secreto —esta en el repositorio— y por eso
 * no vale para nada fuera de aqui. Las de una instalacion las genera el
 * instalador en el servidor del cliente (§7.7).
 */
function testSigningKey(string $id = 'a3'): QrSigningKey
{
    return QrSigningKey::fromBase64($id, base64_encode('clave-de-firma-de-pruebas-KrnQR1'));
}

function otherSigningKey(string $id = 'a2'): QrSigningKey
{
    return QrSigningKey::fromBase64($id, base64_encode('clave-anterior-de-pruebas-KrnQR2'));
}

it('firma un token y verifica el payload que produce', function (): void {
    $key = testSigningKey();

    $payload = $key->sign('7QK2mXpR9vLdN4tZbYcF1w');

    expect($payload->keyId)->toBe('a3')
        ->and($payload->token)->toBe('7QK2mXpR9vLdN4tZbYcF1w')
        ->and($key->verifies($payload))->toBeTrue();
})->group('RF-QR-01', 'RF-QR-02');

it('produce siempre la misma firma para el mismo token y la misma clave', function (): void {
    // Es lo que permite reimprimir una tarjeta perdida sin reemitirla, y lo que
    // hace que la verificacion no dependa de nada mas que del payload.
    $key = testSigningKey();

    expect($key->sign('7QK2mXpR9vLdN4tZbYcF1w')->toString())
        ->toBe($key->sign('7QK2mXpR9vLdN4tZbYcF1w')->toString());
})->group('RF-QR-02');

it('rechaza un payload con la firma manipulada', function (): void {
    // El criterio de terminado del prompt §6.3: «prueba que demuestre que un
    // payload con firma manipulada se rechaza».
    $key = testSigningKey();
    $valido = $key->sign('7QK2mXpR9vLdN4tZbYcF1w');

    $manipulado = QrPayload::of(
        $valido->keyId,
        $valido->token,
        // Se cambia UN caracter. La comparacion es en tiempo constante, asi que
        // el atacante no puede ir acertandola byte a byte.
        ($valido->signature[0] === 'A' ? 'B' : 'A').substr($valido->signature, 1),
    );

    expect($key->verifies($manipulado))->toBeFalse();
})->group('RF-QR-02', 'RS-03');

it('rechaza un payload cuyo token se ha cambiado', function (): void {
    // Falsificar una tarjeta a partir de otra valida: mismo key_id, misma firma,
    // otro token. Sin la clave del servidor es computacionalmente inviable (§5.4).
    $key = testSigningKey();
    $valido = $key->sign('7QK2mXpR9vLdN4tZbYcF1w');

    $suplantado = QrPayload::of($valido->keyId, 'AAAAAAAAAAAAAAAAAAAAAA', $valido->signature);

    expect($key->verifies($suplantado))->toBeFalse();
})->group('RF-QR-02', 'RS-03');

it('rechaza un payload al que se le ha cambiado el key_id', function (): void {
    // La firma cubre el key_id (§5.1). Sin eso, durante un solape de claves se
    // podria coger una tarjeta firmada con la anterior, reetiquetarla y forzar
    // la verificacion contra la otra clave.
    $key = testSigningKey('a3');
    $valido = $key->sign('7QK2mXpR9vLdN4tZbYcF1w');

    $reetiquetado = QrPayload::of('a2', $valido->token, $valido->signature);

    expect(testSigningKey('a2')->verifies($reetiquetado))->toBeFalse();
})->group('RF-QR-02', 'RF-QR-07');

it('no verifica con una clave distinta', function (): void {
    $payload = testSigningKey()->sign('7QK2mXpR9vLdN4tZbYcF1w');

    expect(otherSigningKey('a3')->verifies($payload))->toBeFalse();
})->group('RF-QR-02');

it('exige 32 bytes de clave y base64 estricto', function (): void {
    // El Anexo B dice «32 bytes, base64». HMAC-SHA256 con una clave mas corta
    // pierde fuerza sin avisar, que es la peor forma de fallar de un control
    // criptografico.
    expect(static fn () => QrSigningKey::fromBase64('a3', base64_encode('demasiado-corta')))
        ->toThrow(InvalidSigningKey::class)
        ->and(static fn () => QrSigningKey::fromBase64('a3', 'esto no es base64 !!'))
        ->toThrow(InvalidSigningKey::class)
        ->and(static fn () => QrSigningKey::fromBase64('a3', ''))
        ->toThrow(InvalidSigningKey::class)
        ->and(static fn () => QrSigningKey::fromBase64('demasiado-largo', base64_encode('clave-de-firma-de-pruebas-KrnQR1')))
        ->toThrow(InvalidSigningKey::class);
})->group('RS-01', 'RF-QR-02');

it('verifica con las dos claves del solape de una rotacion', function (): void {
    // §5.3, RF-QR-07: sin key_id habria que reimprimir toda la plantilla en un
    // solo dia. Con el, la nueva firma lo que se emite hoy y la anterior sigue
    // admitiendo lo que ya esta impreso.
    $actual = testSigningKey('a3');
    $anterior = otherSigningKey('a2');

    $keyring = QrKeyring::of($actual, $anterior);

    $tarjetaNueva = $actual->sign('7QK2mXpR9vLdN4tZbYcF1w');
    $tarjetaAntigua = $anterior->sign('AAAAAAAAAAAAAAAAAAAAAA');

    expect($keyring->find('a3')?->verifies($tarjetaNueva))->toBeTrue()
        ->and($keyring->find('a2')?->verifies($tarjetaAntigua))->toBeTrue()
        ->and($keyring->current()->id)->toBe('a3')
        ->and($keyring->keyIds())->toEqualCanonicalizing(['a2', 'a3']);
})->group('RF-QR-07');

it('no resuelve una clave retirada ni una desconocida', function (): void {
    // Paso 2 del §5.2. Retirar la clave anterior es lo que invalida de golpe las
    // tarjetas que quedaran sin reimprimir.
    $keyring = QrKeyring::of(testSigningKey('a3'));

    expect($keyring->find('a2'))->toBeNull()
        ->and($keyring->find('zz'))->toBeNull()
        ->and($keyring->keyIds())->toBe(['a3']);
})->group('RF-QR-02', 'RF-QR-07');

it('acepta la firma de la clave anterior y no la de una retirada', function (): void {
    // Las dos mitades de RF-QR-07 en la misma prueba, porque son la misma
    // decision vista desde los dos lados: durante el solape la tarjeta vieja
    // ficha, y cuando la clave se retira deja de fichar **de forma generica**
    // (RS-03) — indistinguible de «no existe» y de «revocada». Quien traduce
    // ese `null` en un rechazo identico a los demas es `HmacSignatureVerifier`,
    // y lo comprueba `ConstantTimeRejectionTest`.
    $saliente = otherSigningKey('a2');
    $tarjetaVieja = $saliente->sign('7QK2mXpR9vLdN4tZbYcF1w');

    $duranteElSolape = QrKeyring::of(testSigningKey('a3'), $saliente);
    $trasLaRetirada = QrKeyring::of(testSigningKey('a3'));

    expect($duranteElSolape->find($tarjetaVieja->keyId)?->verifies($tarjetaVieja))->toBeTrue()
        // Retirada: la clave ya no esta, y sin clave no hay nada que verificar.
        ->and($trasLaRetirada->find($tarjetaVieja->keyId))->toBeNull();
})->group('RF-QR-07', 'RS-03');

it('sabe cual de las dos claves es la que esta saliendo', function (): void {
    // Es lo que necesitan `credentials:rotate-key`, el filtro `?key_id=` del
    // panel y la metrica de avance: **cual** queda por reimprimir. Nunca la
    // clave, solo su identificador, que ademas va impreso en cada tarjeta.
    expect(QrKeyring::of(testSigningKey('a3'), otherSigningKey('a2'))->previousId())->toBe('a2')
        // Fuera de una rotacion no hay ninguna saliente.
        ->and(QrKeyring::of(testSigningKey('a3'))->previousId())->toBeNull()
        ->and(QrKeyring::empty()->previousId())->toBeNull()
        // Y el descuido de repetir el key_id en las dos variables no es un
        // solape: el llavero las colapsa y no hay nada que rotar.
        ->and(QrKeyring::of(testSigningKey('a3'), otherSigningKey('a3'))->previousId())->toBeNull();
})->group('RF-QR-07');

it('se niega a emitir cuando la instalacion no tiene clave', function (): void {
    // Emitir sin clave produciria tarjetas que nadie puede verificar. Verificar
    // sin clave, en cambio, no revienta: rechaza de forma generica.
    $vacio = QrKeyring::empty();

    expect($vacio->hasCurrent())->toBeFalse()
        ->and($vacio->find('a3'))->toBeNull()
        ->and(static fn () => $vacio->current())->toThrow(InvalidSigningKey::class);
})->group('RS-01', 'RF-QR-01');
