<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use Tests\Support\Http\Api;

/*
 * Modo mantenimiento durante una actualizacion (RF-PD-10, tarea 5.7).
 *
 * update.sh pone la aplicacion en mantenimiento (`php artisan down`) antes de
 * la copia previa y hasta que la version nueva esta verificada. Lo que se
 * afirma aqui es el CONTRATO de esa ventana, que es lo que ven las tres
 * aplicaciones cliente y el propio actualizador:
 *
 *   1. Las dos sondas siguen respondiendo. `/health` es lo que Docker,
 *      Prometheus y el actualizador miran para saber que la version anterior
 *      sigue viva durante la ventana; un 503 ahi reiniciaria un contenedor sano.
 *   2. Todo lo demas responde 503 como `application/problem+json` con un tipo
 *      propio y `Retry-After`: el quiosco conserva el fichaje en su cola (regla
 *      dura 19) y el panel sabe que tiene que esperar, no avisar a nadie.
 *
 * Se activa el modo con el mismo mecanismo que usa `artisan down` (el fichero
 * `storage/framework/down`) y se desactiva SIEMPRE al terminar, pase lo que
 * pase: dejarlo puesto convertiria el resto de la suite en un 503 sin
 * relacion aparente con esta prueba.
 */

it('deja pasar las sondas y cierra el resto con un 503 problem+json durante el mantenimiento', function (): void {
    $mode = app()->maintenanceMode();
    $mode->activate([
        'except' => [],
        'redirect' => null,
        'retry' => 60,
        'refresh' => null,
        'secret' => null,
        'status' => 503,
    ]);

    try {
        // La sonda de vida responde como si nada: sigue diciendo la version.
        Api::guest()->get('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        // La de disponibilidad puede decir «no listo» por sus dependencias, pero
        // nunca «en mantenimiento»: es la que distingue una ventana planificada
        // de una caida.
        $ready = Api::guest()->get('/api/v1/ready');

        expect($ready->json('type'))->not->toBe(ProblemDetails::TYPE_MAINTENANCE);

        // Un fichaje del quiosco: 503, tipo propio, Retry-After y problem+json.
        $scan = Api::guest()->post('/api/v1/scan', []);

        $scan->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', ProblemDetails::TYPE_MAINTENANCE)
            ->assertJsonPath('status', 503);

        // Y una lectura del panel, exactamente igual: la ventana no distingue
        // canales.
        Api::guest()->get('/api/v1/auth/me')
            ->assertStatus(503)
            ->assertJsonPath('type', ProblemDetails::TYPE_MAINTENANCE);
    } finally {
        $mode->deactivate();
    }

    // Retirado el mantenimiento, el 503 desaparece en la misma peticion.
    expect(Api::guest()->post('/api/v1/scan', [])->status())->not->toBe(503);
})->group('RF-PD-10', 'RQ-06');
