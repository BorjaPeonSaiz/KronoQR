<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\CredentialSecret;
use App\Modules\Identity\Domain\ValueObject\QrPayload;
use App\Modules\Identity\Domain\ValueObject\QrSigningKey;
use Tests\Architecture\Support\Repo;

/*
 * El QR del video de camara simulada tiene la MISMA FORMA que una tarjeta
 * emitida por el producto (RQ-04, RF-QR-05; tarea 3.7, decision 2).
 *
 * POR QUE EXISTE ESTA PRUEBA
 * --------------------------
 * RQ-04 exige E2E «con camara simulada alimentada por video con un QR real».
 * El video lo genera `frontend-kiosk/scripts/generate-qr-fixture.mjs` con el
 * mismo ZXing que lee el quiosco y con nivel de correccion Q, a partir de un
 * payload por omision escrito a mano en el propio guion. Ese payload NO esta
 * firmado por ninguna clave real, y no puede estarlo: el job ⑦ corre sin
 * backend (`page.route`), asi que no hay ni clave ni comando que emita uno.
 *
 * «Real», entonces, significa **el formato real emitido por el producto**, y
 * eso si se puede comprobar sin levantar nada: se firma un token aleatorio con
 * una clave de prueba usando el MISMO codigo que firma las tarjetas
 * (`QrSigningKey::sign()`) y se compara la FORMA de lo que sale con la del
 * payload del fixture. Si manana cambia la longitud del token, el alfabeto de
 * la firma o el prefijo del esquema, esta prueba —que corre en la suite barata
 * de Architecture, sin base de datos— señala el fixture, en vez de dejar que el
 * E2E siga en verde grabando un formato que ya no existe.
 *
 * POR QUE EN `Architecture` Y NO EN `Contract`
 * -------------------------------------------
 * `tests/Contract` es, en este repositorio, la suite que confronta la API con
 * `docs/api/openapi.yaml` (un unico fichero, `OpenApiContractTest`), y aqui no
 * hay ningun endpoint de por medio. Lo que se comprueba es una costura entre
 * dos partes del arbol —un guion de `frontend-kiosk/` y un objeto de valor del
 * dominio de `Identity`—, que es justo lo que hacen `QualityGatesTest` o
 * `ClientDocumentationTest` leyendo ficheros del repositorio con
 * `Tests\Architecture\Support\Repo`. Ademas Architecture corre sin arrancar el
 * framework, y todo lo que se necesita aqui es `hash_hmac` y un `preg_match`.
 *
 * LO QUE ESTA PRUEBA NO DICE
 * --------------------------
 * No dice que el payload del fixture verifique contra ninguna clave —no lo
 * hace, y se afirma explicitamente mas abajo para que quede escrito—. Firma
 * invalida y forma invalida son cosas distintas: la primera la rechaza el paso
 * 3 del §5.2 en el servidor, y la segunda ni siquiera llega a salir del
 * quiosco. El E2E solo necesita la segunda.
 */

/** Ruta, relativa a la raiz del repositorio, del generador de los videos. */
function qrFixtureGenerator(): string
{
    return 'frontend-kiosk/scripts/generate-qr-fixture.mjs';
}

/**
 * El payload por omision del generador, leido del guion.
 *
 * Se lee del fichero y no se copia aqui a proposito: una copia se queda vieja
 * en silencio y esta prueba pasaria a comparar la forma de un payload que ya no
 * se graba en ningun video.
 */
function qrFixturePayload(): string
{
    $source = Repo::contents(qrFixtureGenerator());

    // La linea del guion es:
    //   process.env['KIOSK_E2E_QR_PAYLOAD'] ?? 'FH1.…'
    // Los `\s*` admiten el salto de linea con el que Prettier parte esa
    // asignacion, que es como esta escrita hoy.
    $found = preg_match(
        "/KIOSK_E2E_QR_PAYLOAD'\\]\\s*\\?\\?\\s*'([^']+)'/",
        $source,
        $matches,
    );

    expect($found === 1)->toBeTrue(
        qrFixtureGenerator().' ya no declara un payload por omision reconocible: '
        .'si cambio la forma de declararlo, hay que actualizar esta prueba; '
        .'si desaparecio, el video de camara simulada de RQ-04 se queda sin QR.',
    );

    // El `?? ''` es inalcanzable —la expectativa de arriba ya habria parado la
    // prueba con un mensaje util—, y esta para que el tipo sea `string` sin
    // suprimir nada en el analisis.
    return $matches[1] ?? '';
}

/**
 * Un payload emitido por el producto, con una clave de prueba y un token
 * aleatorio.
 *
 * El `key_id` es DISTINTO del que lleva el fixture (`a3`) a proposito: lo que
 * se compara es la forma, no el contenido, y con el mismo identificador la
 * igualdad de longitudes seria trivial en ese segmento.
 */
