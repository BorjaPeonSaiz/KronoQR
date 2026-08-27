<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Exception\MalformedQrPayload;
use App\Modules\Identity\Domain\ValueObject\QrPayload;

/*
 * La FORMA del payload impreso (doc 02 §5.1, ADR-005).
 *
 * Suite unitaria: sin base de datos y sin framework. La forma del payload no
 * necesita ninguna de las dos cosas, y que se pruebe aqui es lo que permite
 * ejecutar estas afirmaciones mientras se escribe el codigo.
 */

it('lee el payload de ejemplo del documento', function (): void {
    // Literal del §5.1. Si esta prueba falla, el codigo y el documento ya no
    // dicen lo mismo, y el documento es el que manda.
    $payload = QrPayload::parse('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa');

    expect($payload->keyId)->toBe('a3')
        ->and($payload->token)->toBe('7QK2mXpR9vLdN4tZbYcF1w')
        ->and($payload->signature)->toBe('k9Xm2pQrT5vN8wLa')
        ->and($payload->toString())->toBe('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa');
})->group('RF-QR-01', 'RF-QR-02');

it('firma sobre prefijo, key_id y token, y no sobre la firma', function (): void {
    // La firma cubre el key_id (§5.1): si solo cubriera el token, durante un
    // solape de claves se podria cambiar el key_id de una tarjeta y forzar la
    // verificacion contra la otra clave.
    $payload = QrPayload::parse('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa');

    expect($payload->signingInput())->toBe('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w')
        ->and(QrPayload::signingInputFor('a3', '7QK2mXpR9vLdN4tZbYcF1w'))->toBe($payload->signingInput());
})->group('RF-QR-02');

it('rechaza todo lo que no tenga la forma del §5.1', function (string $raw): void {
    expect(static fn () => QrPayload::parse($raw))->toThrow(MalformedQrPayload::class);
})->with([
    'vacio' => [''],
    'sin prefijo' => ['XX1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'],
    'prefijo de otra version' => ['FH2.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'],
    'prefijo en minusculas' => ['fh1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'],
    'tres componentes' => ['FH1.a3.7QK2mXpR9vLdN4tZbYcF1w'],
    'cinco componentes' => ['FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa.x'],
    'key_id de un caracter' => ['FH1.a.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'],
    'key_id de tres caracteres' => ['FH1.a3b.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'],
    'key_id con simbolo' => ['FH1.a-.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'],
    'token corto' => ['FH1.a3.7QK2mXpR9vLdN4tZbYcF1.k9Xm2pQrT5vN8wLa'],
    'token largo' => ['FH1.a3.7QK2mXpR9vLdN4tZbYcF1ww.k9Xm2pQrT5vN8wLa'],
    'token fuera del alfabeto base64url' => ['FH1.a3.7QK2mXpR9vLdN4tZbYcF1+.k9Xm2pQrT5vN8wLa'],
    'firma corta' => ['FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wL'],
    'firma larga' => ['FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLaa'],
])->group('RF-QR-02', 'RS-03');

it('no trabaja sobre un payload desmesurado', function (): void {
    // Lo que llega es lo que haya leido una camara. Sin tope, un QR de 3 kB
    // haria trabajar a explode y a preg_match en el camino mas caliente del
    // producto, y repetido es una denegacion de servicio barata.
    $enorme = 'FH1.a3.'.str_repeat('A', QrPayload::MAX_LENGTH).'.k9Xm2pQrT5vN8wLa';

    expect(static fn () => QrPayload::parse($enorme))->toThrow(MalformedQrPayload::class);
})->group('RS-03');

it('devuelve null en lugar de lanzar cuando quien lee es el verificador', function (): void {
    // El camino de fichaje no puede usar excepciones como control de flujo: esa
    // rama costaria distinto que el camino normal y RS-03 exige que no.
    expect(QrPayload::tryParse('esto no es un payload'))->toBeNull()
        ->and(QrPayload::tryParse('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'))
        ->toBeInstanceOf(QrPayload::class);
})->group('RS-03');

it('mantiene las longitudes que hacen que el QR quepa en una version 3', function (): void {
    // 46 caracteres: el margen que permite corregir errores a nivel Q y que una
    // tarjeta sobreviva una temporada en una cocina (§5.1).
    expect(QrPayload::PREFIX)->toBe('FH1')
        ->and(QrPayload::KEY_ID_LENGTH)->toBe(2)
        ->and(QrPayload::TOKEN_LENGTH)->toBe(22)
        ->and(QrPayload::SIGNATURE_LENGTH)->toBe(16)
        ->and(mb_strlen('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'))->toBe(46);
})->group('RF-QR-01');
