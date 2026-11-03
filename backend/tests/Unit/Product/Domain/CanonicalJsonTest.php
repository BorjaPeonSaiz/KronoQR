<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Support\CanonicalJson;

/*
 * LA FORMA CANONICA DEL PAQUETE DE DIAGNOSTICO (RF-PD-09, RL-19): la de su
 * huella y, desde la enmienda de ADR-020 del 01-11-2026, la de los datos
 * asociados del sobre. Quien la recalcula lo hace sobre el fichero releido, y
 * una diferencia de un byte hace que la huella no coincida o que el sobre no
 * abra. Aqui, sola: los mapas ordenados en profundidad, las listas no, y las
 * barras, el unicode y los decimales sin tocar.
 */

it('ordena las claves de los mapas en profundidad y deja las listas en su orden', function (): void {
    $documento = [
        'z' => 1,
        'a' => ['y' => true, 'b' => null],
        'lista' => ['tercero', 'primero', ['k' => 2, 'c' => 1]],
    ];

    expect(CanonicalJson::encode($documento))
        ->toBe('{"a":{"b":null,"y":true},"lista":["tercero","primero",{"c":1,"k":2}],"z":1}');
})->group('RF-PD-09', 'RL-19');

it('no escapa barras ni unicode y conserva el cero decimal', function (): void {
    expect(CanonicalJson::encode(['ruta' => 'a/b', 'nombre' => 'Peña', 'cifra' => 1.0]))
        ->toBe('{"cifra":1.0,"nombre":"Peña","ruta":"a/b"}');
})->group('RF-PD-09', 'RL-19');

it('el mismo documento con otro orden de claves da la misma forma y la misma huella', function (): void {
    $uno = ['manifest' => ['sha256' => 'x', 'anonymized' => false], 'contents' => ['status' => 'omitted']];
    $otro = ['contents' => ['status' => 'omitted'], 'manifest' => ['anonymized' => false, 'sha256' => 'x']];

    expect(CanonicalJson::encode($uno))->toBe(CanonicalJson::encode($otro))
        ->and(CanonicalJson::digest($uno))->toBe(hash('sha256', CanonicalJson::encode($uno)))
        ->and(CanonicalJson::digest($uno))->toBe(CanonicalJson::digest($otro))
        ->and(CanonicalJson::digest([...$uno, 'contents' => ['status' => 'included']]))->not->toBe(CanonicalJson::digest($uno));
})->group('RF-PD-09', 'RL-19');

it('lanza con un texto que no es UTF-8 en vez de devolver una forma vacia', function (): void {
    expect(static fn (): string => CanonicalJson::encode(['roto' => "\xB1\x31"]))->toThrow(JsonException::class);
})->group('RF-PD-09');