function qrEmittedPayload(): QrPayload
{
    $key = QrSigningKey::fromBase64(
        'Z9',
        base64_encode(random_bytes(QrSigningKey::MINIMUM_SECRET_BYTES)),
    );

    $secret = CredentialSecret::fromBytes(random_bytes(CredentialSecret::ENTROPY_BYTES));

    return $key->sign($secret->value);
}

/**
 * La forma de un payload: cuantos segmentos, con que prefijo y de que longitud
 * cada uno.
 *
 * No mira el contenido. Es lo unico que el fixture y una tarjeta de verdad
 * pueden compartir, porque el fixture no esta firmado.
 *
 * @return array{segments: int, prefix: string, lengths: list<int>}
 */
function qrPayloadShape(string $raw): array
{
    $segments = explode(QrPayload::SEPARATOR, $raw);

    return [
        'segments' => \count($segments),
        'prefix' => $segments[0],
        'lengths' => array_map(strlen(...), \array_slice($segments, 1)),
    ];
}

/**
 * Los caracteres de un segmento que NO pertenecen al alfabeto base64url sin
 * relleno (RFC 4648 §5), ordenados y sin repetir.
 *
 * Se devuelven en lugar de un booleano para que el fallo diga cual es el
 * caracter que sobra: un `+`, una `/` o un `=` en un QR que un lector
 * interprete como URL es exactamente el fallo que el §5.1 evita eligiendo
 * base64url.
 */
function qrCharactersOutsideBase64Url(string $segment): string
{
    $offenders = array_unique(str_split((string) preg_replace('/[A-Za-z0-9_-]/', '', $segment)));
    sort($offenders);

    return implode('', $offenders);
}

it('emite el payload del fixture con la misma forma que una tarjeta firmada', function (): void {
    // RQ-04: «un QR real». Real es el formato, y esta es la comparacion que lo
    // ata. Si el dominio cambia la longitud del token o el prefijo del esquema,
    // las dos formas dejan de coincidir y el fallo apunta al fixture.
    $fixture = qrPayloadShape(qrFixturePayload());
    $emitted = qrPayloadShape(qrEmittedPayload()->toString());

    expect($fixture)->toBe($emitted);

    // Y las dos son las del §5.1, escritas aqui como valores explicitos (§3.5,
    // «valores limite escritos explicitos»): 4 segmentos y 2 / 22 / 16.
    expect($fixture)->toBe([
        'segments' => 4,
        'prefix' => QrPayload::PREFIX,
        'lengths' => [
            QrPayload::KEY_ID_LENGTH,
            QrPayload::TOKEN_LENGTH,
            QrPayload::SIGNATURE_LENGTH,
        ],
    ]);
})->group('RQ-04', 'RF-QR-05');

it('escribe el token y la firma del fixture en el mismo alfabeto que el producto', function (): void {
    $fixture = QrPayload::parse(qrFixturePayload());
    $emitted = qrEmittedPayload();

    expect(qrCharactersOutsideBase64Url($fixture->token))->toBe('');
    expect(qrCharactersOutsideBase64Url($fixture->signature))->toBe('');
    expect(qrCharactersOutsideBase64Url($emitted->token))->toBe('');
    expect(qrCharactersOutsideBase64Url($emitted->signature))->toBe('');
})->group('RQ-04', 'RF-QR-05');

it('acepta el payload del fixture como bien formado, aunque su firma no valga', function (): void {
    // El parser del producto es el juez de «bien formado» (paso 1 del §5.2).
    $payload = QrPayload::parse(qrFixturePayload());

    expect($payload->toString())->toBe(qrFixturePayload());

    // Y la otra mitad, escrita para que nadie la confunda con un defecto: el
    // payload del fixture NO verifica contra ninguna clave. Es correcto y es
    // irrelevante para el E2E, que no habla con el servidor.
    $key = QrSigningKey::fromBase64(
        'a3',
        base64_encode(random_bytes(QrSigningKey::MINIMUM_SECRET_BYTES)),
    );

    expect($key->verifies($payload))->toBeFalse();
})->group('RQ-04', 'RF-QR-05');

it('denuncia un payload al que le falta un segmento', function (): void {
    // Control negativo. Una prueba que no puede fallar no vale nada (§9.2): con
    // un segmento menos —el caso mas probable si alguien «simplifica» el
    // fixture— la forma deja de coincidir y el parser lo rechaza.
    $mutilated = implode(
        QrPayload::SEPARATOR,
        \array_slice(explode(QrPayload::SEPARATOR, qrFixturePayload()), 0, 3),
    );

    expect(qrPayloadShape($mutilated))->not->toBe(qrPayloadShape(qrEmittedPayload()->toString()));
    expect(qrPayloadShape($mutilated)['segments'])->toBe(3);
    expect(QrPayload::tryParse($mutilated))->toBeNull();
})->group('RQ-04', 'RF-QR-05');
