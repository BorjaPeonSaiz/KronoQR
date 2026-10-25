<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\Exception\InvalidEmployeeCode;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;

/*
 * El codigo de empleado es opaco (doc 01 §5.5, RF-ID-06).
 *
 * No es una preferencia estetica: este codigo va impreso en la tarjeta y es la
 * mitad publica de la credencial del portal. Uno secuencial revelaria cuanta
 * gente trabaja en el hotel y permitiria adivinar codigos ajenos contando.
 */

it('normaliza a mayusculas el codigo que lee', function (): void {
    // La columna es `citext`: `e7qk2mxpr` y `E7QK2MXPR` son el mismo codigo, y
    // el objeto de valor tiene que decir lo mismo que la base de datos.
    expect(EmployeeCode::fromString('e7qk2mxpr')->value)->toBe('E7QK2MXPR');
})->group('RF-GP-01');

it('acepta los codigos que ya existen en una instalacion', function (string $existing): void {
    // La lectura es mas permisiva que la generacion a proposito: hay codigos
    // creados por versiones anteriores o importados de otro sistema, y
    // rechazarlos dejaria a alguien sin poder fichar por un cambio de formato.
    expect(EmployeeCode::fromString($existing)->value)->toBe($existing);
})->with([
    'hexadecimal de la semilla' => 'E0A1B2C3D',
    'importado corto' => 'AB12',
])->group('RF-GP-01');

it('rechaza un codigo vacio o con caracteres que no puede llevar', function (string $invalid): void {
    expect(fn () => EmployeeCode::fromString($invalid))->toThrow(InvalidEmployeeCode::class);
})->with([
    'vacio' => '',
    'con espacio' => 'E7QK 2MXPR',
    'con guion' => 'RECEPCION-01',
    'demasiado largo' => 'E123456789012345678901234567890123',
])->group('RF-GP-01');

it('compone el codigo nuevo con las posiciones que le dan, sin azar propio (H8)', function (): void {
    // El azar lo pone el adaptador del puerto: con las posiciones fijadas, el
    // codigo es el mismo siempre, caracter a caracter.
    expect(EmployeeCode::fromRandomPicks([0, 1, 2, 3, 4, 5, 6, 7, 8])->value)->toBe('EABCDEFGHJ')
        ->and(EmployeeCode::fromRandomPicks([30, 29, 28, 27, 26, 25, 24, 23, 22])->value)->toBe('E98765432Z');
})->group('RF-GP-01', 'RF-ID-06');

it('el alfabeto del codigo nuevo no tiene los caracteres que se confunden al leer una tarjeta', function (): void {
    // Alguien teclea este codigo en el portal desde una tarjeta desgastada
    // (RF-ID-06). Sin 0/O ni 1/I/L no hay forma de teclear mal un codigo bien
    // leido. Se recorren todas las posiciones del alfabeto.
    $alphabet = '';

    for ($pick = 0; $pick < EmployeeCode::ALPHABET_SIZE; $pick++) {
        $alphabet .= mb_substr(EmployeeCode::fromRandomPicks(array_fill(0, EmployeeCode::RANDOM_LENGTH, $pick))->value, 1, 1);
    }

    expect(mb_strlen($alphabet))->toBe(31)
        ->and(count(array_unique(mb_str_split($alphabet))))->toBe(31)
        ->and($alphabet)->not->toMatch('/[01OIL]/');
})->group('RF-ID-06');

it('rechaza unas posiciones que no forman un codigo nuevo', function (array $picks): void {
    /** @var list<int> $picks */
    expect(static fn (): EmployeeCode => EmployeeCode::fromRandomPicks($picks))->toThrow(InvalidArgumentException::class);
})->with([
    'una de menos' => [[0, 0, 0, 0, 0, 0, 0, 0]],
    'una de mas' => [[0, 0, 0, 0, 0, 0, 0, 0, 0, 0]],
    'ninguna' => [[]],
    'negativa' => [[0, 0, 0, 0, 0, 0, 0, 0, -1]],
    'fuera del alfabeto' => [[0, 0, 0, 0, 0, 0, 0, 0, 31]],
])->group('RF-GP-01');
