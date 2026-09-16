<?php

declare(strict_types=1);

use App\Modules\Kiosk\Domain\ValueObject\ServiceCodeFingerprint;

/*
 * La huella del codigo de servicio con el que se abre la pantalla de
 * diagnostico de la tablet (**RF-KI-08**, tarea 3.3, decision 6).
 *
 * ## Por que las huellas van escritas en hexadecimal y no calculadas
 *
 * Porque una prueba que calculara `hash('sha256', $uuid.':'.$codigo)` para
 * compararlo con lo que devuelve el objeto seria la implementacion copiada: si
 * alguien cambiara el separador o el orden, los dos lados cambiarian a la vez y
 * la prueba seguiria verde. La tablet compara **contra la huella que guardo en
 * `localStorage` en un latido anterior**, asi que esta cadena es contrato: si
 * cambia, todas las tablets de todas las instalaciones se quedan sin poder
 * abrir su pantalla de diagnostico hasta el latido siguiente.
 *
 * ## El UUID va dentro y es una sal, no decoracion
 *
 * Sin el, todas las tablets de la instalacion guardarian la misma huella y una
 * tabla precalculada de codigos de ocho a doce cifras valdria para todas a la
 * vez.
 */

it('no devuelve huella cuando la instalacion no tiene codigo de servicio', function (?string $code): void {
    // `KIOSK_SERVICE_CODE` vale la cadena vacia de serie, y una instalacion que
    // no ha decidido proteger su pantalla de diagnostico no puede quedarse sin
    // ella (regla dura 19): sin huella, la pantalla se abre sin codigo.
    expect(ServiceCodeFingerprint::of('0199a1f0-9c3d-7a21-9c1e-5f2b7d4e8a01', $code))->toBeNull();
})->with([
    'sin ajuste escrito' => [null],
    'ajuste vaciado desde el panel' => [''],
])->group('RF-KI-08');

it('devuelve el sha256 del uuid del quiosco y el codigo', function (): void {
    expect(ServiceCodeFingerprint::of('0199a1f0-9c3d-7a21-9c1e-5f2b7d4e8a01', '24681357'))
        ->toBe('c4dc8befef2c863d1039695fcd42cd5eb3b9b0323beeef6795aca9c53afd35c6');
})->group('RF-KI-08');

it('toma el uuid tal y como se lo dan, sin normalizar mayusculas', function (): void {
    // Los UUID del producto son minusculas siempre (`Str::uuid7()` y la columna
    // `uuid` de PostgreSQL), y aqui no se normaliza nada: normalizar seria una
    // regla mas que la tablet tendria que repetir en TypeScript para que las dos
    // huellas coincidieran. Esta prueba fija que la cadena entra literal, de modo
    // que quien algun dia pase un UUID en mayusculas vea que rompe.
    expect(ServiceCodeFingerprint::of('0199A1F0-9C3D-7A21-9C1E-5F2B7D4E8A01', '24681357'))
        ->toBe('ed84e8b5bbbef054a6317918b46f30ea2f45e491211693e38620fcf39487a542');
})->group('RF-KI-08');

it('da huellas distintas al mismo codigo en dos quioscos', function (): void {
    // La sal por dispositivo: con una huella comun, quien forzara la de una
    // tablet abriria la pantalla de todas las de la instalacion.
    expect(ServiceCodeFingerprint::of('0199a1f0-9c3d-7a21-9c1e-5f2b7d4e8a02', '24681357'))
        ->toBe('1e97f8e215ac46e49c150aac89cb3faa9a3ed57c4ea140829d21132558eb510d');
})->group('RF-KI-08');
