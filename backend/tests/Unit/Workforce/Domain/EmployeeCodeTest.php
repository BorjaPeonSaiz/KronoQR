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

it('genera codigos distintos en cada llamada', function (): void {
    $codes = [];

    for ($i = 0; $i < 200; $i++) {
        $codes[] = EmployeeCode::generate()->value;
    }

    expect(array_unique($codes))->toHaveCount(200);
})->group('RF-GP-01', 'RF-ID-06');

it('no genera codigos correlativos', function (): void {
    // La prueba del requisito: dos codigos consecutivos no se parecen. Si
    // alguien sustituyera la generacion por un contador, la distancia entre dos
    // codigos consecutivos seria minima y esto lo delataria.
    $first = EmployeeCode::generate()->value;
    $second = EmployeeCode::generate()->value;

    $shared = 0;

    for ($i = 0; $i < mb_strlen($first); $i++) {
        if (mb_substr($first, $i, 1) === mb_substr($second, $i, 1)) {
            $shared++;
        }
    }

    // Con 31 simbolos por posicion, compartir mas de la mitad de las posiciones
    // es practicamente imposible por azar.
    expect($shared)->toBeLessThan((int) (mb_strlen($first) / 2));
})->group('RF-GP-01', 'RF-ID-06');

it('evita los caracteres que se confunden al leer una tarjeta', function (): void {
    // Alguien teclea este codigo en el portal desde una tarjeta desgastada
    // (RF-ID-06). Sin 0/O ni 1/I/L no hay forma de teclear mal un codigo bien
    // leido.
    for ($i = 0; $i < 100; $i++) {
        $code = mb_substr(EmployeeCode::generate()->value, 1);

        expect($code)->not->toContain('0')
            ->and($code)->not->toContain('O')
            ->and($code)->not->toContain('1')
            ->and($code)->not->toContain('I')
            ->and($code)->not->toContain('L');
    }
})->group('RF-ID-06');

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
