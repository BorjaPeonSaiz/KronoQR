<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * El servidor PostgreSQL contra el que corre la suite tiene el JIT apagado
 * (bloque 22 de la 2.2.1; RNF-P-02, RNF-P-05).
 *
 * `tests/Architecture/PostgresJitTest.php` comprueba que todo arranque de
 * PostgreSQL del repositorio lleva `-c jit=off`; esta, que el servidor de verdad
 * lo aplica, para la aplicacion y para el rol de migracion. En local falla hasta
 * recrear el contenedor `postgres` de desarrollo con el compose nuevo.
 */

it('el servidor tiene jit=off para la aplicacion y para el rol de migracion', function (string $conexion): void {
    /** @var object{jit: string}|null $fila */
    $fila = DB::connection($conexion === 'migracion' ? config()->string('database.migrations.connection') : null)
        ->selectOne('SHOW jit');

    expect($fila?->jit)->toBe('off');
})->with(['aplicacion', 'migracion'])->group('RNF-P-02');
