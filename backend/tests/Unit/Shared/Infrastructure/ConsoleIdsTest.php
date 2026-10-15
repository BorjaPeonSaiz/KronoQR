<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Console\ConsoleIds;

/*
 * El id de una opcion de consola que firma un asiento (`--responsible`,
 * `--acknowledge`). `(int)` se tragaria `12abc` como 12 y firmaria con la cuenta
 * equivocada: un id es un entero positivo escrito tal cual, o no es un id.
 */

it('lee un entero positivo escrito tal cual, con espacios alrededor', function (string $texto, int $id): void {
    expect(ConsoleIds::positive($texto))->toBe($id);
})->with([
    'simple' => ['7', 7],
    'con espacios' => ['  42 ', 42],
    'con ceros delante' => ['007', 7],
    'dieciocho cifras' => ['999999999999999999', 999999999999999999],
])->group('RL-04', 'RN-23');

it('no adivina un id de lo que no lo es', function (string $texto): void {
    expect(ConsoleIds::positive($texto))->toBeNull();
})->with([
    'vacio' => [''],
    'cero' => ['0'],
    'ceros' => ['000'],
    'negativo' => ['-3'],
    'con letras' => ['12abc'],
    'decimal' => ['1.5'],
    'diecinueve cifras' => ['1000000000000000000'],
])->group('RL-04', 'RN-23');
