<?php

declare(strict_types=1);

use App\Support\Scheduling\SchedulerHeartbeat;

/*
 * El latido del planificador (bloque 9 de la 2.3.0, O1). Lo lee el
 * `healthcheck` del servicio `scheduler` por la edad del fichero.
 */

it('crea el fichero de latido y lo renueva en cada llamada', function (): void {
    $ruta = sys_get_temp_dir().'/kq-heartbeat-'.bin2hex(random_bytes(6));

    try {
        expect((new SchedulerHeartbeat)->beat($ruta))->toBeTrue();
        expect(is_file($ruta))->toBeTrue();

        // Se envejece y se renueva: la edad (mtime) es lo que mira la sonda.
        touch($ruta, time() - 600);
        clearstatcache();
        $envejecido = filemtime($ruta);

        expect((new SchedulerHeartbeat)->beat($ruta))->toBeTrue();
        clearstatcache();

        expect(filemtime($ruta))->toBeGreaterThan((int) $envejecido);
    } finally {
        @unlink($ruta);
    }
})->group('RNF-D-01');

it('no lanza si no puede escribir el latido: lo dira el healthcheck, no una excepcion', function (): void {
    expect((new SchedulerHeartbeat)->beat('/ruta/que/no/existe/latido'))->toBeFalse();
})->group('RNF-D-01');

it('usa la ruta que el healthcheck y el entrypoint conocen', function (): void {
    expect(SchedulerHeartbeat::PATH)->toBe('/tmp/kronoqr-scheduler-heartbeat');
})->group('RNF-D-01');
