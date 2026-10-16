<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\InstallationLocale;

/*
 * `identity:create-user` aplica al idioma el mismo criterio que el alta desde el
 * panel (RF-KI-05, RF-ID-10; bloque 26 de la 2.2.1): sin `--locale`, el de la
 * instalacion (`LOCALE_DEFAULT`), y uno que la instalacion no tiene activo se
 * rechaza sin crear nada.
 */

uses(RefreshDatabase::class);

it('sin --locale, la cuenta toma el idioma de la instalacion', function (string $default, string ...$available): void {
    InstallationLocale::set($default, array_values($available));

    [$exit] = Commands::run('identity:create-user --name="Direccion RRHH" --email=consola@hotel.example --role=rrhh');

    expect($exit)->toBe(0)
        ->and(DB::table('users')->where('email', 'consola@hotel.example')->value('locale'))->toBe($default);
})->with([
    'de serie, solo español' => ['es', 'es'],
    'una instalacion en ingles' => ['en', 'en', 'es'],
])->group('RF-KI-05', 'RF-ID-10');

it('rechaza un idioma que la instalacion no tiene activo, y no crea la cuenta', function (): void {
    [$exit] = Commands::run(
        'identity:create-user --name="Direccion RRHH" --email=consola@hotel.example --role=rrhh --locale=en'
    );

    expect($exit)->toBe(1)
        ->and(DB::table('users')->where('email', 'consola@hotel.example')->exists())->toBeFalse();
})->group('RF-KI-05', 'RF-ID-10');

it('acepta el ingles en cuanto la instalacion lo activa', function (): void {
    InstallationLocale::set('es', ['es', 'en']);

    [$exit] = Commands::run(
        'identity:create-user --name="Direccion RRHH" --email=consola@hotel.example --role=rrhh --locale=en'
    );

    expect($exit)->toBe(0)
        ->and(DB::table('users')->where('email', 'consola@hotel.example')->value('locale'))->toBe('en');
})->group('RF-KI-05', 'RF-ID-10');
