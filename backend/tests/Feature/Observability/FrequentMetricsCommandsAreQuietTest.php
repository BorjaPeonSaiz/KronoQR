<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Support\Database\RefreshDatabase;

/*
 * LAS METRICAS DE CADA MINUTO Y DE CADA CINCO NO ESCRIBEN NADA SI VAN BIEN
 * (V4-BE-1, RNF-D-02).
 *
 * Desde que su salida llega al log del planificador por `ScheduledTaskOutput`,
 * lo que escriben es lo que se lee en el contenedor `scheduler` y en Loki. Una
 * linea por pasada seria una por minuto sin decir nada que no digan ya las
 * series; un fallo, en cambio, sale por la salida de error del comando y llega.
 * Con `-v` si hablan, para quien las lanza a mano.
 *
 * Sin centro de trabajo, que es el estado de la base recien refrescada: el
 * aviso de «todavia no hay centro» tambien es solo con `-v`, porque antes de la
 * puesta en marcha saldria cada minuto.
 */

uses(RefreshDatabase::class);

it('no escriben nada sin -v y si con -v', function (string $command): void {
    expect(Artisan::call($command))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('')
        ->and(Artisan::call($command, ['-v' => true]))->toBe(0)
        ->and(trim(Artisan::output()))->not->toBe('');
})->with([
    'presencia' => ['reporting:presence-metrics'],
    'incidencias' => ['compliance:incident-metrics'],
])->group('RNF-D-02', 'RL-08');
