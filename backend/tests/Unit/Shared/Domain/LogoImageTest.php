<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\LogoFormat;
use App\Modules\Shared\Domain\ValueObject\LogoImage;

/*
 * El logotipo de la instalacion como objeto de valor (RF-PD-08, tarea 5.8).
 *
 * SIN BASE DE DATOS Y SIN DISCO. Lo que se comprueba aqui son las tres
 * operaciones puras de las que dependen tres cosas muy distintas: el tipo con el
 * que viaja por HTTP, la URI de datos que se incrusta en un PDF y la huella que
 * hace cacheable la URL para siempre.
 */

it('lleva el tipo de contenido de su formato, y no el de su extension', function (): void {
    // El fichero puede llamarse como quiera: quien decide es el contenido, y el
    // contenido ya esta decidido cuando existe este objeto.
    expect((new LogoImage(LogoFormat::PNG, 'x'))->mimeType())->toBe('image/png')
        ->and((new LogoImage(LogoFormat::SVG, '<svg/>'))->mimeType())->toBe('image/svg+xml');
})->group('RF-PD-08');

it('se incrusta en base64 con su tipo, para un PDF sin salida a internet', function (): void {
    // ADR-016: el PDF lo dibuja un Chromium sin red. Una URL remota daria
    // documentos sin logotipo el dia que la red del hotel tenga un mal rato.
    $uri = (new LogoImage(LogoFormat::PNG, 'bytes'))->dataUri();

    expect($uri)->toBe('data:image/png;base64,'.base64_encode('bytes'))
        ->and($uri)->not->toContain('http');
})->group('RF-PD-08');

it('huella el contenido, de modo que dos logotipos distintos nunca comparten URL', function (): void {
    $uno = new LogoImage(LogoFormat::PNG, 'primero');
    $otro = new LogoImage(LogoFormat::PNG, 'segundo');

    expect($uno->sha256())->toBe(hash('sha256', 'primero'))
        ->and($uno->cacheTag())->not->toBe($otro->cacheTag());
})->group('RF-PD-08');

it('publica una huella corta de doce digitos hexadecimales, que es lo que exige el contrato', function (): void {
    // El parametro `v` del contrato declara `^[0-9a-f]{12}$`. Si esto creciera,
    // la URL dejaria de validar contra `openapi.yaml`.
    $tag = (new LogoImage(LogoFormat::SVG, '<svg/>'))->cacheTag();

    expect($tag)->toMatch('/^[0-9a-f]{12}$/')
        ->and($tag)->toBe(substr(hash('sha256', '<svg/>'), 0, 12));
})->group('RF-PD-08');

it('el mismo contenido da la misma huella, para que la cache no se invalide sola', function (): void {
    // Es lo que sostiene `Cache-Control: immutable`: reiniciar el servidor, o
    // volver a copiar el mismo fichero, no puede cambiar la URL.
    expect((new LogoImage(LogoFormat::PNG, 'igual'))->cacheTag())
        ->toBe((new LogoImage(LogoFormat::PNG, 'igual'))->cacheTag());
})->group('RF-PD-08');

it('rechaza un fichero de cero bytes en vez de tratarlo como «sin logotipo»', function (): void {
    // Un fichero vacio es un logotipo roto —una copia que fallo a medias—, y
    // confundirlo con «no hay logotipo» esconderia la causa detras de una
    // cabecera que sale en blanco sin que nadie sepa por que.
    expect(fn (): LogoImage => new LogoImage(LogoFormat::PNG, ''))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-PD-08');
