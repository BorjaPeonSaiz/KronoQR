<?php

declare(strict_types=1);

use App\Support\Environment\ProductionSafetyGuard;
use App\Support\Environment\UnsafeProductionConfiguration;

/*
 * La guarda que impide arrancar en produccion con las trazas encendidas
 * (RS-08, tarea 5.4; cierra el hueco «APP_DEBUG=true en .env.example» de
 * docs/07 §6).
 *
 * **Por que hay una prueba y no basta con leer el codigo.** Esta guarda tiene
 * dos fallos posibles y los dos son caros en direcciones opuestas: si no salta
 * cuando debe, una instalacion de un hotel sirve sus claves dentro de una traza
 * de excepcion; si salta cuando no debe, deja a esa misma instalacion sin poder
 * fichar. Las dos direcciones estan cubiertas aqui, y el lado «no salta» tiene
 * mas casos que el otro a proposito.
 *
 * Suite unitaria: PHP puro, sin framework y sin base de datos.
 */

it('deja arrancar una instalacion de produccion con las trazas apagadas', function (): void {
    ProductionSafetyGuard::assert('production', false);

    expect(true)->toBeTrue();
})->group('RS-08', 'RF-PD-02');

it('se niega a arrancar en produccion con las trazas encendidas', function (): void {
    expect(fn () => ProductionSafetyGuard::assert('production', true))
        ->toThrow(UnsafeProductionConfiguration::class);
})->group('RS-08', 'RF-PD-02');

it('dice que hacer, no solo que pasa, y en los dos idiomas', function (): void {
    // El destinatario es el IT del hotel leyendo el log de un contenedor que no
    // levanta: no hay negociacion de idioma en el arranque y el mensaje es lo
    // unico que va a ver.
    try {
        ProductionSafetyGuard::assert('production', true);
        expect(false)->toBeTrue('la guarda tenia que haber lanzado');
    } catch (UnsafeProductionConfiguration $exception) {
        $message = $exception->getMessage();

        expect($message)->toContain('APP_DEBUG=false')
            ->and($message)->toContain('Que hacer')
            ->and($message)->toContain('What to do')
            ->and($message)->toContain('docker compose')
            // Que no se ha perdido nada es la primera pregunta de quien ve un
            // arranque fallido en un sistema con valor legal.
            ->and($message)->toContain('El registro horario NO se ha perdido');
    }
})->group('RS-08', 'RF-PD-02');

it('no molesta fuera de produccion, ni siquiera con las trazas encendidas', function (): void {
    // Desarrollo, pruebas y puesta en escena necesitan la traza. La guarda es
    // estrecha a proposito: solo la combinacion que nadie elige queriendo.
    foreach (['local', 'testing', 'staging', 'ci'] as $environment) {
        ProductionSafetyGuard::assert($environment, true);
    }

    expect(true)->toBeTrue();
})->group('RS-08', 'RF-PD-02');

it('no confunde un nombre de entorno que empieza por production', function (): void {
    // `production-mirror` no es produccion. Una comparacion por prefijo dejaria
    // sin arrancar entornos de ensayo que si necesitan la traza.
    ProductionSafetyGuard::assert('production-mirror', true);

    expect(true)->toBeTrue();
})->group('RS-08', 'RF-PD-02');
