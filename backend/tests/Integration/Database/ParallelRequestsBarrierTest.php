<?php

declare(strict_types=1);

use Tests\Support\Concurrency\ParallelRequests;

/*
 * La barrera de arranque del arnes de concurrencia (Q8 de la verificacion de
 * la 2.1.0, RQ-03).
 *
 * Las pruebas de idempotencia y de cambio de turno solo demuestran algo si sus
 * peticiones coinciden en el tiempo. Sin barrera, el primer hijo empieza su
 * tarea mientras el padre sigue bifurcando a los demas. Con barrera, ningun
 * hijo empieza antes de que el padre los haya bifurcado a todos.
 *
 * `hrtime()` es el reloj monotono del sistema, el mismo para todos los procesos
 * de la maquina: se puede comparar el de un hijo con el del padre. Esta en la
 * suite de integracion porque el arnes espera a que las sesiones de los hijos
 * desaparezcan de PostgreSQL.
 */

it('no deja empezar a ningun hijo hasta que el padre los ha bifurcado a todos', function (): void {
    $soltados = 0;

    $comienzos = ParallelRequests::runTasks(
        10,
        static fn (int $indice): int => hrtime(true),
        static function () use (&$soltados): void {
            $soltados = hrtime(true);
        },
    );

    expect($comienzos)->toHaveCount(10)
        ->and($comienzos)->each->toBeGreaterThan($soltados);
})->group('RQ-03');
