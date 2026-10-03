<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Logging\RedactingLogManager;
use Illuminate\Support\Facades\Log;

/*
 * EL CANAL `emergency` TAMBIEN SANEA (ADR-048, H8; regla dura 21, RF-PD-15).
 *
 * Cuando el canal configurado no se puede construir, Laravel escribe por un
 * logger de emergencia que monta a mano, sin leer `tap` ni `processors`: un
 * `tap` en `config/logging.php` no tendria efecto. `RedactingLogManager`
 * sobrescribe ese montaje. Aqui se rompe el canal a proposito y se lee el
 * fichero que escribe.
 */

it('arranca con RedactingLogManager tanto en el contenedor como en la fachada', function (): void {
    expect(app('log'))->toBeInstanceOf(RedactingLogManager::class)
        ->and(Log::getFacadeRoot())->toBeInstanceOf(RedactingLogManager::class);
})->group('RF-PD-15');

it('sanea la linea que escribe el canal emergency cuando el configurado no se puede construir', function (): void {
    $path = sys_get_temp_dir().'/kronoqr-emergency-'.bin2hex(random_bytes(6)).'.log';

    config([
        'logging.channels.emergency.path' => $path,
        // Un controlador que no existe: `LogManager::get()` lo captura y cae al
        // logger de emergencia, que es exactamente el camino que se prueba.
        'logging.channels.roto' => ['driver' => 'inexistente'],
    ]);

    try {
        Log::channel('roto')->error('No se pudo fichar a Rosa Ficticiana (rosa@hotel-ejemplo.es, 45678912K)');

        $escrito = (string) file_get_contents($path);

        expect($escrito)->toContain('No se pudo fichar a …')
            ->and($escrito)->not->toContain('Rosa')
            ->and($escrito)->not->toContain('Ficticiana')
            ->and($escrito)->not->toContain('rosa@hotel-ejemplo.es')
            ->and($escrito)->not->toContain('45678912K');
    } finally {
        Log::forgetChannel('roto');

        if (is_file($path)) {
            unlink($path);
        }
    }
})->group('RF-PD-15', 'RL-19');
