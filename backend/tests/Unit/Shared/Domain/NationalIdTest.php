<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\NationalId;

/*
 * La forma canonica del documento de identidad (RL-08).
 *
 * Un hash no admite comparaciones aproximadas: si dos sitios del producto
 * normalizaran distinto, `digest('12345678z')` y `digest('12345678Z')` serian
 * dos personas. Estas pruebas fijan la UNICA normalizacion, la que usan el alta,
 * la importacion masiva (RF-GP-05) y la busqueda de la persona de la
 * exportacion legal (RF-IN-05).
 */

it('escribe igual el mismo documento tecleado de formas distintas', function (string $tecleado): void {
    $documento = NationalId::fromInput($tecleado);

    expect($documento)->not->toBeNull()
        ->and($documento?->value)->toBe('12345678Z');
})->with([
    'canonico' => ['12345678Z'],
    'minuscula' => ['12345678z'],
    'con puntos y guion' => ['12.345.678-z'],
    'con espacios alrededor y dentro' => ['  12345678 Z  '],
    'con espacio no separable' => ["12345678\u{00A0}Z"],
])->group('RL-08', 'RF-IN-05', 'RF-GP-05');

it('no es un documento si al normalizar no queda nada', function (string $tecleado): void {
    expect(NationalId::fromInput($tecleado))->toBeNull()
        ->and(NationalId::normalise($tecleado))->toBeNull();
})->with([
    'vacio' => [''],
    'espacios' => ['   '],
    'solo separadores' => ['.- -.'],
])->group('RL-08');

it('deja pasar el nulo sin inventarse un documento', function (): void {
    expect(NationalId::normalise(null))->toBeNull();
})->group('RL-08');

it('es idempotente: normalizar dos veces no cambia nada', function (): void {
    $una = NationalId::normalise('x-1234.567 l');

    expect($una)->toBe('X1234567L')
        ->and(NationalId::normalise($una))->toBe($una);
})->group('RL-08', 'RF-GP-05');

it('no valida la forma de un DNI: un pasaporte tambien es un documento', function (): void {
    // ADR-017: el producto se vende fuera de España y la plantilla de temporada
    // trae NIE, pasaportes y documentos de otros paises.
    expect(NationalId::fromInput('ab 123 456')?->value)->toBe('AB123456');
})->group('RL-08');

it('dice si es mas corto que el minimo una vez normalizado', function (): void {
    expect(NationalId::MIN_LENGTH)->toBe(4)
        ->and(NationalId::fromInput('1-2-3')?->isTooShort())->toBeTrue()
        ->and(NationalId::fromInput('1.2.3.4')?->isTooShort())->toBeFalse();
})->group('RL-08', 'RF-IN-05');

it('compara por la forma canonica', function (): void {
    $a = NationalId::fromInput('12.345.678-z');
    $b = NationalId::fromInput('12345678Z');
    $c = NationalId::fromInput('12345678Y');

    \assert($a instanceof NationalId && $b instanceof NationalId && $c instanceof NationalId);

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
})->group('RL-08');

it('no tiene representacion en texto ni en JSON', function (): void {
    // RL-08 y regla dura 21: el documento en claro no debe acabar en un log por
    // un `(string)` o un `json_encode` descuidado.
    $interfaces = class_implements(NationalId::class);

    expect($interfaces)->not->toHaveKey(Stringable::class)
        ->and($interfaces)->not->toHaveKey(JsonSerializable::class);
})->group('RL-08');
