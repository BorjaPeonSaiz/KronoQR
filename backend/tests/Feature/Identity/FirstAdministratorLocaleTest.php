<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Product\InstallationLocale;

/*
 * El idioma del primer administrador es uno de los idiomas ACTIVOS de la
 * instalacion (RF-KI-05, regla dura 13; bloque 26 de la 2.2.1): fuera de
 * `LOCALE_AVAILABLE` es `422`, y sin el rige `LOCALE_DEFAULT`, no un `es`
 * escrito en el codigo. Cada respuesta se valida contra el contrato.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function idiomaPrimerAdministrador(array $overrides = []): array
{
    return [
        'name' => 'Direccion del hotel',
        'email' => 'direccion@hotel.example',
        'password' => 'Una-Contrasena-Larga-1!',
        'device_name' => 'Panel de gestion',
        ...$overrides,
    ];
}

it('sin idioma, el primer administrador toma el de la instalacion', function (string $default, string ...$available): void {
    InstallationLocale::set($default, array_values($available));

    Api::guest()->post('/api/v1/setup/administrator', idiomaPrimerAdministrador())
        ->assertValidRequest()
        ->assertValidResponse(201);

    expect(DB::table('users')->value('locale'))->toBe($default);
})->with([
    'de serie, solo español' => ['es', 'es'],
    'una instalacion en ingles' => ['en', 'en', 'es'],
])->group('RF-KI-05', 'RF-PD-03');

it('rechaza con 422 un idioma que la instalacion no tiene activo, y no crea la cuenta', function (): void {
    Api::guest()->post('/api/v1/setup/administrator', idiomaPrimerAdministrador(['locale' => 'en']))
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertJsonValidationErrors(['locale']);

    expect(DB::table('users')->count())->toBe(0);
})->group('RF-KI-05', 'RF-PD-03');

it('acepta el ingles en cuanto la instalacion lo activa', function (): void {
    InstallationLocale::set('es', ['es', 'en']);

    Api::guest()->post('/api/v1/setup/administrator', idiomaPrimerAdministrador(['locale' => 'en']))
        ->assertValidRequest()
        ->assertValidResponse(201);

    expect(DB::table('users')->value('locale'))->toBe('en');
})->group('RF-KI-05', 'RF-PD-03');
